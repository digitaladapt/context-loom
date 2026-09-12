<?php

declare(strict_types=1);

namespace App\Service\HealthRegistry;

/**
 * @internal value object: one provider's sampled health state
 */
final class ProviderStatus
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $detail = null,
        public readonly ?\DateTimeImmutable $checkedAt = null,
    ) {
    }
}
