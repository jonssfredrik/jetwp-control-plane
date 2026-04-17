<?php

declare(strict_types=1);

namespace JetWP\Control\Runner;

final class ExecutionResult
{
    public function __construct(
        public readonly string $command,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
        public readonly int $durationMs,
        public readonly bool $timedOut = false,
        public readonly bool $dryRun = false,
    ) {
    }

    public static function dryRun(string $command): self
    {
        return new self(
            command: $command,
            stdout: '',
            stderr: '',
            exitCode: 0,
            durationMs: 0,
            timedOut: false,
            dryRun: true,
        );
    }

    public function successful(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }
}
