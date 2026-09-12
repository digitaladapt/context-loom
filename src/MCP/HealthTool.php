<?php

declare(strict_types=1);

namespace App\MCP;

use App\Service\HealthRegistry;

/**
 * The contextloom_health tool — the single health surface (SPEC D10).
 *
 * Phase 0: reports static, process-level health. Connectivity probing
 * (per-provider) is Phase 4; for now a static OK is honest.
 */
final class HealthTool
{
    public function __construct(
        private readonly HealthRegistry $registry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return $this->registry->snapshot();
    }
}
