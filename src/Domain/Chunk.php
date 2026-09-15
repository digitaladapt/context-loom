<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A single chunk in a ToolRun stream.
 *
 * SPEC §5.1: Every tool execution returns a stream of Chunk objects.
 * ChunkType determines the meaning of $value.
 */
final class Chunk
{
    public function __construct(
        public readonly ChunkType $type,
        public readonly string $value,
        public readonly ?array $meta = null,
    ) {
    }
}
