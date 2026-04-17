<?php

declare(strict_types=1);

namespace JetWP\Control\Services;

use JetWP\Control\Config\Config;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\Site;
use PDO;

final class AlertService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Config $config,
        private readonly ActivityLogService $activityLog
    ) {
    }

    public function notifyJobFailedAfterMaxRetries(Job $job): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $site = Site::findById($this->db, $job->siteId);
        $subject = sprintf(
            '[JetWP] Job failed after max retries: %s (%s)',
            $job->type,
            $site?->label ?? $job->siteId
        );
        $body = implode(PHP_EOL, [
            'A job has failed after reaching its retry limit.',
            '',
            'Site: ' . ($site?->label ?? 'Unknown site'),
            'Site ID: ' . $job->siteId,
            'Job ID: ' . $job->id,
            'Type: ' . $job->type,
            'Attempts: ' . $job->attempts . '/' . $job->maxAttempts,
            'Completed At: ' . ($job->completedAt ?? 'n/a'),
            'Duration (ms): ' . ($job->durationMs !== null ? (string) $job->durationMs : 'n/a'),
            '',
            'Error output:',
            $job->errorOutput ?? '(empty)',
        ]);

        $delivered = $this->deliver($subject, $body);
        if ($delivered) {
            $this->activityLog->logAlert('alert.job_failed_max_retries', $job->siteId, [
                'job_id' => $job->id,
                'type' => $job->type,
                'attempts' => $job->attempts,
                'max_attempts' => $job->maxAttempts,
                'driver' => $this->driver(),
            ]);
        }

        return $delivered;
    }

    public function checkMissedHeartbeats(?int $thresholdMinutes = null): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $thresholdMinutes = $thresholdMinutes ?? (int) $this->config->get('alerts.heartbeat_missed_minutes', 30);
        $thresholdMinutes = max(1, $thresholdMinutes);
        $sent = 0;

        foreach ($this->staleSites($thresholdMinutes) as $site) {
            $reference = $site->lastHeartbeatAt ?? $site->registeredAt;
            if ($this->activityLog->hasSiteActionSince('alert.heartbeat_missed', $site->id, $reference)) {
                continue;
            }

            if ($this->notifyMissedHeartbeat($site, $thresholdMinutes)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @return list<Site>
     */
    private function staleSites(int $thresholdMinutes): array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM sites
             WHERE status <> :paused_status
               AND COALESCE(last_heartbeat_at, registered_at) <= DATE_SUB(NOW(), INTERVAL :threshold MINUTE)
             ORDER BY COALESCE(last_heartbeat_at, registered_at) ASC'
        );
        $statement->bindValue('paused_status', 'paused');
        $statement->bindValue('threshold', $thresholdMinutes, PDO::PARAM_INT);
        $statement->execute();

        $sites = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $sites[] = new Site(
                    id: (string) $row['id'],
                    serverId: (int) $row['server_id'],
                    url: (string) $row['url'],
                    label: (string) $row['label'],
                    wpPath: (string) $row['wp_path'],
                    hmacSecret: (string) $row['hmac_secret'],
                    status: (string) $row['status'],
                    wpVersion: isset($row['wp_version']) ? (string) $row['wp_version'] : null,
                    phpVersion: isset($row['php_version']) ? (string) $row['php_version'] : null,
                    lastHeartbeatAt: isset($row['last_heartbeat_at']) ? (string) $row['last_heartbeat_at'] : null,
                    registeredAt: (string) $row['registered_at'],
                    updatedAt: (string) $row['updated_at'],
                );
            }
        }

        return $sites;
    }

    private function notifyMissedHeartbeat(Site $site, int $thresholdMinutes): bool
    {
        $lastSeenAt = $site->lastHeartbeatAt ?? $site->registeredAt;
        $subject = sprintf('[JetWP] Missed heartbeat: %s', $site->label);
        $body = implode(PHP_EOL, [
            'A site has missed the configured heartbeat threshold.',
            '',
            'Site: ' . $site->label,
            'Site ID: ' . $site->id,
            'URL: ' . $site->url,
            'Status: ' . $site->status,
            'Last seen at: ' . $lastSeenAt,
            'Threshold (minutes): ' . $thresholdMinutes,
        ]);

        $delivered = $this->deliver($subject, $body);
        if ($delivered) {
            $this->activityLog->logAlert('alert.heartbeat_missed', $site->id, [
                'threshold_minutes' => $thresholdMinutes,
                'last_seen_at' => $lastSeenAt,
                'driver' => $this->driver(),
            ]);
        }

        return $delivered;
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('alerts.enabled', true);
    }

    private function driver(): string
    {
        return (string) $this->config->get('alerts.driver', 'log');
    }

    private function deliver(string $subject, string $body): bool
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            return false;
        }

        if ($this->driver() !== 'mail') {
            error_log(sprintf(
                'JETWP ALERT [%s] To: %s%s%s',
                $subject,
                implode(', ', $recipients),
                PHP_EOL,
                $body
            ));

            return true;
        }

        $headers = [
            'From: ' . $this->fromHeader(),
            'Content-Type: text/plain; charset=UTF-8',
        ];

        return mail(
            implode(',', $recipients),
            $subject,
            $body,
            implode("\r\n", $headers)
        );
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $configured = $this->config->get('alerts.recipients', []);
        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $configured
            )));
        }

        $statement = $this->db->query(
            "SELECT email FROM users WHERE role = 'admin' ORDER BY id ASC"
        );

        return array_values(array_filter(array_map(
            static fn (mixed $row): string => trim((string) ($row['email'] ?? '')),
            $statement->fetchAll()
        )));
    }

    private function fromHeader(): string
    {
        $email = trim((string) $this->config->get('alerts.from_email', 'alerts@localhost'));
        $name = trim((string) $this->config->get('alerts.from_name', 'JetWP Control Plane'));

        return $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
    }
}
