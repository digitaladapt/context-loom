<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Defines how a tool's output is formatted and transported.
 *
 * SPEC §4.3: output and streaming are separate from type.
 * output = what the data looks like; streaming = how the transport forwards it.
 */
final class OutputSpec
{
    public function __construct(
        public readonly string $mode,
        public readonly string $format,
    ) {
        if (!\in_array($this->mode, ['block', 'stream'], true)) {
            throw new \InvalidArgumentException(
                \sprintf('OutputSpec mode must be "block" or "stream", got "%s".', $this->mode)
            );
        }
        if (!\in_array($this->format, ['json', 'text', 'xml', 'csv'], true)) {
            throw new \InvalidArgumentException(
                \sprintf('OutputSpec format must be "json", "text", "xml", or "csv", got "%s".', $this->format)
            );
        }
    }
}
