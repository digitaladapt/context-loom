<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Auth\ApiKeyAuthenticator;
use App\MCP\ContextLoomServer;
use Mcp\Server\Transport\StreamableHttpTransport;
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
 */
final class McpController
{
    public function __construct(
        private readonly ContextLoomServer $server,
        private readonly ApiKeyAuthenticator $apiKeyAuthenticator,
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function handle(Request $request): Response
    {
        $psrRequest = $this->toPsr7($request);

        $middleware = [
            ...StreamableHttpTransport::defaultMiddleware(),
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
