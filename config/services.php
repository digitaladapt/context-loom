<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\McpController;
use App\Infrastructure\Auth\ApiKeyAuthenticator;
use App\MCP\ContextLoomServer;
use App\MCP\HealthTool;
use App\Service\HealthRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // App services are private by default and autowired/autoconfigured
    // (Symfony convention). Controllers/commands referenced by the kernel
    // are public.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    // PSR-17 factories (Nyholm) — needed by both the MCP controller and
    // the API-key middleware. One factory implements all four interfaces.
    $services->set(Psr17Factory::class);
    $services->alias(ServerRequestFactoryInterface::class, Psr17Factory::class);
    $services->alias(StreamFactoryInterface::class, Psr17Factory::class);
    $services->alias(ResponseFactoryInterface::class, Psr17Factory::class);

    // Logging: no monolog in Phase 0 — http-kernel provides an autowired
    // `logger` service that falls back to stderr; we alias it explicitly so
    // autowiring Psr\Log\LoggerInterface works everywhere.
    $services->set('logger', Symfony\Component\HttpKernel\Log\Logger::class);

    // Controllers (public: referenced by routes).
    $services->set(HealthController::class)->public();
    $services->set(McpController::class)->public();

    // MCP layer.
    $services->set(HealthRegistry::class)->public();
    $services->set(HealthTool::class);
    $services->set(ContextLoomServer::class)
        ->args([
            service(HealthTool::class),
            service('logger'),
            '%kernel.project_dir%/var/mcp-sessions',
        ]);

    // Auth middleware (used by the MCP transport).
    $services->set(ApiKeyAuthenticator::class)
        ->args([
            '%env(CONTEXT_LOOM_API_KEY)%',
            service(ResponseFactoryInterface::class),
        ]);

    // Commands (autoconfigured via AsCommand).
    $services->set(App\Command\ValidateCommand::class)->public();
    $services->set(App\Command\ProbeCommand::class)->public();
    $services->set(App\Command\ServeCommand::class)
        ->args(['%kernel.project_dir%'])
        ->public();
};
