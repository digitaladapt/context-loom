<?php

declare(strict_types=1);

namespace App\MCP;

use App\Service\HealthRegistry;
use App\Service\NotifyService;
use App\Service\Registry\Registry;
use App\Service\ToolExecutor;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
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
        // Load registry entries before building tools
        $this->registry->load();

        $builder = Server::builder()
            ->setServerInfo('context-loom', '0.1.0-dev')
            ->setSession(new Server\Session\FileSessionStore($sessionDir, ttl: 3600))
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

            $tool = new \Mcp\Schema\Tool(
                name: $toolDef['name'],
                title: $toolDef['title'],
                inputSchema: $toolDef['inputSchema'],
                description: $toolDef['description'],
                annotations: null,
            );

            $handler = new RegistryToolHandler(
                $entry,
                $this->toolExecutor,
                $this->notifyService,
                $this->logger,
            );

            $builder = $builder->add($tool, $handler);
        }

        return $builder->build();
    }
}
