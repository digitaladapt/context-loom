<?php

declare(strict_types=1);

namespace App\Service\Stream;

use App\Domain\Chunk;
use App\Domain\ChunkType;
use App\Domain\ToolRun;
use Mcp\Client\ClientGateway;

/**
 * Consumes a ToolRun stream and produces MCP output.
 *
 * SPEC §5.2: Handles both progress notifications and block sink fallback.
 * When a client sends a progressToken, pushes progress chunks via
 * ClientGateway::progress(). Otherwise, collects everything for a
 * CallToolResult.
 */
final class StreamSink
{
    /** @var list<Chunk> Collected chunks for block sink */
    private array $collected = [];

    private ?string $progressToken = null;

    private ?ClientGateway $clientGateway = null;

    /**
     * @param array{progress_token?: string, client?: ClientGateway}|null $options
     */
    public function __construct(?array $options = null)
    {
        if ($options) {
            $this->progressToken = $options['progress_token'] ?? null;
            $this->clientGateway = $options['client'] ?? null;
        }
    }

    /**
     * Feed a chunk into the sink.
     */
    public function push(Chunk $chunk): void
    {
        $this->collected[] = $chunk;

        // Push progress chunks to the client if we have a progressToken
        if ($chunk->type === ChunkType::PROGRESS && $this->clientGateway !== null && $this->progressToken !== null) {
            $progressText = $chunk->value;
            if (str_starts_with($progressText, 'progress:')) {
                $progressText = substr($progressText, 9);
            }
            $this->clientGateway->progress($this->progressToken, $progressText);
        }
    }

    /**
     * Complete the sink and produce the final result.
     *
     * @return array{content: list<array{type: string, text: string}>, isError: bool}|null
     */
    public function finish(): ?array
    {
        if (empty($this->collected)) {
            return null;
        }

        $content = [];
        $hasError = false;
        $stdout = '';
        $stderr = '';

        foreach ($this->collected as $chunk) {
            if ($chunk->type === ChunkType::STDOUT) {
                $stdout .= $chunk->value;
            } elseif ($chunk->type === ChunkType::STDERR) {
                $stderr .= $chunk->value;
            } elseif ($chunk->type === ChunkType::META && str_starts_with($chunk->value, 'exit_code:')) {
                // Just metadata, don't include in output
            }
        }

        if ($stderr !== '') {
            $hasError = true;
        }

        if ($stdout !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $stdout,
            ];
        }

        if ($stderr !== '') {
            $content[] = [
                'type' => 'text',
                'text' => "[stderr] {$stderr}",
            ];
        }

        if (empty($content)) {
            $content[] = [
                'type' => 'text',
                'text' => '(completed with no output)',
            ];
        }

        return [
            'content' => $content,
            'isError' => $hasError,
        ];
    }
}
