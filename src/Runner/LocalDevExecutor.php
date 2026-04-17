<?php

declare(strict_types=1);

namespace JetWP\Control\Runner;

use JetWP\Control\Models\Job;
use JetWP\Control\Models\Server;
use JetWP\Control\Models\Site;
use RuntimeException;

final class LocalDevExecutor
{
    /**
     * @var array<int, string>
     */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_TYPES = [
        'uptime.check',
        'cache.flush',
        'translations.update',
        'core.update',
        'core.rollback',
        'plugin.update',
        'plugin.rollback',
        'plugin.update_all',
        'theme.update',
        'theme.rollback',
        'security.integrity',
        'db.optimize',
    ];

    public function supports(Site $site, Server $server, string $type): bool
    {
        return in_array(strtolower($server->hostname), self::LOCAL_HOSTS, true)
            && in_array($type, self::SUPPORTED_TYPES, true)
            && is_file(rtrim($site->wpPath, "/\\") . '/wp-load.php');
    }

    public function describe(Job $job): string
    {
        return sprintf('local-dev:%s', $job->type);
    }

    public function execute(Site $site, Server $server, Job $job, int $timeoutSeconds): ExecutionResult
    {
        $operation = [
            'type' => $job->type,
            'params' => $job->params ?? [],
            'wp_path' => rtrim($site->wpPath, "/\\"),
            'site_url' => $site->url,
        ];

        $scriptFile = tempnam(sys_get_temp_dir(), 'jetwp_local_');
        if ($scriptFile === false) {
            throw new RuntimeException('Failed to allocate a temporary script file for local dev execution.');
        }

        $script = $this->buildScript($operation);
        if (file_put_contents($scriptFile, $script) === false) {
            @unlink($scriptFile);
            throw new RuntimeException('Failed to write the temporary local dev execution script.');
        }

        $command = [$this->phpBinary($server), $scriptFile];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $start = microtime(true);
        $process = proc_open($command, $descriptorSpec, $pipes, $operation['wp_path'], null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            @unlink($scriptFile);
            throw new RuntimeException('Failed to start the local dev execution process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $timedOut = false;

        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);

                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }

                if ((microtime(true) - $start) >= $timeoutSeconds) {
                    $timedOut = true;
                    proc_terminate($process);
                    $stderr .= ($stderr === '' ? '' : PHP_EOL) . sprintf(
                        'Local dev execution timed out after %d seconds.',
                        $timeoutSeconds
                    );
                    $exitCode = 124;
                    break;
                }

                usleep(10000);
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $procCloseExitCode = proc_close($process);
            @unlink($scriptFile);

            if (!$timedOut && $exitCode < 0) {
                $exitCode = is_int($procCloseExitCode) ? $procCloseExitCode : 1;
            }
        }

        return new ExecutionResult(
            command: $this->stringifyCommand($command),
            stdout: trim($stdout),
            stderr: trim($stderr),
            exitCode: $exitCode,
            durationMs: (int) round((microtime(true) - $start) * 1000),
            timedOut: $timedOut,
        );
    }

    private function phpBinary(Server $server): string
    {
        $configured = trim($server->phpPath);

        if ($configured === '' || strtolower($configured) === 'php') {
            return PHP_BINARY;
        }

        return $configured;
    }

    /**
     * @param array{type:string,params:array<string,mixed>,wp_path:string,site_url:string} $operation
     */
    private function buildScript(array $operation): string
    {
        $export = var_export($operation, true);

        return <<<PHP
<?php
declare(strict_types=1);

\$operation = {$export};

try {
    \$wpLoad = rtrim((string) \$operation['wp_path'], "/\\\\") . '/wp-load.php';
    if (!is_file(\$wpLoad)) {
        throw new RuntimeException('wp-load.php was not found for local dev execution.');
    }

    define('WP_USE_THEMES', false);
    require \$wpLoad;

    \$findPluginFile = static function (string \$requestedSlug): ?string {
        foreach (get_plugins() as \$file => \$data) {
            \$slug = dirname((string) \$file) === '.' ? basename((string) \$file, '.php') : dirname((string) \$file);
            if (\$slug === \$requestedSlug) {
                return (string) \$file;
            }
        }

        return null;
    };

    \$findTheme = static function (string \$requestedSlug): ?WP_Theme {
        foreach (wp_get_themes() as \$stylesheet => \$theme) {
            if ((string) \$stylesheet === \$requestedSlug) {
                return \$theme;
            }
        }

        return null;
    };

    \$packageUrl = static function (string \$type, string \$slug, string \$version): string {
        if (\$type === 'plugin') {
            return 'https://downloads.wordpress.org/plugin/' . rawurlencode(\$slug) . '.' . rawurlencode(\$version) . '.zip';
        }

        return 'https://downloads.wordpress.org/theme/' . rawurlencode(\$slug) . '.' . rawurlencode(\$version) . '.zip';
    };

    switch (\$operation['type']) {
        case 'uptime.check':
            \$response = wp_remote_get((string) \$operation['site_url'], [
                'timeout' => 10,
                'redirection' => 5,
            ]);
            if (is_wp_error(\$response)) {
                throw new RuntimeException(\$response->get_error_message());
            }
            echo (string) wp_remote_retrieve_response_code(\$response);
            exit(0);

        case 'cache.flush':
            global \$wpdb;

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            \$deleted = 0;
            \$optionsTable = \$wpdb->options;
            \$deleted += (int) \$wpdb->query(
                "DELETE FROM {\$optionsTable}
                 WHERE LEFT(option_name, 11) = '_transient_'
                    OR LEFT(option_name, 16) = '_site_transient_'"
            );

            if (is_multisite() && isset(\$wpdb->sitemeta)) {
                \$siteMetaTable = \$wpdb->sitemeta;
                \$deleted += (int) \$wpdb->query(
                    "DELETE FROM {\$siteMetaTable}
                     WHERE LEFT(meta_key, 16) = '_site_transient_'"
                );
            }

            echo json_encode([
                'message' => 'Cache flushed locally.',
                'deleted_transient_rows' => \$deleted,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'translations.update':
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';

            wp_version_check();
            wp_update_plugins();
            wp_update_themes();

            \$updates = wp_get_translation_updates();
            if (!is_array(\$updates) || \$updates === []) {
                echo json_encode([
                    'message' => 'No translation updates were available.',
                    'updated_count' => 0,
                    'updated' => [],
                ], JSON_UNESCAPED_SLASHES);
                exit(0);
            }

            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Language_Pack_Upgrader(\$skin);
            \$result = \$upgrader->bulk_upgrade(\$updates);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            \$updated = [];
            foreach (\$updates as \$update) {
                if (!is_object(\$update)) {
                    continue;
                }

                \$updated[] = [
                    'type' => isset(\$update->type) ? (string) \$update->type : 'unknown',
                    'slug' => isset(\$update->slug) ? (string) \$update->slug : '',
                    'language' => isset(\$update->language) ? (string) \$update->language : '',
                    'version' => isset(\$update->version) ? (string) \$update->version : '',
                ];
            }

            echo json_encode([
                'message' => 'Translation updates attempted locally.',
                'updated_count' => count(\$updated),
                'updated' => \$updated,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'core.update':
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';

            \$beforeVersion = get_bloginfo('version');
            wp_version_check();
            \$requestedVersion = trim((string) (\$operation['params']['version'] ?? ''));
            \$updates = get_core_updates(['dismissed' => false]);
            if (!is_array(\$updates) || \$updates === []) {
                echo json_encode([
                    'message' => 'No core updates were available.',
                    'requested_version' => \$requestedVersion !== '' ? \$requestedVersion : null,
                ], JSON_UNESCAPED_SLASHES);
                exit(0);
            }

            \$selected = null;
            foreach (\$updates as \$update) {
                if (!is_object(\$update) || !isset(\$update->response) || \$update->response !== 'upgrade') {
                    continue;
                }

                \$version = isset(\$update->current) ? (string) \$update->current : (isset(\$update->version) ? (string) \$update->version : '');
                if (\$requestedVersion === '' || \$version === \$requestedVersion) {
                    \$selected = \$update;
                    break;
                }
            }

            if (\$selected === null) {
                echo json_encode([
                    'message' => \$requestedVersion !== ''
                        ? 'Requested core version was not available for update.'
                        : 'No applicable core update was available.',
                    'requested_version' => \$requestedVersion !== '' ? \$requestedVersion : null,
                ], JSON_UNESCAPED_SLASHES);
                exit(0);
            }

            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Core_Upgrader(\$skin);
            \$result = \$upgrader->upgrade(\$selected);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            \$updatedVersion = isset(\$selected->current) ? (string) \$selected->current : (isset(\$selected->version) ? (string) \$selected->version : get_bloginfo('version'));
            echo json_encode([
                'message' => 'Core update attempted locally.',
                'previous_version' => \$beforeVersion,
                'updated_to' => \$updatedVersion,
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'core.rollback':
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';

            \$requestedVersion = trim((string) (\$operation['params']['version'] ?? ''));
            if (\$requestedVersion === '') {
                throw new RuntimeException('core.rollback requires params.version.');
            }

            \$beforeVersion = get_bloginfo('version');
            \$rollback = (object) [
                'response' => 'upgrade',
                'current' => \$requestedVersion,
                'version' => \$requestedVersion,
                'download' => 'https://wordpress.org/wordpress-' . \$requestedVersion . '.zip',
                'packages' => (object) [
                    'full' => 'https://wordpress.org/wordpress-' . \$requestedVersion . '.zip',
                ],
            ];

            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Core_Upgrader(\$skin);
            \$result = \$upgrader->upgrade(\$rollback);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            echo json_encode([
                'message' => 'Core rollback attempted locally.',
                'previous_version' => \$beforeVersion,
                'rolled_back_to' => \$requestedVersion,
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'plugin.update':
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';

            wp_update_plugins();
            \$requestedSlug = trim((string) (\$operation['params']['slug'] ?? ''));
            if (\$requestedSlug === '') {
                throw new RuntimeException('plugin.update requires params.slug.');
            }

            \$pluginFile = \$findPluginFile(\$requestedSlug);
            if (\$pluginFile === null) {
                throw new RuntimeException('Requested plugin slug was not found locally.');
            }

            \$plugins = get_plugins();
            \$beforeVersion = isset(\$plugins[\$pluginFile]['Version']) ? (string) \$plugins[\$pluginFile]['Version'] : null;
            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Plugin_Upgrader(\$skin);
            \$result = \$upgrader->upgrade(\$pluginFile);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            wp_clean_plugins_cache(true);
            \$afterPlugins = get_plugins();
            echo json_encode([
                'message' => 'Plugin update attempted locally.',
                'slug' => \$requestedSlug,
                'plugin' => \$pluginFile,
                'previous_version' => \$beforeVersion,
                'updated_to' => isset(\$afterPlugins[\$pluginFile]['Version']) ? (string) \$afterPlugins[\$pluginFile]['Version'] : null,
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'plugin.rollback':
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            \$requestedSlug = trim((string) (\$operation['params']['slug'] ?? ''));
            \$requestedVersion = trim((string) (\$operation['params']['version'] ?? ''));
            if (\$requestedSlug === '' || \$requestedVersion === '') {
                throw new RuntimeException('plugin.rollback requires params.slug and params.version.');
            }

            \$pluginFile = \$findPluginFile(\$requestedSlug);
            if (\$pluginFile === null) {
                throw new RuntimeException('Requested plugin slug was not found locally.');
            }

            \$plugins = get_plugins();
            \$beforeVersion = isset(\$plugins[\$pluginFile]['Version']) ? (string) \$plugins[\$pluginFile]['Version'] : null;
            \$wasActive = is_plugin_active(\$pluginFile);

            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Plugin_Upgrader(\$skin);
            \$result = \$upgrader->install(\$packageUrl('plugin', \$requestedSlug, \$requestedVersion), [
                'overwrite_package' => true,
            ]);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            if (\$wasActive) {
                \$activation = activate_plugin(\$pluginFile, '', false, true);
                if (is_wp_error(\$activation)) {
                    throw new RuntimeException(\$activation->get_error_message());
                }
            }

            wp_clean_plugins_cache(true);
            \$afterPlugins = get_plugins();
            echo json_encode([
                'message' => 'Plugin rollback attempted locally.',
                'slug' => \$requestedSlug,
                'plugin' => \$pluginFile,
                'previous_version' => \$beforeVersion,
                'rolled_back_to' => isset(\$afterPlugins[\$pluginFile]['Version']) ? (string) \$afterPlugins[\$pluginFile]['Version'] : \$requestedVersion,
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'plugin.update_all':
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';

            wp_update_plugins();
            \$updates = get_site_transient('update_plugins');
            \$targets = array_keys((array) (\$updates->response ?? []));
            if (\$targets === []) {
                echo json_encode([
                    'message' => 'No plugin updates were available.',
                    'updated' => [],
                ], JSON_UNESCAPED_SLASHES);
                exit(0);
            }

            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Plugin_Upgrader(\$skin);
            \$result = \$upgrader->bulk_upgrade(\$targets);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            echo json_encode([
                'message' => 'Plugin bulk update attempted locally.',
                'updated' => \$targets,
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'theme.update':
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';
            require_once ABSPATH . 'wp-includes/theme.php';

            wp_update_themes();
            \$requestedSlug = trim((string) (\$operation['params']['slug'] ?? ''));
            if (\$requestedSlug === '') {
                throw new RuntimeException('theme.update requires params.slug.');
            }

            \$theme = \$findTheme(\$requestedSlug);
            if (!\$theme instanceof WP_Theme) {
                throw new RuntimeException('Requested theme slug was not found locally.');
            }

            \$beforeVersion = (string) \$theme->get('Version');
            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Theme_Upgrader(\$skin);
            \$result = \$upgrader->upgrade(\$requestedSlug);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            \$afterTheme = wp_get_theme(\$requestedSlug);
            echo json_encode([
                'message' => 'Theme update attempted locally.',
                'slug' => \$requestedSlug,
                'previous_version' => \$beforeVersion,
                'updated_to' => (string) \$afterTheme->get('Version'),
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'theme.rollback':
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-includes/theme.php';

            \$requestedSlug = trim((string) (\$operation['params']['slug'] ?? ''));
            \$requestedVersion = trim((string) (\$operation['params']['version'] ?? ''));
            if (\$requestedSlug === '' || \$requestedVersion === '') {
                throw new RuntimeException('theme.rollback requires params.slug and params.version.');
            }

            \$theme = \$findTheme(\$requestedSlug);
            if (!\$theme instanceof WP_Theme) {
                throw new RuntimeException('Requested theme slug was not found locally.');
            }

            \$beforeVersion = (string) \$theme->get('Version');
            \$skin = new Automatic_Upgrader_Skin();
            \$upgrader = new Theme_Upgrader(\$skin);
            \$result = \$upgrader->install(\$packageUrl('theme', \$requestedSlug, \$requestedVersion), [
                'overwrite_package' => true,
            ]);
            if (is_wp_error(\$result)) {
                throw new RuntimeException(\$result->get_error_message());
            }

            \$afterTheme = wp_get_theme(\$requestedSlug);
            echo json_encode([
                'message' => 'Theme rollback attempted locally.',
                'slug' => \$requestedSlug,
                'previous_version' => \$beforeVersion,
                'rolled_back_to' => (string) \$afterTheme->get('Version'),
                'result' => \$result,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'security.integrity':
            require_once ABSPATH . 'wp-admin/includes/update.php';

            \$version = get_bloginfo('version');
            \$locale = get_locale();
            \$checksums = get_core_checksums(\$version, \$locale);
            \$fallbackChecksums = \$locale !== 'en_US' ? get_core_checksums(\$version, 'en_US') : [];

            if (!is_array(\$checksums) || \$checksums === []) {
                throw new RuntimeException('Core checksums could not be fetched for local integrity verification.');
            }

            \$issues = [];
            foreach (\$checksums as \$relativePath => \$expectedHash) {
                if (!is_string(\$relativePath) || str_starts_with(\$relativePath, 'wp-content/')) {
                    continue;
                }

                \$absolutePath = ABSPATH . str_replace('/', DIRECTORY_SEPARATOR, (string) \$relativePath);
                if (!is_file(\$absolutePath)) {
                    \$issues[] = ['file' => \$relativePath, 'status' => 'missing'];
                    continue;
                }

                \$actualHash = hash_file('md5', \$absolutePath);
                \$acceptableHashes = [(string) \$expectedHash];
                if (is_array(\$fallbackChecksums) && isset(\$fallbackChecksums[\$relativePath])) {
                    \$acceptableHashes[] = (string) \$fallbackChecksums[\$relativePath];
                }

                if (!in_array(\$actualHash, \$acceptableHashes, true)) {
                    \$issues[] = ['file' => \$relativePath, 'status' => 'modified'];
                }
            }

            echo json_encode([
                'issues' => \$issues,
                'issue_count' => count(\$issues),
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        case 'db.optimize':
            global \$wpdb;

            \$like = \$wpdb->esc_like(\$wpdb->prefix) . '%';
            \$tables = \$wpdb->get_col(\$wpdb->prepare('SHOW TABLES LIKE %s', \$like));
            if (!is_array(\$tables) || \$tables === []) {
                throw new RuntimeException('No tables matched the current WordPress table prefix.');
            }

            \$optimized = [];
            foreach (\$tables as \$table) {
                \$table = (string) \$table;
                \$wpdb->query("OPTIMIZE TABLE {\$table}");
                \$optimized[] = \$table;
            }

            echo json_encode([
                'message' => 'Database tables optimized locally.',
                'tables' => \$optimized,
            ], JSON_UNESCAPED_SLASHES);
            exit(0);

        default:
            throw new RuntimeException('Local dev execution does not support job type: ' . \$operation['type']);
    }
} catch (Throwable \$exception) {
    fwrite(STDERR, \$exception->getMessage());
    exit(1);
}
PHP;
    }

    /**
     * @param list<string> $command
     */
    private function stringifyCommand(array $command): string
    {
        $parts = [];

        foreach ($command as $part) {
            if ($part === '' || preg_match('/[\s"]/u', $part) === 1) {
                $parts[] = '"' . str_replace('"', '\"', $part) . '"';
                continue;
            }

            $parts[] = $part;
        }

        return implode(' ', $parts);
    }
}
