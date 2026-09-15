<?php

declare(strict_types=1);

namespace App\MCP;

use App\Domain\InputSpec;
use App\Domain\RegistryEntry;
use App\Domain\ToolRun;
use App\Service\HealthRegistry;
use App\Service\NotifyService;
use App\Service\Registry\Registry;
use App\Service\Stream\StreamSink;
use App\Service\ToolExecutor;
use Mcp\Server\Server;
use Mcp\Server\Session\ServerSession;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Client\ClientGateway;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Context Loom MCP server — wires mcp/sdk, registers registry tools, and the health tool.
 *
 * SPEC §3.1 + §11.1.3: Streamable HTTP /mcp endpoint. Every registry entry
 * is registered as a native MCP tool. contextloom_health is always present.
 */
final class ContextLoomServer
{
    private readonly Server $server;

    public function __construct(
        private readonly HealthRegistry $healthRegistry,
        private readonly ToolExecutor $toolExecutor,
        private readonly Registry $registry,
        private readonly ToolFactory $toolFactory,
        private readonly NotifyService $notifyService,
        private readonly LoggerInterface $logger,
        string $sessionDir,
    ) {
        $this->server = $this->build($sessionDir);
    }

    /**
     * Handle a single MCP request.
     *
     * @param iterable<\Psr\Http\Server\MiddlewareInterface>|null $middleware
     */
    public function handle(ServerRequestInterface $request, ?iterable $middleware = null): ResponseInterface
    {
        $transport = new StreamableHttpTransport(
            request: $request,
            logger: $this->logger,
            middleware: $middleware,
        );

        return $this->server->run($transport);
    }

    /**
     * Get the current list of registered tool names (for validation + health).
     *
     * @return list<string>
     */
    public function getToolNames(): array
    {
        $tools = ['contextloom_health'];
        foreach ($this->registry->getEntries() as $entry) {
            $tools[] = $entry->name;
        }

        return $tools;
    }

    private function build(string $sessionDir): Server
    {
        $builder = Server::builder()
            ->setServerInfo('context-loom', '0.1.0-dev')
            ->setSession(new \Mcp\Server\Session\FileSessionStore($sessionDir, ttl: 3600))
            // contextloom_health tool — always present, no-input
            ->addTool(
                handler: function (): array {
                    return (new HealthTool($this->healthRegistry))();
                },
                name: 'contextloom_health',
                title: 'Context Loom health',
                description: 'Reports Context Loom server health: overall status and per-provider connectivity. Use this to check that the server and its backends are reachable.',
                inputSchema: ['type' => 'object'],
            );

        // Register all registry entries as MCP tools
        foreach ($this->registry->getEntries() as $entry) {
            $toolDef = $this->toolFactory->toToolDefinition($entry);

            $builder = $builder->addTool(
                handler: function (array $arguments = []) use ($entry) {
                    return $this->handleToolCall($entry, $arguments);
                },
                name: $toolDef['name'],
                title: $toolDef['title'],
                description: $toolDef['description'],
                inputSchema: $toolDef['inputSchema'],
            );
        }

        return $builder->build();
    }

    /**
     * Handle a tool call for a registry entry.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function handleToolCall(RegistryEntry $entry, array $arguments): array
    {
        $this->logger->info('MCP tool call.', ['tool' => $entry->name, 'arguments' => $arguments]);

        // Special handling for notify_send (uses NotifyService)
        if ($entry->name === 'notify_send') {
            return $this->handleNotifySend($entry, $arguments);
        }

        // Validate arguments
        $errors = $entry->validateArguments($arguments);
        if (!empty($errors)) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Validation errors: ' . implode('; ', $errors),
                ]],
                'isError' => true,
            ];
        }

        // Execute the tool
        $toolRun = $this->toolExecutor->execute($entry, $arguments);

        // Convert ToolRun to MCP result via StreamSink
        $sink = new StreamSink();
        foreach ($toolRun->getChunks() as $chunk) {
            $sink->push($chunk);
        }

        $result = $sink->finish();

        return $result ?? [
            'content' => [['type' => 'text', 'text' => '(completed)']],
            'isError' => false,
        ];
    }

    /**
     * Handle the special notify_send tool.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function handleNotifySend(RegistryEntry $entry, array $arguments): array
    {
        $message = $arguments['message'] ?? 'No message provided';
        $channels = $arguments['channels'] ?? [];
        $level = $arguments['level'] ?? 'info';
        $options = [];

        // Extract channel-specific options
        foreach ($entry->args as $arg) {
            if (isset($arguments[$arg->name])) {
                $options[$arg->name] = $arguments[$arg->name];
            }
        }

        $toolRun = $this->notifyService->send($message, $channels, $level, $options);

        $sink = new StreamSink();
        foreach ($toolRun->getChunks() as $chunk) {
            $sink->push($chunk);
        }

        $result = $sink->finish();

        return $result ?? [
            'content' => [['type' => 'text', 'text' => 'Notification sent.']],
            'isError' => false,
        ];
    }
}
