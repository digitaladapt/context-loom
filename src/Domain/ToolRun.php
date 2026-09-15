<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A stream of Chunk objects produced by a tool execution.
 *
 * SPEC §5.1: ToolRun is the core abstraction — every executor produces one.
 * The executor pushes chunks; the MCP layer consumes them.
 */
final class ToolRun implements \IteratorAggregate, \Countable
{
    /** @var list<Chunk> */
    private array $chunks = [];

    private bool $isComplete = false;

    public function push(Chunk $chunk): void
    {
        if ($this->isComplete) {
            throw new \RuntimeException('ToolRun is complete; no more chunks.');
        }
        $this->chunks[] = $chunk;
    }

    public function complete(): void
    {
        $this->isComplete = true;
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    /** @return list<Chunk> */
    public function getChunks(): array
    {
        return $this->chunks;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->chunks);
    }

    public function count(): int
    {
        return \count($this->chunks);
    }

    /**
     * Extract the final stdout value from completed chunks.
     */
    public function getFinalOutput(): string
    {
        if (!$this->isComplete) {
            throw new \RuntimeException('ToolRun is not complete.');
        }
        $output = '';
        foreach ($this->chunks as $chunk) {
            if (ChunkType::STDOUT === $chunk->type) {
                $output .= $chunk->value;
            }
        }

        return $output;
    }

    /**
     * Extract stderr output.
     */
    public function getStderr(): string
    {
        $output = '';
        foreach ($this->chunks as $chunk) {
            if (ChunkType::STDERR === $chunk->type) {
                $output .= $chunk->value;
            }
        }

        return $output;
    }
}
