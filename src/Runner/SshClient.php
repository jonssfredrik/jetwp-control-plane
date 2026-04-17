<?php

declare(strict_types=1);

namespace JetWP\Control\Runner;

use JetWP\Control\Models\Server;
use RuntimeException;

final class SshClient
{
    public function __construct(private readonly string $sshBinary = 'ssh')
    {
    }

    public function testConnection(Server $server, int $timeoutSeconds = 10): ExecutionResult
    {
        return $this->execute($server, 'whoami', $timeoutSeconds);
    }

    public function execute(Server $server, string $remoteCommand, int $timeoutSeconds = 300): ExecutionResult
    {
        $target = sprintf('%s@%s', $server->sshUser, $server->hostname);
        $command = [
            $this->sshBinary,
            '-i', $server->sshKeyPath,
            '-p', (string) $server->sshPort,
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=NUL',
            '-o', 'LogLevel=ERROR',
            '-o', 'ConnectTimeout=' . max(1, $timeoutSeconds),
            $target,
            $remoteCommand,
        ];

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $start = microtime(true);
        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start ssh process.');
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
                        'SSH command timed out after %d seconds.',
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
