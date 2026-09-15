<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Defines how a registry entry is probed for connectivity.
 *
 * SPEC §6.3: Per-protocol probes with method, URL, timeout.
 */
final class ProbeSpec
{
    public function __construct(
        public readonly string $level,
        public readonly string $method,
        public readonly string $url,
        public readonly int $timeout,
    ) {
    }
}
