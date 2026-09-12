<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

if (!is_file(dirname(__DIR__).'/vendor/autoload.php')) {
    throw new RuntimeException('Run "composer install" first.');
}

require dirname(__DIR__).'/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file(dirname(__DIR__).'/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

/*
 * PHP built-in server router script (`php -S host:port router.php`).
 *
 * Unlike a static router that just includes index.php, this script:
 *  - serves real files from public/ (so getters/static assets work),
 *  - routes everything else through the Symfony kernel.
 *
 * The front controller public/index.php remains the entrypoint for
 * FrankenPHP/Caddy (Phase 5); this router is the dev loop only.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?? '/';

$file = __DIR__.$path;
if (is_file($file) && !str_contains($path, '..')) {
    return false; // let the built-in server serve the static file
}

require __DIR__.'/index.php';
