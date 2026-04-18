<?php

declare(strict_types=1);

namespace JetWP\Control\Api;

use JetWP\Control\Models\Job;
use JetWP\Control\Models\PairingToken;
use JetWP\Control\Models\Server;
use JetWP\Control\Models\Site;
use JetWP\Control\Models\Telemetry;
use JetWP\Control\Security\Secrets;
use JetWP\Control\Services\ActivityLogService;
use PDO;
use Throwable;

final class AgentApi
{
    private const HEARTBEAT_MAX_BYTES = 262144;

    public function __construct(
        private readonly PDO $db,
        private readonly Secrets $secrets,
        private readonly ?ActivityLogService $activityLog = null,
    ) {
    }

    public function handles(string $path): bool
    {
        return str_starts_with($path, '/api/v1/');
    }

    public function dispatch(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/api/v1/health') {
            $this->respond([
                'status' => 'ok',
                'service' => 'jetwp-control',
                'time' => gmdate('c'),
            ]);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/sites/register') {
            $this->register();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9-]{36})/heartbeat$#i', $path, $matches) === 1) {
            $this->heartbeat(strtolower($matches[1]));
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/sites/([a-f0-9-]{36})/jobs/pending$#i', $path, $matches) === 1) {
            $this->pendingJobs(strtolower($matches[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9-]{36})/jobs/claim$#i', $path, $matches) === 1) {
            $this->claimPendingJob(strtolower($matches[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9-]{36})/job-result$#i', $path, $matches) === 1) {
            $this->storeJobResult(strtolower($matches[1]));
            return;
        }

        $this->error(404, 'Not found.', 'NOT_FOUND');
    }

    private function register(): void
    {
        [$rawBody, $payload] = $this->readJsonBody();

        $pairingToken = trim((string) ($payload['pairing_token'] ?? ''));
        $url = Site::normalizeUrl((string) ($payload['url'] ?? ''));
        $label = trim((string) ($payload['label'] ?? ''));
        $wpPath = trim((string) ($payload['wp_path'] ?? ''));
        $wpVersion = $this->nullableString($payload['wp_version'] ?? null);
        $phpVersion = $this->nullableString($payload['php_version'] ?? null);

        $errors = [];

        if ($pairingToken === '') {
            $errors['pairing_token'][] = 'Pairing token is required.';
        }

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors['url'][] = 'URL must be a valid absolute URL.';
        }

        if ($label === '') {
            $errors['label'][] = 'Label is required.';
        }

        if ($wpPath === '') {
            $errors['wp_path'][] = 'WordPress path is required.';
        }

        if ($errors !== []) {
            $this->activityLog?->logAgentRequest(null, 'register.rejected', [
                'reason' => 'validation_error',
                'url' => $url,
                'label' => $label,
                'wp_path' => $wpPath,
                'payload_bytes' => strlen($rawBody),
                'errors' => $errors,
            ]);
            $this->error(400, 'Validation failed.', 'VALIDATION_ERROR', $errors);
        }

        try {
            $this->db->beginTransaction();

            $token = PairingToken::lockByToken($this->db, $pairingToken);
            if (!$token instanceof PairingToken) {
                $this->db->rollBack();
                $this->activityLog?->logAgentRequest(null, 'register.rejected', [
                    'reason' => 'invalid_pairing_token',
                    'url' => $url,
                    'payload_bytes' => strlen($rawBody),
                ]);
                $this->error(403, 'Pairing token is invalid.', 'FORBIDDEN');
            }

            if ($token->used) {
                $this->db->rollBack();
                $this->activityLog?->logAgentRequest(null, 'register.rejected', [
                    'reason' => 'pairing_token_used',
                    'url' => $url,
                    'token_id' => $token->id,
                ]);
                $this->error(403, 'Pairing token has already been used.', 'FORBIDDEN');
            }

            if (strtotime($token->expiresAt) < time()) {
                $this->db->rollBack();
                $this->activityLog?->logAgentRequest(null, 'register.rejected', [
                    'reason' => 'pairing_token_expired',
                    'url' => $url,
                    'token_id' => $token->id,
                ]);
                $this->error(403, 'Pairing token has expired.', 'FORBIDDEN');
            }

            if ($token->serverId === null) {
                $this->db->rollBack();
                $this->activityLog?->logAgentRequest(null, 'register.rejected', [
                    'reason' => 'pairing_token_unassigned',
                    'url' => $url,
                    'token_id' => $token->id,
                ]);
                $this->error(400, 'Pairing token is not assigned to a server.', 'VALIDATION_ERROR', [
                    'pairing_token' => ['Pairing token is not assigned to a server.'],
                ]);
            }

            $server = Server::findById($this->db, $token->serverId);
            if (!$server instanceof Server || !$server->isActive) {
                $this->db->rollBack();
                $this->activityLog?->logAgentRequest(null, 'register.rejected', [
                    'reason' => 'inactive_server',
                    'url' => $url,
                    'server_id' => $token->serverId,
                ]);
                $this->error(403, 'Pairing token is not assigned to an active server.', 'FORBIDDEN');
            }

            if (Site::findByUrl($this->db, $url) instanceof Site) {
                $this->db->rollBack();
                $existingSite = Site::findByUrl($this->db, $url);
                $this->activityLog?->logAgentRequest($existingSite?->id, 'register.rejected', [
                    'reason' => 'site_already_registered',
                    'url' => $url,
                    'server_id' => $server->id,
                ]);
                $this->error(409, 'A site with this URL is already registered.', 'CONFLICT');
            }

            $hmacSecret = bin2hex(random_bytes(32));
            $site = Site::create($this->db, [
                'server_id' => $server->id,
                'url' => $url,
                'label' => $label,
                'wp_path' => $wpPath,
                'hmac_secret' => $this->secrets->encrypt($hmacSecret),
                'wp_version' => $wpVersion,
                'php_version' => $phpVersion,
            ]);

            PairingToken::markUsed($this->db, $token->id);
            $this->db->commit();
            $this->activityLog?->logAgentRequest($site->id, 'register.succeeded', [
                'server_id' => $server->id,
                'url' => $site->url,
                'label' => $site->label,
                'wp_path' => $site->wpPath,
                'payload_bytes' => strlen($rawBody),
            ]);

            $this->respond([
                'status' => 'ok',
                'data' => [
                    'site_id' => $site->id,
                    'hmac_secret' => $hmacSecret,
                ],
            ], 200);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->activityLog?->logAgentRequest(null, 'register.failed', [
                'reason' => 'internal_error',
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);
            $this->error(500, 'Failed to register site.', 'INTERNAL_ERROR');
        }
    }

    private function heartbeat(string $siteId): void
    {
        $rawBody = $this->readRawBody();
        if (strlen($rawBody) > self::HEARTBEAT_MAX_BYTES) {
            $this->activityLog?->logAgentRequest($siteId, 'heartbeat.rejected', [
                'reason' => 'payload_too_large',
                'payload_bytes' => strlen($rawBody),
            ]);
            $this->error(400, 'Heartbeat payload exceeds the maximum allowed size.', 'VALIDATION_ERROR', [
                'body' => ['Heartbeat payload exceeds 256 KB.'],
            ]);
        }

        $site = $this->authenticateSiteRequest($siteId, $rawBody, 'heartbeat');

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || array_is_list($payload)) {
            $this->activityLog?->logAgentRequest($site->id, 'heartbeat.rejected', [
                'reason' => 'invalid_json',
                'payload_bytes' => strlen($rawBody),
            ]);
            $this->error(400, 'Request body must be a JSON object.', 'VALIDATION_ERROR', [
                'body' => ['Request body must be a JSON object.'],
            ]);
        }

        try {
            $this->db->beginTransaction();
            Telemetry::create($this->db, $site->id, $payload);
            Site::recordHeartbeat(
                $this->db,
                $site->id,
                $this->nullableString($payload['wp_version'] ?? null),
                $this->nullableString($payload['php_version'] ?? null)
            );
            $this->db->commit();
            $pendingJobs = Job::countPendingForSite($this->db, $site->id, [
                Job::STRATEGY_AGENT_ONLY,
                Job::STRATEGY_AGENT_PREFERRED,
            ]);
            $this->activityLog?->logAgentRequest($site->id, 'heartbeat.received', [
                'payload_bytes' => strlen($rawBody),
                'wp_version' => $this->nullableString($payload['wp_version'] ?? null),
                'php_version' => $this->nullableString($payload['php_version'] ?? null),
                'pending_jobs' => $pendingJobs,
            ]);

            $this->respond([
                'status' => 'ok',
                'pending_jobs' => $pendingJobs,
            ]);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->activityLog?->logAgentRequest($site->id, 'heartbeat.failed', [
                'reason' => 'internal_error',
                'message' => $exception->getMessage(),
            ]);
            $this->error(500, 'Failed to store heartbeat telemetry.', 'INTERNAL_ERROR');
        }
    }

    private function pendingJobs(string $siteId): void
    {
        $rawBody = $this->readOptionalRawBody();
        $site = $this->authenticateSiteRequest($siteId, $rawBody, 'jobs.pending');
        $jobs = Job::pendingForSite($this->db, $site->id, [
            Job::STRATEGY_AGENT_ONLY,
            Job::STRATEGY_AGENT_PREFERRED,
        ]);

        $data = [];
        $jobIds = [];
        foreach ($jobs as $job) {
            $data[] = [
                'job_id' => $job->id,
                'type' => $job->type,
                'params' => $this->formatJobParams($job->params),
            ];
            $jobIds[] = $job->id;
        }

        $this->activityLog?->logAgentRequest($site->id, 'jobs.pending.fetched', [
            'count' => count($data),
            'job_ids' => $jobIds,
        ]);

        $this->respond([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    private function claimPendingJob(string $siteId): void
    {
        $rawBody = $this->readOptionalRawBody();
        $site = $this->authenticateSiteRequest($siteId, $rawBody, 'jobs.claim');
        $job = Job::claimNextPendingForAgent($this->db, $site->id);

        if (!$job instanceof Job) {
            $this->activityLog?->logAgentRequest($site->id, 'jobs.claim.empty', []);
            $this->respond([
                'status' => 'ok',
                'data' => null,
            ]);
        }

        $this->activityLog?->logAgentRequest($site->id, 'jobs.claim.succeeded', [
            'job_id' => $job->id,
            'type' => $job->type,
            'execution_strategy' => $job->executionStrategy,
        ]);

        $this->respond([
            'status' => 'ok',
            'data' => [
                'job_id' => $job->id,
                'type' => $job->type,
                'params' => $this->formatJobParams($job->params),
                'execution_strategy' => $job->executionStrategy,
            ],
        ]);
    }

    private function storeJobResult(string $siteId): void
    {
        [$rawBody, $payload] = $this->readJsonBody();
        $site = $this->authenticateSiteRequest($siteId, $rawBody, 'job-result');

        $jobId = trim((string) ($payload['job_id'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $output = $this->nullableOutput($payload['output'] ?? null);
        $errorOutput = $this->nullableOutput($payload['error_output'] ?? null);
        $durationMs = $payload['duration_ms'] ?? null;

        $errors = [];

        if ($jobId === '') {
            $errors['job_id'][] = 'Job ID is required.';
        }

        if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $errors['status'][] = 'Status must be completed, failed, or cancelled.';
        }

        if (!is_int($durationMs) && !(is_string($durationMs) && ctype_digit($durationMs))) {
            $errors['duration_ms'][] = 'Duration must be an integer number of milliseconds.';
        } elseif ((int) $durationMs < 0) {
            $errors['duration_ms'][] = 'Duration must be zero or greater.';
        }

        if (array_key_exists('output', $payload) && !is_string($payload['output']) && $payload['output'] !== null) {
            $errors['output'][] = 'Output must be a string or null.';
        }

        if (array_key_exists('error_output', $payload) && !is_string($payload['error_output']) && $payload['error_output'] !== null) {
            $errors['error_output'][] = 'Error output must be a string or null.';
        }

        if ($errors !== []) {
            $this->activityLog?->logAgentRequest($site->id, 'job_result.rejected', [
                'reason' => 'validation_error',
                'payload_bytes' => strlen($rawBody),
                'job_id' => $jobId,
                'errors' => $errors,
            ]);
            $this->error(400, 'Validation failed.', 'VALIDATION_ERROR', $errors);
        }

        $job = Job::findByIdForSite($this->db, $jobId, $site->id);
        if (!$job instanceof Job) {
            $this->activityLog?->logAgentRequest($site->id, 'job_result.rejected', [
                'reason' => 'job_not_found',
                'job_id' => $jobId,
            ]);
            $this->error(404, 'Job not found.', 'NOT_FOUND');
        }

        try {
            Job::updateResult(
                $this->db,
                $job->id,
                $site->id,
                $status,
                $output,
                (int) $durationMs,
                $errorOutput
            );
            $this->activityLog?->logAgentRequest($site->id, 'job_result.received', [
                'job_id' => $job->id,
                'status' => $status,
                'duration_ms' => (int) $durationMs,
                'has_output' => $output !== null && $output !== '',
                'has_error_output' => $errorOutput !== null && $errorOutput !== '',
                'payload_bytes' => strlen($rawBody),
            ]);

            $this->respond([
                'status' => 'ok',
            ]);
        } catch (Throwable $exception) {
            $this->activityLog?->logAgentRequest($site->id, 'job_result.failed', [
                'reason' => 'internal_error',
                'job_id' => $job->id,
                'message' => $exception->getMessage(),
            ]);
            $this->error(500, 'Failed to store job result.', 'INTERNAL_ERROR');
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function readJsonBody(): array
    {
        $rawBody = $this->readRawBody();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload) || array_is_list($payload)) {
            $this->error(400, 'Request body must be a JSON object.', 'VALIDATION_ERROR', [
                'body' => ['Request body must be a JSON object.'],
            ]);
        }

        return [$rawBody, $payload];
    }

    private function readRawBody(): string
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || trim($rawBody) === '') {
            $this->error(400, 'Request body is required.', 'VALIDATION_ERROR', [
                'body' => ['Request body is required.'],
            ]);
        }

        return $rawBody;
    }

    private function readOptionalRawBody(): string
    {
        $rawBody = file_get_contents('php://input');

        return is_string($rawBody) ? $rawBody : '';
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = (string) $value;
        }

        return $headers;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableOutput(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function formatJobParams(?array $params): array|object
    {
        if ($params === null || $params === []) {
            return (object) [];
        }

        return $params;
    }

    private function authenticateSiteRequest(string $siteId, string $rawBody, string $operation): Site
    {
        $headers = $this->requestHeaders();
        $headerSiteId = trim((string) ($headers['X-JetWP-Site-Id'] ?? ''));
        $signature = trim((string) ($headers['X-JetWP-Signature'] ?? ''));
        $timestampHeader = trim((string) ($headers['X-JetWP-Timestamp'] ?? ''));
        $site = Site::findById($this->db, $siteId);
        $loggedSiteId = $site?->id;

        if ($headerSiteId === '' || $signature === '' || $timestampHeader === '') {
            $this->activityLog?->logAgentRequest($loggedSiteId, 'auth.rejected', [
                'operation' => $operation,
                'reason' => 'missing_headers',
            ]);
            $this->error(401, 'Authentication headers are required.', 'AUTH_REQUIRED');
        }

        if (!ctype_digit($timestampHeader)) {
            $this->activityLog?->logAgentRequest($loggedSiteId, 'auth.rejected', [
                'operation' => $operation,
                'reason' => 'invalid_timestamp',
            ]);
            $this->error(403, 'Timestamp header is invalid.', 'FORBIDDEN');
        }

        if ($headerSiteId !== $siteId) {
            $this->activityLog?->logAgentRequest($loggedSiteId, 'auth.rejected', [
                'operation' => $operation,
                'reason' => 'site_id_mismatch',
                'header_site_id' => $headerSiteId,
            ]);
            $this->error(403, 'Header site ID does not match the request path.', 'FORBIDDEN');
        }

        if (!$site instanceof Site) {
            $this->activityLog?->logAgentRequest(null, 'auth.rejected', [
                'operation' => $operation,
                'reason' => 'site_not_found',
                'site_id' => $siteId,
            ]);
            $this->error(404, 'Site not found.', 'NOT_FOUND');
        }

        $timestamp = (int) $timestampHeader;
        if (abs(time() - $timestamp) > 60) {
            $this->activityLog?->logAgentRequest($site->id, 'auth.rejected', [
                'operation' => $operation,
                'reason' => 'timestamp_outside_window',
                'timestamp' => $timestamp,
            ]);
            $this->error(403, 'Timestamp is outside the allowed window.', 'FORBIDDEN');
        }

        try {
            $secret = $this->secrets->decrypt($site->hmacSecret);
        } catch (Throwable) {
            $this->activityLog?->logAgentRequest($site->id, 'auth.failed', [
                'operation' => $operation,
                'reason' => 'secret_decrypt_failed',
            ]);
            $this->error(500, 'Stored HMAC secret could not be decrypted.', 'INTERNAL_ERROR');
        }

        $expectedSignature = hash_hmac('sha256', $rawBody . '|' . $timestampHeader, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            $this->activityLog?->logAgentRequest($site->id, 'auth.rejected', [
                'operation' => $operation,
                'reason' => 'invalid_signature',
            ]);
            $this->error(403, 'HMAC signature is invalid.', 'FORBIDDEN');
        }

        return $site;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private function error(int $status, string $message, string $code, array $errors = []): never
    {
        $payload = [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $this->respond($payload, $status);
    }
}
