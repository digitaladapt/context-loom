<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Result of a connectivity probe.
 *
 * SPEC §6.4: States are ok, unknown, degraded, down.
 */
final class ProbeResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?\DateTimeImmutable $checkedAt = null,
        public readonly ?string $error = null,
        public readonly ?array $detail = null,
    ) {
    }
}
