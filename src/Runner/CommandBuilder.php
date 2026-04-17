<?php

declare(strict_types=1);

namespace JetWP\Control\Runner;

use InvalidArgumentException;

final class CommandBuilder
{
    public function __construct(
        private readonly string $wpCli,
        private readonly string $wpPath,
        private readonly ?string $siteUrl = null,
    ) {
    }

    public function build(string $type, array $params): string
    {
        $base = 'cd ' . escapeshellarg($this->wpPath) . ' && ' . escapeshellarg($this->wpCli);

        return match ($type) {
            'uptime.check' => $this->uptimeCheck(),
            'cache.flush' => "{$base} cache flush && {$base} transient delete --all",
            'translations.update' => "{$base} language core update && {$base} language plugin update --all && {$base} language theme update --all",
            'plugin.update' => $this->pluginUpdate($base, $params),
            'plugin.rollback' => $this->pluginRollback($base, $params),
            'plugin.update_all' => "{$base} plugin update --all",
            'core.update' => $this->coreUpdate($base, $params),
            'core.rollback' => $this->coreRollback($base, $params),
            'theme.update' => $this->themeUpdate($base, $params),
            'theme.rollback' => $this->themeRollback($base, $params),
            'security.integrity' => "{$base} core verify-checksums --format=json",
            'db.optimize' => "{$base} db optimize",
            default => throw new InvalidArgumentException("Unknown job type: {$type}"),
        };
    }

    private function pluginUpdate(string $base, array $params): string
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('plugin.update requires params.slug');
        }

        return "{$base} plugin update " . escapeshellarg($slug);
    }

    private function pluginRollback(string $base, array $params): string
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $version = trim((string) ($params['version'] ?? ''));
        if ($slug === '' || $version === '') {
            throw new InvalidArgumentException('plugin.rollback requires params.slug and params.version');
        }

        return "{$base} plugin install "
            . escapeshellarg($slug)
            . ' --version='
            . escapeshellarg($version)
            . ' --force';
    }

    private function coreUpdate(string $base, array $params): string
    {
        $version = trim((string) ($params['version'] ?? ''));

        if ($version !== '') {
            return "{$base} core update --version=" . escapeshellarg($version);
        }

        return "{$base} core update";
    }

    private function coreRollback(string $base, array $params): string
    {
        $version = trim((string) ($params['version'] ?? ''));
        if ($version === '') {
            throw new InvalidArgumentException('core.rollback requires params.version');
        }

        return "{$base} core update --version=" . escapeshellarg($version) . ' --force';
    }

    private function themeUpdate(string $base, array $params): string
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('theme.update requires params.slug');
        }

        return "{$base} theme update " . escapeshellarg($slug);
    }

    private function themeRollback(string $base, array $params): string
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $version = trim((string) ($params['version'] ?? ''));
        if ($slug === '' || $version === '') {
            throw new InvalidArgumentException('theme.rollback requires params.slug and params.version');
        }

        return "{$base} theme install "
            . escapeshellarg($slug)
            . ' --version='
            . escapeshellarg($version)
            . ' --force';
    }

    private function uptimeCheck(): string
    {
        $url = trim((string) $this->siteUrl);
        if ($url === '') {
            throw new InvalidArgumentException('uptime.check requires a site URL.');
        }

        return "curl -sL -o /dev/null -w '%{http_code}' --max-time 10 " . escapeshellarg($url);
    }
}
