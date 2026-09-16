<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Notify\NtfyClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guards ntfy token authentication (NTFY_TOKEN → Authorization: Bearer).
 *
 * The homelab ntfy server runs with auth-default-access: deny-all, so a
 * publish without credentials is rejected — the exact failure this pins.
 */
final class NtfyClientTest extends TestCase
{
    public function test_sends_bearer_authorization_header_when_token_configured(): void
    {
        $httpClient = $this->recordingHttpClient();
        $factory = new Psr17Factory();

        $client = new NtfyClient($httpClient, $factory, $factory, 'https://ntfy.example.com', 'tk_testtoken123');
        $run = $client->send('testtopic', 'hello', 'info');

        self::assertTrue($run->isComplete());
        self::assertCount(1, $httpClient->requests);

        $request = $httpClient->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('Bearer tk_testtoken123', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('', $run->getStderr(), 'a 200 response must not produce stderr chunks');
    }

    public function test_sends_no_authorization_header_without_token(): void
    {
        $httpClient = $this->recordingHttpClient();
        $factory = new Psr17Factory();

        // null (env unset) — the pre-existing anonymous-publish path.
        $client = new NtfyClient($httpClient, $factory, $factory, 'https://ntfy.sh', null);
        $client->send('testtopic', 'hello', 'info');

        $request = $httpClient->requests[0];
        self::assertFalse($request->hasHeader('Authorization'), 'no token → no Authorization header');
    }

    public function test_blank_token_is_treated_as_unset(): void
    {
        $httpClient = $this->recordingHttpClient();
        $factory = new Psr17Factory();

        $client = new NtfyClient($httpClient, $factory, $factory, 'https://ntfy.sh', '  ');
        $client->send('testtopic', 'hello', 'info');

        self::assertFalse($httpClient->requests[0]->hasHeader('Authorization'), 'whitespace-only token must not produce a header');
    }

    public function test_token_whitespace_is_trimmed(): void
    {
        $httpClient = $this->recordingHttpClient();
        $factory = new Psr17Factory();

        $client = new NtfyClient($httpClient, $factory, $factory, 'https://ntfy.sh', " tk_testtoken123\n");
        $client->send('testtopic', 'hello', 'info');

        self::assertSame('Bearer tk_testtoken123', $httpClient->requests[0]->getHeaderLine('Authorization'));
    }

    /**
     * Minimal PSR-18 test double that records every request it is sent and
     * answers with a realistic ntfy publish response (HTTP 200 + JSON).
     */
    private function recordingHttpClient(): ClientInterface
    {
        return new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], '{"id":"test-message-id"}');
            }
        };
    }
}
