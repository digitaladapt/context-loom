<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Infrastructure\Notify\NtfyClient;
use App\Kernel;
use App\MCP\ContextLoomServer;
use App\Service\NotifyService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Guards the NTFY_TOKEN → NtfyClient wiring through the real container.
 *
 * The unit tests pin the client behavior; this pins the other half — that
 * `%env(default::NTFY_TOKEN)%` actually reaches the client (and resolves to
 * null when unset, so anonymous publishing keeps working untouched).
 */
final class NotifyWiringTest extends TestCase
{
    protected function setUp(): void
    {
        $projectDir = \dirname(__DIR__, 2);

        if (class_exists(Dotenv::class) && is_file($projectDir.'/.env.test')) {
            (new Dotenv())->bootEnv($projectDir.'/.env.test');
        }
    }

    protected function tearDown(): void
    {
        putenv('NTFY_TOKEN');
        unset($_ENV['NTFY_TOKEN'], $_SERVER['NTFY_TOKEN']);
    }

    public function test_ntfy_token_env_is_wired_into_the_notify_client(): void
    {
        putenv('NTFY_TOKEN=tk_wired_test_token');
        $_ENV['NTFY_TOKEN'] = 'tk_wired_test_token';

        $client = $this->notifyClientFromKernel();

        self::assertSame('tk_wired_test_token', self::readPrivate($client, 'ntfyToken'));
    }

    public function test_notify_client_gets_null_token_when_env_unset(): void
    {
        putenv('NTFY_TOKEN');
        unset($_ENV['NTFY_TOKEN'], $_SERVER['NTFY_TOKEN']);

        $client = $this->notifyClientFromKernel();

        self::assertNull(self::readPrivate($client, 'ntfyToken'));
    }

    /**
     * Boot the real kernel and walk the public ContextLoomServer service down
     * to the private notify client — the same graph a tool call hits in prod.
     */
    private function notifyClientFromKernel(): NtfyClient
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();

        $server = $kernel->getContainer()->get(ContextLoomServer::class);
        self::assertInstanceOf(ContextLoomServer::class, $server);

        $notifyService = self::readPrivate($server, 'notifyService');
        self::assertInstanceOf(NotifyService::class, $notifyService);

        $client = self::readPrivate($notifyService, 'ntfyClient');
        self::assertInstanceOf(NtfyClient::class, $client);

        return $client;
    }

    private static function readPrivate(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }
}
