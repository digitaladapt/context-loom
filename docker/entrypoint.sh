#!/bin/sh
set -eu

echo "→ Warming cache…"
php /app/bin/console cache:warm --env=prod

echo "→ Starting FrankenPHP…"
exec frankenphp run --config /app/docker/Caddyfile
