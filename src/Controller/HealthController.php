<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /health — the single health surface (SPEC D10), REST side.
 * Same payload shape as the contextloom_health MCP tool.
 */
final class HealthController
{
    public function __construct(
        private readonly HealthRegistry $registry,
    ) {
    }

    public function index(): JsonResponse
    {
        return new JsonResponse($this->registry->snapshot());
    }
}
