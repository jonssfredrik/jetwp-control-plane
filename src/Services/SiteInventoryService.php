<?php

declare(strict_types=1);

namespace JetWP\Control\Services;

use JetWP\Control\Models\Site;
use JetWP\Control\Models\Telemetry;
use PDO;

final class SiteInventoryService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * }
     */
    public function snapshotForSite(Site $site, int $historyLimit = 30): array
    {
        $history = Telemetry::recentForSite($this->db, $site->id, $historyLimit);
        $latestTelemetry = $history[0] ?? null;
        $payload = is_array($latestTelemetry['payload'] ?? null) ? $latestTelemetry['payload'] : [];

        $plugins = $this->buildPluginInventory(
            is_array($payload['plugins'] ?? null) ? $payload['plugins'] : [],
            $history
        );
        $themes = $this->buildThemeInventory(
            is_array($payload['themes'] ?? null) ? $payload['themes'] : [],
            $history
        );

        $currentCoreVersion = $this->firstNonEmptyString([
            $payload['core']['current_version'] ?? null,
            $payload['wp_version'] ?? null,
            $site->wpVersion,
        ]);
        $availableCoreUpdate = $this->firstNonEmptyString([
            $payload['core']['available_update'] ?? null,
            $payload['core_update'] ?? null,
        ]);

        return [
            'latest_telemetry' => $latestTelemetry,
            'history' => $history,
            'core' => [
                'current_version' => $currentCoreVersion,
                'available_update' => $availableCoreUpdate,
                'rollback_version' => $currentCoreVersion !== null
                    ? $this->findPreviousCoreVersion($history, $currentCoreVersion)
                    : null,
                'has_update' => $availableCoreUpdate !== null,
            ],
            'plugins' => $plugins,
            'themes' => $themes,
            'summary' => [
                'plugin_count' => count($plugins),
                'theme_count' => count($themes),
                'plugin_updates' => count(array_filter(
                    $plugins,
                    static fn (array $item): bool => $item['update_available'] !== null
                )),
                'theme_updates' => count(array_filter(
                    $themes,
                    static fn (array $item): bool => $item['update_available'] !== null
                )),
            ],
        ];
    }

    /**
     * @param mixed $items
     * @param list<array{id:int,site_id:string,payload:array,received_at:string}> $history
     * @return list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>
     */
    private function buildPluginInventory(mixed $items, array $history): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slug = trim((string) ($item['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $version = trim((string) ($item['version'] ?? ''));
            $normalized[] = [
                'slug' => $slug,
                'name' => trim((string) ($item['name'] ?? $slug)),
                'version' => $version,
                'active' => (bool) ($item['active'] ?? false),
                'update_available' => $this->nullableString($item['update_available'] ?? null),
                'rollback_version' => $version !== ''
                    ? $this->findPreviousVersion($history, 'plugins', $slug, $version)
                    : null,
                'file' => $this->nullableString($item['file'] ?? null),
            ];
        }

        usort($normalized, [$this, 'compareInventoryItems']);

        return $normalized;
    }

    /**
     * @param mixed $items
     * @param list<array{id:int,site_id:string,payload:array,received_at:string}> $history
     * @return list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>
     */
    private function buildThemeInventory(mixed $items, array $history): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slug = trim((string) ($item['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $version = trim((string) ($item['version'] ?? ''));
            $normalized[] = [
                'slug' => $slug,
                'name' => trim((string) ($item['name'] ?? $slug)),
                'version' => $version,
                'active' => (bool) ($item['active'] ?? false),
                'update_available' => $this->nullableString($item['update_available'] ?? null),
                'rollback_version' => $version !== ''
                    ? $this->findPreviousVersion($history, 'themes', $slug, $version)
                    : null,
            ];
        }

        usort($normalized, [$this, 'compareInventoryItems']);

        return $normalized;
    }

    /**
     * @param list<array{id:int,site_id:string,payload:array,received_at:string}> $history
     */
    private function findPreviousVersion(array $history, string $collectionKey, string $slug, string $currentVersion): ?string
    {
        $seenCurrent = false;

        foreach ($history as $entry) {
            $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
            $items = is_array($payload[$collectionKey] ?? null) ? $payload[$collectionKey] : [];

            foreach ($items as $item) {
                if (!is_array($item) || trim((string) ($item['slug'] ?? '')) !== $slug) {
                    continue;
                }

                $version = trim((string) ($item['version'] ?? ''));
                if ($version === '') {
                    continue;
                }

                if ($version === $currentVersion) {
                    $seenCurrent = true;
                    continue;
                }

                if ($seenCurrent) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{id:int,site_id:string,payload:array,received_at:string}> $history
     */
    private function findPreviousCoreVersion(array $history, string $currentVersion): ?string
    {
        $seenCurrent = false;

        foreach ($history as $entry) {
            $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
            $version = $this->firstNonEmptyString([
                $payload['core']['current_version'] ?? null,
                $payload['wp_version'] ?? null,
            ]);

            if ($version === null) {
                continue;
            }

            if ($version === $currentVersion) {
                $seenCurrent = true;
                continue;
            }

            if ($seenCurrent) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param array{name:string,active:bool,update_available:?string} $left
     * @param array{name:string,active:bool,update_available:?string} $right
     */
    private function compareInventoryItems(array $left, array $right): int
    {
        if ($left['active'] !== $right['active']) {
            return $left['active'] ? -1 : 1;
        }

        $leftHasUpdate = $left['update_available'] !== null;
        $rightHasUpdate = $right['update_available'] !== null;
        if ($leftHasUpdate !== $rightHasUpdate) {
            return $leftHasUpdate ? -1 : 1;
        }

        return strcasecmp($left['name'], $right['name']);
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = $this->nullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
