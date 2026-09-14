<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots the real Kernel and drives the MCP streamable HTTP endpoint through
 * the actual handshake + tools/call — the Phase 0 acceptance flow.
 */
final class McpFlowTest extends TestCase
{
    private const API_KEY = 'test-api-key-12345';

    /** Host allowlisted in .env.test for these tests (see CONTEXT_LOOM_ALLOWED_HOSTS). */
    private const ALLOWED_HOST = 'loom.example.com';

    private Kernel $kernel;

    protected function setUp(): void
    {
        $projectDir = \dirname(__DIR__, 2);

        // Test env loads .env.test (standard Symfony convention). The
        // production/prod entrypoints load .env; the test suite pins its own
        // values so CI is deterministic.
        if (class_exists(Dotenv::class) && is_file($projectDir.'/.env.test')) {
            (new Dotenv())->bootEnv($projectDir.'/.env.test');
        }

        $this->kernel = new Kernel('test', false);
    }

    private function post(array $payload, ?string $session = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::API_KEY,
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }

        $request = Request::create('/mcp', 'POST', [], [], [], $server, json_encode($payload, \JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request);

        $body = $response->getContent();

        return [
            'status' => $response->getStatusCode(),
            'body' => '' === $body ? null : json_decode($body, true, 512, \JSON_THROW_ON_ERROR),
            'session' => $response->headers->get('Mcp-Session-Id'),
        ];
    }

    public function test_initialize_lists_and_calls_health_tool(): void
    {
        // 1. initialize
        $init = $this->post([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);

        self::assertSame(200, $init['status']);
        self::assertSame('context-loom', $init['body']['result']['serverInfo']['name']);
        self::assertSame('0.1.0-dev', $init['body']['result']['serverInfo']['version']);
        self::assertArrayHasKey('tools', $init['body']['result']['capabilities']);
        self::assertNotNull($init['session'], 'initialize must return an Mcp-Session-Id');

        $session = $init['session'];

        // 2. notifications/initialized
        $notif = $this->post([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $session);
        self::assertSame(202, $notif['status']);

        // 3. tools/list
        $list = $this->post([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], $session);

        self::assertSame(200, $list['status']);
        $toolNames = array_column($list['body']['result']['tools'], 'name');
        self::assertContains('contextloom_health', $toolNames);

        // 4. tools/call — contextloom_health
        $call = $this->post([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'contextloom_health',
                'arguments' => [],
            ],
        ], $session);

        self::assertSame(200, $call['status']);
        self::assertFalse($call['body']['result']['isError']);
        self::assertSame('ok', $call['body']['result']['structuredContent']['status']);
        self::assertSame([], $call['body']['result']['structuredContent']['providers']);
    }

    /**
     * Regression: every tool definition must survive a lossy decode → encode
     * round trip — the hop any consumer makes between us and the LLM.
     *
     * PHP cannot tell an empty JSON object (`{}`) from an empty array (`[]`)
     * once decoded into an associative array — both are `[]`. A definition
     * published with `"properties":{}` therefore comes back out of a
     * consumer's re-encode as `"properties":[]`, which strict tool-schema
     * validators reject: `JSON schema error at #: properties must be an
     * object`. (Reported via TaskWeaver, which stores tool schemas in a JSON
     * column and re-serves them to workers/LLMs.)
     */
    public function test_tool_schemas_survive_lossy_json_round_trip(): void
    {
        $init = $this->post([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]);
        $session = $init['session'];
        self::assertNotNull($session);

        $this->post([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $session);

        $list = $this->post([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], $session);

        self::assertSame(200, $list['status']);
        $tools = $list['body']['result']['tools'];
        self::assertNotEmpty($tools);

        foreach ($tools as $tool) {
            // $tool is already the assoc-decoded form (the lossy half of the
            // trip); re-encoding it is what any PHP consumer does next.
            $reEncoded = json_encode($tool, \JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString(
                '"properties":[]',
                $reEncoded,
                \sprintf('Tool "%s" re-encodes to an invalid "properties":[] (must stay an object).', $tool['name']),
            );
        }

        // The no-arg health tool publishes the bare minimal schema — nothing
        // an encoder could turn into `[]`.
        $healthTool = null;
        foreach ($tools as $tool) {
            if ('contextloom_health' === $tool['name']) {
                $healthTool = $tool;
                break;
            }
        }

        self::assertNotNull($healthTool, 'contextloom_health must be listed.');
        self::assertSame(['type' => 'object'], $healthTool['inputSchema']);
    }

    public function test_missing_api_key_is_unauthorized(): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
        $request = Request::create('/mcp', 'POST', [], [], [], $server, '{}');
        $response = $this->kernel->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Bearer', $response->headers->get('WWW-Authenticate') ?? '');
    }

    public function test_health_endpoint_is_unauthenticated_and_returns_ok(): void
    {
        $request = Request::create('/health', 'GET');
        $response = $this->kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('version', $body);
    }

    /**
     * Regression: production 403 (2026-09-13, see PR description). The SDK's
     * DNS-rebinding protection defaults to a localhost-only Host/Origin
     * allowlist, so a server deployed under a real hostname 403'd every
     * request before auth even ran. Request::create() defaults to
     * Host: localhost — exactly what the allowlist permits — which is why
     * the tests above never caught it.
     */
    public function test_unlisted_public_hostname_is_rejected(): void
    {
        $response = $this->postToHost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ], 'evil.example.net');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Host header', $response->getContent());
    }

    public function test_allowlisted_public_hostname_is_served(): void
    {
        $response = $this->postToHost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ], self::ALLOWED_HOST);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('context-loom', $body['result']['serverInfo']['name']);
    }

    private function postToHost(array $payload, string $host): Response
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => $host,
            'HTTP_AUTHORIZATION' => 'Bearer '.self::API_KEY,
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];

        $request = Request::create('/mcp', 'POST', [], [], [], $server, json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->kernel->handle($request);
    }
}
