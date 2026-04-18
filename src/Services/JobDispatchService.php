<?php

declare(strict_types=1);

namespace JetWP\Control\Services;

use InvalidArgumentException;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\Site;
use JetWP\Control\Runner\JobExecutor;
use JetWP\Control\Security\Secrets;
use PDO;
use RuntimeException;

final class JobDispatchService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Secrets $secrets,
        private readonly JobExecutor $executor,
        private readonly ?ActivityLogService $activityLog = null,
    ) {
    }

    public function dispatch(Job $job, string $reason = 'manual_trigger'): Job
    {
        if ($job->status !== Job::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending jobs can be triggered directly.');
        }

        return match ($job->executionStrategy) {
            Job::STRATEGY_AGENT_ONLY,
            Job::STRATEGY_AGENT_PREFERRED => $this->dispatchToAgent($job, $reason),
            Job::STRATEGY_SSH_ONLY => $this->dispatchToSsh($job, $reason),
            default => throw new RuntimeException('Unsupported execution strategy: ' . $job->executionStrategy),
        };
    }

    private function dispatchToAgent(Job $job, string $reason): Job
    {
        $claimed = Job::claimPendingByIdForAgent($this->db, $job->id, $reason);
        if (!$claimed instanceof Job) {
            throw new RuntimeException('Job could not be claimed for agent dispatch.');
        }

        $site = Site::findById($this->db, $claimed->siteId);
        if (!$site instanceof Site) {
            throw new RuntimeException('Site not found for agent dispatch.');
        }

        $payload = [
            'action' => 'run_job',
            'job' => [
                'job_id' => $claimed->id,
                'type' => $claimed->type,
                'params' => $claimed->params ?? (object) [],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Agent dispatch payload could not be encoded.');
        }

        $response = $this->postJson(
            Site::normalizeUrl($site->url) . '/wp-json/jetwp/v1/trigger',
            $this->signedHeaders($site, $body),
            $body
        );

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            Job::restorePending($this->db, $claimed->id);
            throw new RuntimeException('Agent trigger request failed: ' . trim($response['body']));
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'ok') {
            Job::restorePending($this->db, $claimed->id);
            throw new RuntimeException('Agent trigger returned an invalid response.');
        }

        $jobResult = is_array($data['data']['job_result'] ?? null) ? $data['data']['job_result'] : null;
        if (is_array($jobResult)) {
            $status = trim((string) ($jobResult['status'] ?? ''));
            $output = isset($jobResult['output']) && is_string($jobResult['output']) ? $jobResult['output'] : null;
            $errorOutput = isset($jobResult['error_output']) && is_string($jobResult['error_output']) ? $jobResult['error_output'] : null;
            $durationMs = isset($jobResult['duration_ms']) ? (int) $jobResult['duration_ms'] : 0;

            if (in_array($status, [Job::STATUS_COMPLETED, Job::STATUS_FAILED, Job::STATUS_CANCELLED], true)) {
                Job::updateResult(
                    $this->db,
                    $claimed->id,
                    $claimed->siteId,
                    $status,
                    $output,
                    $durationMs,
                    $errorOutput
                );
            }
        }

        $this->activityLog?->logJobWorkerEvent($claimed, 'agent_triggered', [
            'dispatch_reason' => $reason,
            'http_status' => $response['status_code'],
        ]);

        return Job::findById($this->db, $claimed->id) ?? $claimed;
    }

    private function dispatchToSsh(Job $job, string $reason): Job
    {
        $claimed = Job::claimPendingByIdForSsh($this->db, $job->id, $reason);
        if (!$claimed instanceof Job) {
            throw new RuntimeException('Job could not be claimed for SSH dispatch.');
        }

        $this->activityLog?->logJobWorkerEvent($claimed, 'ssh_triggered', [
            'dispatch_reason' => $reason,
        ]);

        $this->executor->executeClaimed($claimed);

        return Job::findById($this->db, $claimed->id) ?? $claimed;
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(Site $site, string $body): array
    {
        $timestamp = (string) time();
        $secret = $this->secrets->decrypt($site->hmacSecret);

        return [
            'Content-Type' => 'application/json',
            'X-JetWP-Site-Id' => $site->id,
            'X-JetWP-Timestamp' => $timestamp,
            'X-JetWP-Signature' => hash_hmac('sha256', $body . '|' . $timestamp, $secret),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status_code:int, body:string}
     */
    private function postJson(string $url, array $headers, string $body): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Failed to initialize cURL for agent dispatch.');
            }

            $formattedHeaders = [];
            foreach ($headers as $name => $value) {
                $formattedHeaders[] = $name . ': ' . $value;
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $formattedHeaders,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
            ]);

            $responseBody = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($responseBody === false) {
                throw new RuntimeException($error !== '' ? $error : 'Agent dispatch cURL request failed.');
            }

            return [
                'status_code' => $statusCode,
                'body' => (string) $responseBody,
            ];
        }

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $formattedHeaders),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('Agent dispatch HTTP request failed.');
        }

        $statusCode = 0;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
                $statusCode = (int) $matches[1];
                break;
            }
        }

        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
        ];
    }
}
