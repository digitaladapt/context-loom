<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Notify\DiscordClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guards the Discord webhook payload contract.
 *
 * Regression context (2026-09-18 live chunk test): the payload builder wrote
 * the chunk text into BOTH `content` and `embeds[0].description` (Discord
 * renders both — the text appeared twice), and every chunk after the first
 * sent `content: null` + `embeds: null` — rejected with 50006 "Cannot send
 * an empty message". A 5.4k-char message therefore delivered nothing at all.
 */
final class DiscordClientTest extends TestCase
{
    private const WEBHOOK = 'https://discord.example.com/api/webhooks/123/abc';

    public function test_single_chunk_message_carries_the_text_exactly_once(): void
    {
        $httpClient = $this->recordingHttpClient();
        $client = $this->client($httpClient);

        $run = $client->send(self::WEBHOOK, 'hello world', 'info');

        self::assertTrue($run->isComplete());
        self::assertSame('', $run->getStderr());
        self::assertCount(1, $httpClient->requests);

        $payload = self::payload($httpClient->requests[0]);

        self::assertArrayNotHasKey('content', $payload, 'the embed already carries the text — mirroring it into content duplicates it');
        self::assertSame('hello world', $payload['embeds'][0]['description']);
    }

    public function test_long_message_delivers_every_chunk_with_non_empty_payloads(): void
    {
        $httpClient = $this->recordingHttpClient();
        $client = $this->client($httpClient);

        $message = implode(' ', array_fill(0, 900, 'word')); // 4499 chars → 2 chunks
        $run = $client->send(self::WEBHOOK, $message, 'info');

        self::assertCount(2, $httpClient->requests);
        self::assertSame('', $run->getStderr());

        $descriptions = [];
        foreach ($httpClient->requests as $request) {
            $payload = self::payload($request);
            self::assertArrayNotHasKey('content', $payload);

            $description = $payload['embeds'][0]['description'] ?? null;
            self::assertIsString($description);
            self::assertNotSame('', $description, 'empty payloads are rejected by Discord (50006)');
            self::assertLessThanOrEqual(4096, mb_strlen($description));

            $descriptions[] = $description;
        }

        self::assertSame($message, implode(' ', $descriptions), 'chunk seams must reconstruct the original message losslessly');
        self::assertStringContainsString('(1/2)', self::payload($httpClient->requests[0])['embeds'][0]['title']);
        self::assertStringContainsString('(2/2)', self::payload($httpClient->requests[1])['embeds'][0]['title']);
    }

    public function test_chunking_without_word_boundaries_loses_no_characters(): void
    {
        $httpClient = $this->recordingHttpClient();
        $client = $this->client($httpClient);

        $message = str_repeat('x', 5000); // no spaces → hard split expected: 4096 + 904
        $run = $client->send(self::WEBHOOK, $message, 'info');

        self::assertCount(2, $httpClient->requests);
        self::assertSame('', $run->getStderr());

        $descriptions = array_map(
            static fn (RequestInterface $request): string => self::payload($request)['embeds'][0]['description'],
            $httpClient->requests,
        );

        self::assertSame([4096, 904], array_map('mb_strlen', $descriptions));
        self::assertSame($message, implode('', $descriptions));
    }

    public function test_embed_keeps_level_metadata(): void
    {
        $httpClient = $this->recordingHttpClient();
        $client = $this->client($httpClient);

        $fields = [
            ['name' => 'Level', 'value' => 'critical', 'inline' => true],
            ['name' => 'Source', 'value' => 'Context Loom', 'inline' => true],
        ];

        $client->send(self::WEBHOOK, 'boom', 'critical', 'Custom Bot', $fields);

        $payload = self::payload($httpClient->requests[0]);

        self::assertSame('Custom Bot', $payload['username']);
        self::assertSame(0xC0392B, $payload['embeds'][0]['color']);
        self::assertSame('Critical Notification', $payload['embeds'][0]['title']);
        self::assertSame($fields, $payload['embeds'][0]['fields']);
    }

    public function test_http_error_response_is_reported_as_stderr_chunk(): void
    {
        $httpClient = $this->recordingHttpClient(400, '{"message":"bad"}');
        $client = $this->client($httpClient);

        $run = $client->send(self::WEBHOOK, 'hi', 'info');

        self::assertStringContainsString('HTTP 400', $run->getStderr());
    }

    private static function payload(RequestInterface $request): array
    {
        return json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function client(ClientInterface $httpClient): DiscordClient
    {
        $factory = new Psr17Factory();

        return new DiscordClient($httpClient, $factory, $factory);
    }

    /**
     * Minimal PSR-18 test double that records every request it is sent and
     * answers with a realistic Discord webhook response (HTTP 204, no body).
     */
    private function recordingHttpClient(int $status = 204, string $body = ''): ClientInterface
    {
        return new class($status, $body) implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function __construct(
                private readonly int $status,
                private readonly string $body,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response($this->status, ['Content-Type' => 'application/json'], $this->body);
            }
        };
    }
}
