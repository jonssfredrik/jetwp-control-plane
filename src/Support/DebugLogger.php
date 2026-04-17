<?php

declare(strict_types=1);

namespace JetWP\Control\Support;

final class DebugLogger
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $path,
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (!$this->enabled || trim($this->path) === '') {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $payload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode([
                'timestamp' => date('Y-m-d H:i:s'),
                'level' => 'error',
                'message' => 'Failed to encode debug log payload.',
            ], JSON_UNESCAPED_SLASHES) ?: '{"message":"Failed to encode debug log payload."}';
        }

        error_log($encoded . PHP_EOL, 3, $this->path);
    }
}
