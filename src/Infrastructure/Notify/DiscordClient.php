<?php

declare(strict_types=1);

namespace App\Infrastructure\Notify;

use App\Domain\Chunk;
use App\Domain\ChunkType;
use App\Domain\ToolRun;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends notifications via Discord webhooks.
 *
 * SPEC §7.3: Level-aware routing, chunking at 4096 char limit,
 * color/tag map.
 */
final class DiscordClient
{
    // Discord embed color codes by level
    private const COLOR_MAP = [
        'trace' => 0x95A5A6,      // Gray
        'debug' => 0x95A5A6,      // Gray
        'info' => 0x3498DB,       // Blue
        'notice' => 0x2ECC71,     // Green
        'warning' => 0xF39C12,    // Yellow
        'error' => 0xE74C3C,      // Red
        'critical' => 0xC0392B,   // Dark Red
        'emergency' => 0x8E44AD,  // Purple
    ];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Send a notification to a Discord webhook.
     *
     * @param string $webhookUrl The Discord webhook URL
     * @param string $message The notification message (will be chunked if > 4096)
     * @param string $level Notification level
     * @param string $username Optional override for the bot username
     * @param array<string,mixed> $embedFields Optional embed fields
     */
    public function send(
        string $webhookUrl,
        string $message,
        string $level = 'info',
        ?string $username = null,
        array $embedFields = [],
    ): ToolRun {
        $run = new ToolRun();

        // Chunk the message at 4096 chars (Discord limit)
        $chunks = $this->chunkMessage($message, 4096);

        $color = self::COLOR_MAP[$level] ?? 0x3498DB;
        $username = $username ?? 'Context Loom';

        foreach ($chunks as $index => $chunk) {
            $payload = [
                'content' => $index === 0 ? $chunk : null,
                'embeds' => $index === 0 ? [[
                    'title' => ucfirst($level) . ' Notification',
                    'description' => $index === 0 ? $chunk : '...',
                    'color' => $color,
                    'fields' => empty($embedFields) ? null : $embedFields,
                    'timestamp' => date(\DateTimeImmutable::ATOM),
                    'footer' => [
                        'text' => 'Context Loom',
                    ],
                ]] : null,
                'username' => $username,
            ];

            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            try {
                $request = $this->requestFactory->createRequest('POST', $webhookUrl);
                $request = $request->withHeader('Content-Type', 'application/json');
                $request = $request->withBody($this->streamFactory->createStream($body));

                $response = $this->httpClient->sendRequest($request);

                if ($response->getStatusCode() >= 400) {
                    $responseBody = (string) $response->getBody();
                    $run->push(new Chunk(ChunkType::STDERR, \sprintf('Discord chunk %d: HTTP %d - %s', $index + 1, $response->getStatusCode(), $responseBody)));
                } else {
                    $run->push(new Chunk(ChunkType::STDOUT, \sprintf('Discord chunk %d sent (HTTP %d)', $index + 1, $response->getStatusCode())));
                }
            } catch (\Throwable $e) {
                $run->push(new Chunk(ChunkType::STDERR, \sprintf('Discord chunk %d error: %s', $index + 1, $e->getMessage())));
            }
        }

        $run->complete();

        return $run;
    }

    /**
     * Split a message into chunks of at most $maxChars characters,
     * breaking at word boundaries when possible.
     *
     * @return list<string>
     */
    private function chunkMessage(string $message, int $maxChars): array
    {
        if (mb_strlen($message) <= $maxChars) {
            return [$message];
        }

        $chunks = [];
        $remaining = $message;

        while (mb_strlen($remaining) > $maxChars) {
            // Find the last space within the limit
            $chunk = mb_substr($remaining, 0, $maxChars);
            $lastSpace = strrpos($chunk, ' ');

            if ($lastSpace !== false) {
                $chunk = mb_substr($chunk, 0, $lastSpace);
            }

            $chunks[] = rtrim($chunk);
            $remaining = mb_substr($remaining, mb_strlen($chunk) + 1);
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }
}
