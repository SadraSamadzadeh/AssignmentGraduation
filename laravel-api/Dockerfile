# ============================================================================
# STAGE 1: Base Image
# ============================================================================
# Purpose: Create a base image with all system dependencies and PHP extensions
# This stage is shared by both production API and worker images to ensure consistency
FROM php:8.2-fpm-alpine AS base

# Install system dependencies required for PHP extensions and Laravel
# - nginx: Web server for serving the application
# - supervisor: Process manager to run multiple processes (nginx + php-fpm)
# - postgresql-dev, libpq: PostgreSQL client libraries for database connections
# - icu-dev: Internationalization support for Laravel
# - libzip-dev: ZIP archive support for composer and Laravel
# - oniguruma-dev: Required for mbstring extension
# - linux-headers: Linux kernel headers required for sockets extension
# - curl: HTTP client for external API calls
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libpq \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    curl \
    bash

# Install PHP extensions needed by Laravel and the application
# - pdo_pgsql: PDO driver for PostgreSQL (database queries)
# - pgsql: PostgreSQL native driver (backup/restore operations)
# - intl: Internationalization (date/time formatting, translations)
# - zip: ZIP archive handling (composer, package management)
# - mbstring: Multibyte string handling (UTF-8 support)
# - opcache: PHP opcode caching (performance optimization)
# - bcmath: Arbitrary precision mathematics (financial calculations)
# - pcntl: Process control (queue workers, signal handling)
# - sockets: Socket communication for RabbitMQ
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    mbstring \
    opcache \
    bcmath \
    pcntl \
    sockets

# Install Redis extension via PECL for cache and session storage
# Version 6.0.2 is stable and compatible with PHP 8.2
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis-6.0.2 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer (PHP dependency manager)
# Using official Composer 2.x image as source for the binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory for all subsequent commands
WORKDIR /var/www/html

# ============================================================================
# STAGE 2: Dependencies
# ============================================================================
# Purpose: Install PHP dependencies separately for better caching
# Docker will cache this layer if composer.json/lock don't change
FROM base AS dependencies

# Copy only composer files first for optimal Docker layer caching
# If these files don't change, Docker reuses the cached layer with installed dependencies
COPY composer.json composer.lock ./

# Install production dependencies
# --no-dev: Skip development dependencies (phpunit, etc.)
# --no-scripts: Don't run post-install scripts yet (needs full codebase)
# --no-autoloader: Don't generate autoloader yet (needs full codebase)
# --prefer-dist: Download distribution packages (faster than source)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --optimize-autoloader

# ============================================================================
# STAGE 3: Production API Image
# ============================================================================
# Purpose: Final image for running the Laravel API with Nginx + PHP-FPM
# This container handles HTTP requests, serves the application, and dispatches jobs
FROM dependencies AS production

# Copy the entire Laravel application code
COPY . .

# Complete the Composer installation with full codebase
# --optimize-autoloader: Generate optimized class maps for faster autoloading
# --classmap-authoritative: Use only classmap (no file scanning, best performance)
RUN composer dump-autoload --optimize --classmap-authoritative

# Create Laravel storage directories before running cache commands
# These directories are required for view caching, session storage, etc.
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R 775 storage bootstrap/cache

# Optimize Laravel for production
# config:cache: Cache all config files into single file (faster loading)
# route:cache: Cache routes for faster routing (no need to parse routes/*)
# Note: Skipping view:cache since this is an API-only application without Blade views
RUN php artisan config:cache \
    && php artisan route:cache

# Configure PHP for production
# Using production php.ini with security and performance settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy custom PHP configuration
# Overrides for memory limits, upload size, execution time, etc.
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/custom.ini

# Copy OPcache configuration for performance
# OPcache stores compiled PHP bytecode in memory (huge performance boost)
COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini

# Copy Nginx configuration
# Main nginx config with worker processes, events, http settings
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf

# Virtual host configuration for the Laravel application
# Handles request routing, static files, PHP processing
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy Supervisor configuration
# Manages nginx and php-fpm processes in a single container
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set proper ownership and permissions
# nginx user needs to read application files and write to storage/logs
# 775 permissions allow read/write/execute for owner and group
RUN chown -R nginx:nginx /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Create log directories for nginx
RUN mkdir -p /var/log/nginx /var/log/supervisor \
    && chown -R nginx:nginx /var/log/nginx

# Create directory for PHP-FPM socket and PID files
RUN mkdir -p /run/php

# Expose port 80 for HTTP traffic
# Docker Swarm's ingress network will load balance across replicas
EXPOSE 80

# Health check to verify the container is functioning
# Checks the /api/health endpoint every 30 seconds
# Fails if 3 consecutive checks fail, starts checking after 10s
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/api/health || exit 1

# Start Supervisor which manages nginx and php-fpm
# Supervisor keeps both processes running and restarts them if they crash
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# ============================================================================
# STAGE 4: Worker Image
# ============================================================================
# Purpose: Separate image for queue workers (no nginx, only PHP CLI)
# Workers consume jobs from RabbitMQ and process them asynchronously
FROM dependencies AS worker

# Copy the entire Laravel application code
COPY . .

# Complete the Composer installation
RUN composer dump-autoload --optimize --classmap-authoritative

# Configure PHP for CLI (same as production but no need for FPM)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy PHP configuration (memory limits, etc.)
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/custom.ini

# Workers don't need opcache (CLI doesn't benefit much from it)
# But we keep it for consistency and potential future use

# Set proper permissions for storage and cache
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R 775 storage bootstrap/cache

# No exposed ports - workers don't accept HTTP traffic
# They only consume from RabbitMQ via internal overlay network

# Health check for workers: verify PHP can execute
# Simpler than API health check since there's no HTTP server
HEALTHCHECK --interval=60s --timeout=3s --start-period=30s --retries=3 \
    CMD php artisan queue:restart || exit 1

# Run the Laravel queue worker
# queue:work rabbitmq: Use RabbitMQ connection (from config/queue.php)
# --sleep=3: Sleep 3 seconds when no jobs available (reduces CPU)
# --tries=3: Retry failed jobs up to 3 times before marking as failed
# --timeout=300: Max 5 minutes per job (prevents hanging)
# --memory=512: Restart worker if memory exceeds 512MB (prevents leaks)
CMD ["php", "artisan", "queue:work", "rabbitmq", "--sleep=3", "--tries=3", "--timeout=300", "--memory=512"]

# ============================================================================
# STAGE 5: Scheduler Image
# ============================================================================
# Purpose: Runs Laravel's task scheduler (cron jobs)
# Handles periodic tasks like retrying unmatched records, cleanup, etc.
FROM dependencies AS scheduler

# Copy application code
COPY . .

# Complete Composer installation
RUN composer dump-autoload --optimize --classmap-authoritative

# Configure PHP for CLI
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/custom.ini

# Set permissions
RUN mkdir -p storage/logs storage/framework/cache \
    && chmod -R 775 storage bootstrap/cache

# No exposed ports - scheduler runs cron tasks only

# Health check: verify scheduler process is alive
HEALTHCHECK --interval=60s --timeout=3s --start-period=10s --retries=3 \
    CMD ps aux | grep 'schedule:run' || exit 0

# Run the Laravel scheduler every minute
# This executes all scheduled tasks defined in app/Console/Kernel.php
# The infinite loop keeps the container running
CMD ["sh", "-c", "while true; do php artisan schedule:run --verbose --no-interaction >> /dev/null 2>&1; sleep 60; done"]
