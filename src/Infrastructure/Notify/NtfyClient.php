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
 * Sends notifications via ntfy (ntfy.sh or self-hosted).
 *
 * SPEC §7.3: Level-aware routing, color/tag map.
 */
final class NtfyClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?string $ntfyUrl = null,
    ) {
    }

    /**
     * Send a notification.
     *
     * @param string               $topic        The ntfy topic
     * @param string               $message      The notification message
     * @param string               $level        One of: trace, debug, info, notice, warning, error, critical, emergency
     * @param array<string,string> $extraHeaders Optional extra headers
     * @param array<string,mixed>  $extraFields  Optional ntfy fields (priority, tags, etc.)
     */
    public function send(
        string $topic,
        string $message,
        string $level = 'info',
        array $extraHeaders = [],
        array $extraFields = [],
    ): ToolRun {
        $run = new ToolRun();

        // Level → ntfy priority map (1-5)
        $priorityMap = [
            'trace' => 1,
            'debug' => 2,
            'info' => 3,
            'notice' => 3,
            'warning' => 4,
            'error' => 4,
            'critical' => 5,
            'emergency' => 5,
        ];

        $priority = $priorityMap[$level] ?? 3;

        $fields = array_merge(['default' => $message], $extraFields);

        $body = json_encode([
            'topic' => $topic,
            'message' => $message,
            'priority' => $priority,
            'tags' => $extraFields['tags'] ?? $this->levelToTags($level),
            'fields' => $fields,
            'click' => $extraFields['click'] ?? null,
            'actions' => $extraFields['actions'] ?? null,
        ], \JSON_THROW_ON_ERROR);

        $url = rtrim($this->ntfyUrl, '/').'/'.$topic;

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'X-Notify-Priority' => (string) $priority,
        ], $extraHeaders);

        try {
            $request = $this->requestFactory->createRequest('POST', $url);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $request = $request->withBody($this->streamFactory->createStream($body));

            $response = $this->httpClient->sendRequest($request);
            $bodyText = (string) $response->getBody();

            $run->push(new Chunk(ChunkType::STDOUT, \sprintf('ntfy response: HTTP %d', $response->getStatusCode())));
            if ($response->getStatusCode() >= 400) {
                $run->push(new Chunk(ChunkType::STDERR, $bodyText));
            }
        } catch (\Throwable $e) {
            $run->push(new Chunk(ChunkType::STDERR, \sprintf('ntfy send error: %s', $e->getMessage())));
        }

        $run->complete();

        return $run;
    }

    /**
     * Map notification level to ntfy emoji tags.
     *
     * @return list<string>
     */
    private function levelToTags(string $level): array
    {
        $tagMap = [
            'trace' => ['white_question'],
            'debug' => ['debug'],
            'info' => ['information_source'],
            'notice' => ['bell'],
            'warning' => ['warning'],
            'error' => ['exclamation_mark'],
            'critical' => ['rotating_light'],
            'emergency' => ['scream'],
        ];

        return $tagMap[$level] ?? ['white_check_mark'];
    }
}
