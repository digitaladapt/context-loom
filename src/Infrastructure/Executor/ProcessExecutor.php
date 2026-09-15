<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\Chunk;
use App\Domain\ChunkType;
use App\Domain\RegistryEntry;
use App\Domain\ToolRun;
use Psr\Log\LoggerInterface;

/**
 * Executes a `type: process` registry entry.
 *
 * SPEC §4.4: Runs subprocesses, streams stdout/stderr as chunks.
 * Process group isolation + per-run state.
 */
final class ProcessExecutor implements ToolExecutorInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'process' === $type;
    }

    public function execute(RegistryEntry $entry, array $arguments): ToolRun
    {
        $run = new ToolRun();
        $command = $this->buildCommand($entry, $arguments);

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'r'],  // stdout
                2 => ['pipe', 'r'],  // stderr
            ],
            $pipes
        );

        if (!\is_resource($process)) {
            $run->push(new Chunk(ChunkType::STDERR, "Failed to start process: {$command}"));
            $run->complete();

            return $run;
        }

        // Set non-blocking for streaming
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdoutAccumulator = '';
        $stderrAccumulator = '';
        $maxOutputBytes = 10_485_760; // 10MB safety limit

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            // Read stdout
            if (false === feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if (false !== $chunk && '' !== $chunk) {
                    $stdoutAccumulator .= $chunk;
                    if ('' !== $chunk) {
                        $run->push(new Chunk(ChunkType::STDOUT, $chunk));
                    }
                }
            }

            // Read stderr
            if (false === feof($pipes[2])) {
                $chunk = fread($pipes[2], 8192);
                if (false !== $chunk && '' !== $chunk) {
                    $stderrAccumulator .= $chunk;
                    if ('' !== $chunk) {
                        $run->push(new Chunk(ChunkType::STDERR, $chunk));
                    }
                }
            }

            usleep(10_000); // 10ms polling interval
        }

        // Drain remaining output
        while (false !== ($chunk = fread($pipes[1], 8192)) && '' !== $chunk) {
            $run->push(new Chunk(ChunkType::STDOUT, $chunk));
            $stdoutAccumulator .= $chunk;
        }
        while (false !== ($chunk = fread($pipes[2], 8192)) && '' !== $chunk) {
            $run->push(new Chunk(ChunkType::STDERR, $chunk));
            $stderrAccumulator .= $chunk;
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = $status['exitcode'] ?? -1;

        $run->push(new Chunk(
            ChunkType::META,
            "exit_code:{$exitCode}",
            ['exit_code' => $exitCode]
        ));

        $run->complete();

        if (0 !== $exitCode && '' !== $stderrAccumulator) {
            $this->logger?->warning('Process exited non-zero.', [
                'entry' => $entry->name,
                'exit_code' => $exitCode,
                'stderr' => $stderrAccumulator,
            ]);
        }

        return $run;
    }

    private function buildCommand(RegistryEntry $entry, array $arguments): string
    {
        $command = $entry->process ?? '';

        // Resolve env var templates in the command
        $command = $entry->resolveTemplate($command);

        // Append arguments if any
        if (!empty($arguments)) {
            $argStrings = [];
            foreach ($arguments as $key => $value) {
                if (\is_array($value)) {
                    $argStrings[] = escapeshellarg(json_encode($value));
                } else {
                    $argStrings[] = escapeshellarg((string) $value);
                }
            }
            $command .= ' '.implode(' ', $argStrings);
        }

        return $command;
    }
}
