# Use PHP 8.2 with FPM
FROM php:8.2-fpm

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    vim \
    unzip \
    git \
    curl \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install pdo_mysql pdo_pgsql pgsql zip exif pcntl bcmath opcache sockets

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy existing application directory contents
COPY . /var/www/html

# Copy existing application directory permissions
COPY --chown=www-data:www-data . /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Install php-amqplib for RabbitMQ
RUN composer require php-amqplib/php-amqplib

# Create storage directories and set permissions
RUN mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/storage/framework/cache \
    && mkdir -p /var/www/html/storage/framework/sessions \
    && mkdir -p /var/www/html/storage/framework/views \
    && mkdir -p /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Create entrypoint script
RUN echo '#!/bin/bash\n\
# Wait for database to be ready\n\
echo "Waiting for database..."\n\
until php artisan migrate:status > /dev/null 2>&1; do\n\
  echo "Database not ready, waiting..."\n\
  sleep 2\n\
done\n\
\n\
# Run migrations\n\
echo "Running database migrations..."\n\
php artisan migrate --force\n\
\n\
# Clear and cache config\n\
php artisan config:clear\n\
php artisan config:cache\n\
\n\
# Generate app key if not set\n\
if [ -z "$APP_KEY" ]; then\n\
  php artisan key:generate\n\
fi\n\
\n\
echo "Starting Laravel development server..."\n\
php artisan serve --host=0.0.0.0 --port=8000' > /var/www/html/entrypoint.sh \
    && chmod +x /var/www/html/entrypoint.sh

# Expose port 8000
EXPOSE 8000

# Switch to non-privileged user
USER www-data

# Start the application
ENTRYPOINT ["/var/www/html/entrypoint.sh"]