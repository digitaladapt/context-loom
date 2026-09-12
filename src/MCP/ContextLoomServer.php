<?php

declare(strict_types=1);

namespace App\MCP;

use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Context Loom MCP server — wires the official mcp/sdk builder and exposes
 * the registry tools (v0.1: precisely contextloom_health).
 *
 * The SDK is built once (tool definitions + handlers) and the transport is
 * per-request, so one server object serves every /mcp call.
 *
 * Sessions: the PHP built-in server re-executes the front controller per
 * request (no shared memory), so the default in-memory session store does not
 * survive between requests. We use the SDK's file-based store. In production
 * (FrankenPHP worker mode / multiple HTTP workers) the same store stays
 * correct — files are shared. Modern-era clients that skip the handshake
 * (SEP-2575, 2026-07-28) use the stateless dispatcher and need no session.
 *
 * Handler convention (verified in mcp/sdk 0.8.x ReferenceHandler): an
 * addTool() closure receives named parameters matching the tool's declared
 * input fields; SDK-injectable types (ClientGateway, RequestContext) are
 * resolved from the parameters. A tool with no inputs takes no arguments —
 * a required `array $arguments` parameter would be treated as a missing
 * tool input named "arguments".
 */
final class ContextLoomServer
{
    private readonly Server $server;

    public function __construct(
        private readonly HealthTool $healthTool,
        private readonly LoggerInterface $logger,
        string $sessionDir,
    ) {
        $this->server = $this->build($sessionDir);
    }

    /**
     * @param iterable<\Psr\Http\Server\MiddlewareInterface>|null $middleware
     */
    public function handle(ServerRequestInterface $request, ?iterable $middleware = null): ResponseInterface
    {
        $transport = new Server\Transport\StreamableHttpTransport(
            request: $request,
            logger: $this->logger,
            middleware: $middleware,
        );

        return $this->server->run($transport);
    }

    private function build(string $sessionDir): Server
    {
        return Server::builder()
            ->setServerInfo('context-loom', '0.1.0-dev')
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                resources: false,
                prompts: false,
                logging: false,
            ))
            ->setSession(new FileSessionStore($sessionDir, ttl: 3600))
            ->addTool(
                handler: function (): array {
                    return ($this->healthTool)();
                },
                name: 'contextloom_health',
                title: 'Context Loom health',
                description: 'Reports Context Loom server health: overall status and per-provider connectivity. Use this to check that the server and its backends are reachable.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            )
            ->build();
    }
}
