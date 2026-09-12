<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API-key bearer auth for the MCP /mcp endpoint (SPEC §3.1: API key bearer,
 * same philosophy as mcp-server; OAuth 2.1 proxy mode is §9.2, v2.0).
 *
 * We implement a small PSR-15 middleware rather than the SDK's
 * AuthorizationMiddleware: that one is OAuth-shaped (requires resource
 * metadata + scopes). This keeps Phase 0 simple and matches the spec's
 * default `apikey` mode. When OAuth arrives (v2.0) we swap to the SDK's
 * validator path.
 */
final class ApiKeyAuthenticator implements MiddlewareInterface
{
    public const HEADER = 'Authorization';
    public const SCHEME = 'Bearer';

    public function __construct(
        private readonly string $apiKey,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine(self::HEADER);
        if ('' === $header || !str_starts_with($header, self::SCHEME.' ')) {
            return $this->unauthorized();
        }

        $token = trim(substr($header, \strlen(self::SCHEME)));
        if ('' === $this->apiKey || !hash_equals($this->apiKey, $token)) {
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(401)
            ->withHeader('WWW-Authenticate', self::SCHEME.' realm="context-loom"');
    }
}
