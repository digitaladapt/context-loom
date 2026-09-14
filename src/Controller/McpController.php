<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Auth\ApiKeyAuthenticator;
use App\MCP\ContextLoomServer;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST /mcp — MCP Streamable HTTP transport.
 *
 * The SDK transport is per-request: give it a PSR-7 request, it returns the
 * PSR-7 response (which may be application/json or text/event-stream for a
 * long-running call). We bridge Symfony Request <-> PSR-7 and hand the result
 * back as a Symfony Response (D18: one process, one router, /mcp + /health).
 *
 * Auth: when CONTEXT_LOOM_AUTH=apikey (the default), the SDK transport's
 * default security middleware + our ApiKeyAuthenticator run at the edge.
 *
 * Host/Origin validation: the SDK's DNS-rebinding protection defaults to a
 * localhost-only allowlist, which 403s every request carrying a public
 * hostname (Host or Origin header) before auth even runs. On a server deployed
 * behind a reverse proxy under a real hostname, CONTEXT_LOOM_ALLOWED_HOSTS
 * (comma-separated, no ports; IPv6 bracketed) extends that allowlist.
 * Unset/empty keeps the SDK defaults — correct for the local dev loop.
 */
final class McpController
{
    /** @var list<string>|null null: SDK defaults (localhost variants) */
    private readonly ?array $allowedHosts;

    public function __construct(
        private readonly ContextLoomServer $server,
        private readonly ApiKeyAuthenticator $apiKeyAuthenticator,
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?string $allowedHostsCsv = null,
    ) {
        $hosts = null;

        if (null !== $allowedHostsCsv && '' !== trim($allowedHostsCsv)) {
            $hosts = array_values(array_filter(
                array_map('trim', explode(',', $allowedHostsCsv)),
                static fn (string $host): bool => '' !== $host,
            ));
        }

        $this->allowedHosts = [] === $hosts ? null : $hosts;
    }

    public function handle(Request $request): Response
    {
        $psrRequest = $this->toPsr7($request);

        // Same pipeline as StreamableHttpTransport::defaultMiddleware()
        // (CORS + DNS-rebinding protection), but with a configurable host
        // allowlist. Do NOT add ProtocolVersionMiddleware here: the transport
        // applies it to handshake-era traffic on its own (SDK 0.8.x warns
        // otherwise).
        $middleware = [
            new CorsMiddleware(),
            null === $this->allowedHosts
                ? new DnsRebindingProtectionMiddleware()
                : new DnsRebindingProtectionMiddleware($this->allowedHosts),
            $this->apiKeyAuthenticator,
        ];

        $psrResponse = $this->server->handle($psrRequest, $middleware);

        return $this->fromPsr7($psrResponse);
    }

    private function toPsr7(Request $request): \Psr\Http\Message\ServerRequestInterface
    {
        $psr = $this->serverRequestFactory->createServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->server->all(),
        );

        foreach ($request->headers->all() as $name => $values) {
            $psr = $psr->withHeader($name, $values);
        }

        $body = $request->getContent();
        if ('' !== $body) {
            $psr = $psr->withBody($this->streamFactory->createStream($body));
        }

        return $psr->withQueryParams($request->query->all());
    }

    private function fromPsr7(\Psr\Http\Message\ResponseInterface $psrResponse): Response
    {
        $status = $psrResponse->getStatusCode();
        $contentType = $psrResponse->getHeaderLine('Content-Type');
        $body = (string) $psrResponse->getBody();

        $response = str_contains($contentType, 'text/event-stream')
            ? new StreamedResponse()
            : new JsonResponse();

        $response->setStatusCode($status);
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->headers->set($name, $values);
        }

        if ($response instanceof StreamedResponse) {
            $response->setCallback(static function () use ($body): void {
                echo $body;
            });
        } else {
            $response->setContent($body);
        }

        return $response;
    }
}
