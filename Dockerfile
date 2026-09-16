# ── Stage 1: Composer ──────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4 AS composer

# System deps for composer install
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy only manifests first for better layer caching
COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --no-scripts

# Build arg for application version (pass with --build-arg APP_VERSION=v2.0.0 in CI)
ARG APP_VERSION=dev

# Copy the rest of the application
COPY . .

# Write the version file (used by SystemController at runtime)
RUN echo "${APP_VERSION}" > VERSION

# ── Stage 2: Runtime ───────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4 AS runtime

# Install only runtime system deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-context-loom.ini

WORKDIR /app

# Copy the built application from the composer stage
COPY --from=composer /app /app

# create empty ".env" file, to resolve error
RUN touch /app/.env

# Copy Docker support files
COPY docker/Caddyfile /app/docker/Caddyfile
COPY docker/entrypoint.sh /app/docker/entrypoint.sh
RUN chmod +x /app/docker/entrypoint.sh

# Environment defaults
ENV APP_ENV=prod \
    FRANKENPHP_WORKER=1 \
    FRANKENPHP_RESET_KERNEL=1

# Volume for logs and cache
VOLUME /app/var

# Run as non-root user for security
USER nobody:nogroup

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -sf http://localhost:80/health || exit 1

ENTRYPOINT ["/app/docker/entrypoint.sh"]
