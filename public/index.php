<?php

declare(strict_types=1);

use App\Kernel;

if (!is_file(dirname(__DIR__).'/vendor/autoload_runtime.php')) {
    throw new RuntimeException('Run "composer install" first.');
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

/*
 * Front controller — routed through the Symfony Runtime.
 *
 * Important: the runtime is what enters the FrankenPHP worker loop. When the
 * FRANKENPHP_WORKER env var is truthy (set in the Dockerfile), SymfonyRuntime
 * hands the kernel to Runner\FrankenPhpWorkerRunner, which calls
 * frankenphp_handle_request() and (with FRANKENPHP_RESET_KERNEL=1) clones the
 * kernel between requests. A plain Request::createFromGlobals() + $kernel->handle()
 * script NEVER reaches frankenphp_handle_request(), so the worker fails to
 * initialize and FrankenPHP aborts startup with:
 *   "failed to initialize workers: too many consecutive failures:
 *    worker ... has not reached frankenphp_handle_request()"
 *
 * The runtime also loads .env (via symfony/dotenv) when present, so the
 * previous manual Dotenv boot-env is no longer needed here.
 */
return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'] ?? 'prod', (bool) ($context['APP_DEBUG'] ?? false));
};
