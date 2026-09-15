<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Chunk;
use App\Domain\ChunkType;
use App\Domain\RegistryEntry;
use App\Domain\ToolRun;
use App\Infrastructure\Notify\DiscordClient;
use App\Infrastructure\Notify\NtfyClient;
use Psr\Log\LoggerInterface;

/**
 * Notify service: level-aware routing between ntfy + Discord.
 *
 * SPEC §7.3: Port notify_service.py behavior — level-aware routing,
 * per-level webhook/topic fallback, chunking (Discord 4096), color/tag map.
 */
final class NotifyService
{
    public function __construct(
        private readonly ?NtfyClient $ntfyClient = null,
        private readonly ?DiscordClient $discordClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Send a notification through configured channels.
     *
     * @param string $message The notification message
     * @param array<string> $channels Channels to send to (e.g. ['ntfy', 'discord'])
     * @param string $level Notification level
     * @param array<string,mixed> $options Channel-specific options
     */
    public function send(
        string $message,
        array $channels = ['ntfy', 'discord'],
        string $level = 'info',
        array $options = [],
    ): ToolRun {
        $run = new ToolRun();

        foreach ($channels as $channel) {
            if ($channel === 'ntfy' && $this->ntfyClient !== null) {
                $topic = $options['ntfy_topic'] ?? $_ENV['NTFY_TOPIC'] ?? 'general';
                $ntfyUrl = $options['ntfy_url'] ?? $_ENV['NTFY_URL'] ?? 'https://ntfy.sh';

                $extraFields = [
                    'level' => $level,
                    'tags' => ['notification', 'context-loom'],
                ];

                if (!empty($options['ntfy_username'])) {
                    $extraFields['username'] = $options['ntfy_username'];
                }

                $result = $this->ntfyClient->send(
                    $topic,
                    $message,
                    $level,
                    [],
                    $extraFields
                );

                foreach ($result->getChunks() as $chunk) {
                    $run->push($chunk);
                }

                $this->logger?->info('Notification sent via ntfy.', [
                    'topic' => $topic,
                    'level' => $level,
                ]);
            }

            if ($channel === 'discord' && $this->discordClient !== null) {
                $webhookUrl = $options['discord_webhook'] ?? $_ENV['DISCORD_WEBHOOK'] ?? '';

                if ($webhookUrl === '') {
                    $run->push(new Chunk(ChunkType::STDERR, 'No Discord webhook URL configured.'));
                    continue;
                }

                $embedFields = [
                    ['name' => 'Level', 'value' => $level, 'inline' => true],
                    ['name' => 'Source', 'value' => 'Context Loom', 'inline' => true],
                ];

                $result = $this->discordClient->send(
                    $webhookUrl,
                    $message,
                    $level,
                    $options['discord_username'] ?? null,
                    $embedFields
                );

                foreach ($result->getChunks() as $chunk) {
                    $run->push($chunk);
                }

                $this->logger?->info('Notification sent via Discord.', [
                    'level' => $level,
                ]);
            }
        }

        $run->complete();

        return $run;
    }
}
