# Base stage with common dependencies
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

# Install system dependencies
RUN apt-get update && apt-get install -y \
    acl \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    intl \
    opcache

# Install APCu
RUN pecl install apcu && docker-php-ext-enable apcu

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set proper permissions
RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var && \
    chmod -R 775 var

# Development stage
FROM base AS dev

# Install Xdebug
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Copy development PHP configuration
COPY docker/php/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/app.ini

# Install development dependencies
RUN composer install --optimize-autoloader --no-interaction

# Copy entrypoint
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# Production stage
FROM base AS prod

# Copy production PHP configuration
COPY docker/php/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/app.ini

# Optimize for production
RUN composer dump-autoload --optimize --classmap-authoritative

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
