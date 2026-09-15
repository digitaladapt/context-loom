<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\InputSpec;
use App\Domain\RegistryEntry;
use App\Domain\ToolRun;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Executes a `type: http` registry entry.
 *
 * SPEC D13: HTTP executor — method, URL template, headers, auth, params, body.
 * Response flows back as chunks to the ToolRun stream. PSR-18 only.
 */
final class HttpExecutor implements ToolExecutorInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'http' === $type;
    }

    public function execute(RegistryEntry $entry, array $arguments): ToolRun
    {
        $run = new ToolRun();

        // Resolve the URL template
        $urlTemplate = $entry->http['url'] ?? '';
        $url = $entry->resolveTemplate($urlTemplate);

        // Map args to query params
        $queryParams = [];
        if (\is_array($entry->http['params'] ?? null)) {
            foreach ($entry->http['params'] as $paramName) {
                $spec = $this->findArgSpec($entry, $paramName);
                $fieldName = $spec?->field_name ?? $paramName;

                if (isset($arguments[$fieldName])) {
                    $queryParams[$paramName] = $spec?->cast($arguments[$fieldName]) ?? $arguments[$fieldName];
                }
            }
        }

        // Build query string
        if (!empty($queryParams)) {
            $url .= '?'.http_build_query($queryParams);
        }

        // Build headers
        $headers = [];
        if (\is_array($entry->http['headers'] ?? null)) {
            foreach ($entry->http['headers'] as $name => $value) {
                $headers[$name] = $entry->resolveTemplate((string) $value);
            }
        }

        // Apply auth
        $auth = $entry->http['auth'] ?? null;
        if (null !== $auth && 'none' !== $auth) {
            $headers = $this->addAuth($headers, $entry);
        }

        // Build request method
        $method = strtoupper($entry->http['method'] ?? 'GET');

        // Build request body
        $body = null;
        $bodyContent = $entry->http['body'] ?? null;
        if (null !== $bodyContent) {
            $body = $this->streamFactory->createStream(
                $entry->resolveTemplate((string) $bodyContent)
            );
        } elseif ('POST' === $method || 'PUT' === $method || 'PATCH' === $method) {
            // Send empty body for POST/PUT/PATCH if no body specified
            $body = $this->streamFactory->createStream('');
        }

        // Execute
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (null !== $body) {
            $request = $request->withBody($body);
        }

        $timeout = (int) ($entry->http['timeout'] ?? 30);
        $request = $request->withHeader('X-ContextLoom-Timeout', (string) $timeout);

        try {
            $response = $this->httpClient->sendRequest($request);

            // Push response as stdout chunk
            $responseBody = (string) $response->getBody();
            $run->push(new \App\Domain\Chunk(\App\Domain\ChunkType::STDOUT, $responseBody));

            if ($response->getStatusCode() >= 400) {
                $run->push(new \App\Domain\Chunk(
                    \App\Domain\ChunkType::META,
                    \sprintf('status_code:%d', $response->getStatusCode()),
                    ['status_code' => $response->getStatusCode()]
                ));
            }
        } catch (\Throwable $e) {
            // Connection error
            $run->push(new \App\Domain\Chunk(
                \App\Domain\ChunkType::STDERR,
                \sprintf('HTTP error: %s', $e->getMessage())
            ));
        }

        $run->complete();

        return $run;
    }

    private function findArgSpec(RegistryEntry $entry, string $name): ?InputSpec
    {
        foreach ($entry->args as $arg) {
            if ($arg->name === $name || $arg->field_name === $name) {
                return $arg;
            }
        }

        return null;
    }

    private function addAuth(array $headers, RegistryEntry $entry): array
    {
        $auth = $entry->http['auth'];
        $apiKey = null;
        $username = null;
        $password = null;

        switch ($auth) {
            case 'api_key':
                $apiKey = $entry->http['api_key'] ?? $_ENV[$entry->http['api_key_env'] ?? ''] ?? '';
                $headerName = $entry->http['api_key_header'] ?? 'X-API-Key';
                $in = $entry->http['api_key_in'] ?? 'header';
                if ('header' === $in) {
                    $prefix = $entry->http['api_key_prefix'] ?? '';
                    $headers['Authorization'] = '' !== $prefix
                        ? $prefix.' '.$apiKey
                        : $apiKey;
                }
                // Will be handled in query params above
                // For now, store for later

                break;

            case 'bearer':
                $token = $entry->http['api_key'] ?? $_ENV[$entry->http['api_key_env'] ?? ''] ?? '';
                $headers['Authorization'] = 'Bearer '.$token;
                break;

            case 'basic':
                $username = $entry->http['api_key'] ?? '';
                $password = $entry->http['api_key_secret'] ?? '';
                $headers['Authorization'] = 'Basic '.base64_encode($username.':'.$password);
                break;
        }

        return $headers;
    }
}
