<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('health', '/health')
        ->controller([App\Controller\HealthController::class, 'index'])
        ->methods(['GET']);

    // MCP Streamable HTTP endpoint. POST /mcp carries the JSON-RPC payload
    // (and may answer text/event-stream). We deliberately do NOT implement the
    // legacy two-endpoint HTTP+SSE transport (GET /sse + POST /messages) —
    // it was deprecated 2025-03-26 (SPEC §3.1).
    $routes->add('mcp', '/mcp')
        ->controller([App\Controller\McpController::class, 'handle'])
        ->methods(['GET', 'POST', 'DELETE', 'OPTIONS']);
};
