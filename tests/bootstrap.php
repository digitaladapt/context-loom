<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap — pins the test environment before the kernel boots.
 *
 * Must run before any Kernel is constructed: Symfony reads APP_ENV/APP_DEBUG
 * at boot, and the container cache is keyed by environment.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '0';
putenv('APP_ENV=test');
putenv('APP_DEBUG=0');

if (class_exists(Dotenv::class) && is_file(dirname(__DIR__).'/.env.test')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.test');
}
