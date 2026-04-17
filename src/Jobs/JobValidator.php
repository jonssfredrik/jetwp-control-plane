<?php

declare(strict_types=1);

namespace JetWP\Control\Jobs;

use DateTimeImmutable;
use JetWP\Control\Models\Site;
use PDO;
use Throwable;

final class JobValidator
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_TYPES = [
        'uptime.check',
        'cache.flush',
        'translations.update',
        'plugin.update',
        'plugin.update_all',
        'core.update',
        'security.integrity',
        'db.optimize',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return self::SUPPORTED_TYPES;
    }

    public function validateForCreate(array $payload): array
    {
        $siteId = trim((string) ($payload['site_id'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));
        $priority = $payload['priority'] ?? 5;
        $params = $payload['params'] ?? [];
        $scheduledAt = $payload['scheduled_at'] ?? null;
        $errors = [];

        if ($siteId === '') {
            $errors['site_id'][] = 'Site ID is required.';
        } elseif (!Site::findById($this->db, $siteId) instanceof Site) {
            $errors['site_id'][] = 'Site was not found.';
        }

        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            $errors['type'][] = 'Job type is not supported for MVP.';
        }

        if (!is_int($priority) && !(is_string($priority) && ctype_digit($priority))) {
            $errors['priority'][] = 'Priority must be an integer between 1 and 10.';
        } elseif ((int) $priority < 1 || (int) $priority > 10) {
            $errors['priority'][] = 'Priority must be between 1 and 10.';
        }

        if (!is_array($params) || (array_is_list($params) && $params !== [])) {
            $errors['params'][] = 'Params must be a JSON object.';
        }

        $normalizedParams = [];
        if ($errors === []) {
            $normalizedParams = $this->validateTypeParams($type, $params);
        }

        if ($scheduledAt !== null && (!is_string($scheduledAt) || trim($scheduledAt) === '')) {
            $errors['scheduled_at'][] = 'Scheduled at must be a datetime string or null.';
        }

        $normalizedScheduledAt = null;
        if (is_string($scheduledAt) && trim($scheduledAt) !== '') {
            try {
                $normalizedScheduledAt = (new DateTimeImmutable($scheduledAt))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $errors['scheduled_at'][] = 'Scheduled at must be a valid datetime string.';
            }
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }

        return [
            'site_id' => $siteId,
            'type' => $type,
            'params' => $normalizedParams,
            'priority' => (int) $priority,
            'max_attempts' => 3,
            'scheduled_at' => $normalizedScheduledAt,
            'created_by' => 'manual',
        ];
    }

    private function validateTypeParams(string $type, array $params): array
    {
        $errors = [];
        $normalized = [];

        if (in_array($type, ['uptime.check', 'cache.flush', 'translations.update', 'plugin.update_all', 'security.integrity', 'db.optimize'], true)) {
            if ($params !== []) {
                $errors['params'][] = sprintf('%s does not accept params in MVP.', $type);
            }
        }

        if ($type === 'plugin.update') {
            $slug = trim((string) ($params['slug'] ?? ''));
            if ($slug === '') {
                $errors['params'][] = 'plugin.update requires params.slug.';
            } else {
                $normalized['slug'] = $slug;
            }
        }

        if ($type === 'core.update') {
            $version = trim((string) ($params['version'] ?? ''));
            if ($version !== '') {
                $normalized['version'] = $version;
            }
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }

        return $normalized;
    }
}
