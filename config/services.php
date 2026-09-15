<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\McpController;
use App\Infrastructure\Auth\ApiKeyAuthenticator;
use App\Infrastructure\Executor\HttpExecutor;
use App\Infrastructure\Executor\InternalExecutor;
use App\Infrastructure\Executor\ProcessExecutor;
use App\Infrastructure\Notify\DiscordClient;
use App\Infrastructure\Notify\NtfyClient;
use App\MCP\ContextLoomServer;
use App\MCP\HealthTool;
use App\MCP\ToolFactory;
use App\Service\HealthRegistry;
use App\Service\NotifyService;
use App\Service\Registry\Registry;
use App\Service\Stream\StreamSink;
use App\Service\ToolExecutor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // App services are private by default and autowired/autoconfigured
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    // PSR-17 factories (Nyholm)
    $services->set(Psr17Factory::class);
    $services->alias(ServerRequestFactoryInterface::class, Psr17Factory::class);
    $services->alias(StreamFactoryInterface::class, Psr17Factory::class);
    $services->alias(ResponseFactoryInterface::class, Psr17Factory::class);

    // Logging
    $services->set('logger', \Symfony\Component\HttpKernel\Log\Logger::class);

    // PSR-18 HTTP client (Symfony HttpClient)
    $services->alias(ClientInterface::class, \Symfony\Component\HttpClient\Psr18Client::class);
    $services->set(\Symfony\Component\HttpClient\Psr18Client::class);

    // Registry — load entries at boot
    $services->set(Registry::class)
        ->args([
            '%kernel.project_dir%/registry',
            service('logger'),
        ]);

    // Tool executor — registers all executors
    $services->set(ToolExecutor::class)
        ->args([
            \iterator_to_array($services->taggedIterator('App\\Infrastructure\\Executor\\ToolExecutorInterface')),
            service('logger'),
        ]);

    // Executors
    $services->set(HttpExecutor::class)
        ->args([
            service(ClientInterface::class),
            service(ServerRequestFactoryInterface::class),
            service(StreamFactoryInterface::class),
        ])
        ->tag('App\\Infrastructure\\Executor\\ToolExecutorInterface');

    $services->set(ProcessExecutor::class)
        ->args([
            service('logger'),
        ])
        ->tag('App\\Infrastructure\\Executor\\ToolExecutorInterface');

    $services->set(InternalExecutor::class)
        ->tag('App\\Infrastructure\\Executor\\ToolExecutorInterface');

    // Notify clients
    $services->set(NtfyClient::class)
        ->args([
            service(ClientInterface::class),
            service(ServerRequestFactoryInterface::class),
            service(StreamFactoryInterface::class),
            '%env(default::NTFY_URL)%',
        ]);

    $services->set(DiscordClient::class)
        ->args([
            service(ClientInterface::class),
            service(ServerRequestFactoryInterface::class),
            service(StreamFactoryInterface::class),
        ]);

    // Notify service
    $services->set(NotifyService::class)
        ->args([
            service(NtfyClient::class),
            service(DiscordClient::class),
            service('logger'),
        ]);

    // ToolFactory (MCP)
    $services->set(ToolFactory::class);

    // Health
    $services->set(HealthRegistry::class)->public();
    $services->set(HealthTool::class);

    // MCP
    $services->set(ContextLoomServer::class)
        ->args([
            service(HealthRegistry::class),
            service(ToolExecutor::class),
            service(Registry::class),
            service(ToolFactory::class),
            service(NotifyService::class),
            service('logger'),
            '%kernel.project_dir%/var/mcp-sessions',
        ])
        ->public();

    // Controllers (public: referenced by routes)
    $services->set(HealthController::class)->public();
    $services->set(McpController::class)
        ->args([
            '$allowedHostsCsv' => '%env(default::CONTEXT_LOOM_ALLOWED_HOSTS)%',
        ])
        ->public();

    // Auth middleware
    $services->set(ApiKeyAuthenticator::class)
        ->args([
            '%env(CONTEXT_LOOM_API_KEY)%',
            service(ResponseFactoryInterface::class),
        ]);

    // Commands
    $services->set(App\Command\ValidateCommand::class)->public();
    $services->set(App\Command\ProbeCommand::class)->public();
    $services->set(App\Command\ServeCommand::class)
        ->args(['%kernel.project_dir%'])
        ->public();
};
