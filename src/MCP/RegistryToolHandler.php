<?php

declare(strict_types=1);

namespace App\MCP;

use App\Domain\RegistryEntry;
use App\Service\NotifyService;
use App\Service\Stream\StreamSink;
use App\Service\ToolExecutor;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * MCP tool handler for registry entries — implements ToolHandlerInterface so
 * the SDK's ExplicitElementLoader passes the raw argument bag to execute().
 *
 * Returns a real CallToolResult (not a raw array) so the SDK honors the
 * isError flag instead of JSON-string-wrapping the payload. SPEC §5.2.
 */
final class RegistryToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly RegistryEntry $entry,
        private readonly ToolExecutor $toolExecutor,
        private readonly NotifyService $notifyService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        // Strip SDK-injected internals (CallToolHandler adds _session/_request)
        // before argument validation and dispatch.
        unset($arguments['_session'], $arguments['_request']);

        $this->logger->info('MCP tool call.', ['tool' => $this->entry->name, 'arguments' => $arguments]);

        // Special handling for notify_send (uses NotifyService)
        if ('notify_send' === $this->entry->name) {
            return $this->sendNotification($arguments);
        }

        // Validate arguments
        $errors = $this->entry->validateArguments($arguments);
        if (!empty($errors)) {
            return CallToolResult::error([
                new TextContent('Validation errors: '.implode('; ', $errors)),
            ]);
        }

        return $this->toResult($this->toolExecutor->execute($this->entry, $arguments), '(completed)');
    }

    private function sendNotification(array $arguments): CallToolResult
    {
        // message/channels validated as required by the SDK's inputSchema
        // check before execute() runs; level is optional with a default.
        $message = $arguments['message'] ?? 'No message provided';
        $channels = $arguments['channels'] ?? [];
        $level = $arguments['level'] ?? 'info';
        $options = [];

        foreach ($this->entry->args as $arg) {
            if (\array_key_exists($arg->name, $arguments)) {
                $options[$arg->name] = $arguments[$arg->name];
            }
        }

        return $this->toResult(
            $this->notifyService->send($message, $channels, $level, $options),
            'Notification sent.',
        );
    }

    /**
     * Drain a ToolRun through the block StreamSink and wrap the result in a
     * CallToolResult so the SDK passes isError through to the MCP response.
     */
    private function toResult(\App\Domain\ToolRun $toolRun, string $fallback): CallToolResult
    {
        $sink = new StreamSink();
        foreach ($toolRun->getChunks() as $chunk) {
            $sink->push($chunk);
        }

        $result = $sink->finish();

        if (null === $result) {
            return new CallToolResult([new TextContent($fallback)], isError: false);
        }

        return new CallToolResult(
            content: array_map(
                static fn (array $item): Content => new TextContent($item['text']),
                $result['content'],
            ),
            isError: $result['isError'],
        );
    }
}
