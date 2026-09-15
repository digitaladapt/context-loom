<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\Chunk;
use App\Domain\ChunkType;
use App\Domain\RegistryEntry;
use App\Domain\ToolRun;

/**
 * Executes a `type: internal` registry entry.
 *
 * SPEC §4: Internal entries reference a PHP callable.
 * For v0.1, this is a simple invokable service.
 */
final class InternalExecutor implements ToolExecutorInterface
{
    /** @var array<string, object> */
    private array $handlers = [];

    /**
     * Register a handler for a specific entry name.
     */
    public function registerHandler(string $entryName, object $handler): void
    {
        $this->handlers[$entryName] = $handler;
    }

    public function supports(string $type): bool
    {
        return $type === 'internal';
    }

    public function execute(RegistryEntry $entry, array $arguments): ToolRun
    {
        $run = new ToolRun();

        $handler = $this->handlers[$entry->name] ?? null;
        if ($handler === null) {
            $run->push(new Chunk(ChunkType::STDERR, "No handler registered for: {$entry->name}"));
            $run->complete();

            return $run;
        }

        if (!is_callable([$handler, '__invoke'])) {
            $run->push(new Chunk(ChunkType::STDERR, "Handler for {$entry->name} is not callable."));
            $run->complete();

            return $run;
        }

        try {
            $result = $handler(...$arguments);
            $output = \is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $run->push(new Chunk(ChunkType::STDOUT, $output));
        } catch (\Throwable $e) {
            $run->push(new Chunk(ChunkType::STDERR, $e->getMessage()));
        }

        $run->complete();

        return $run;
    }
}
