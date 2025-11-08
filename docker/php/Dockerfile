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
    postgresql-client \
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

# Install dependencies (skip auto-scripts because they might need dev dependencies)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Set proper permissions
RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var && \
    chmod -R 775 var

# Development stage
FROM dunglas/frankenphp:1-php8.4-bookworm AS dev

# Install system dependencies
RUN apt-get update && apt-get install -y \
    acl \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    intl \
    opcache

# Install APCu
RUN pecl install apcu && docker-php-ext-enable apcu

# Install Xdebug
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json composer.lock symfony.lock ./

# Install dependencies
RUN composer install --optimize-autoloader --no-interaction --no-scripts

# Copy application files (includes docker directory with configs and entrypoint)
COPY . /app

# Copy development PHP configuration after copying all files
COPY docker/php/conf.d/app.dev.ini $PHP_INI_DIR/conf.d/app.ini

# Run composer scripts now that all files are present
RUN composer install --optimize-autoloader --no-interaction

# Copy and configure entrypoint (it's already copied with COPY . /app above)
RUN cp docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint && \
    chmod +x /usr/local/bin/docker-entrypoint

# Set proper permissions
RUN mkdir -p var/cache var/log && \
    chown -R www-data:www-data var && \
    chmod -R 775 var

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# Production stage
FROM base AS prod

# Copy production PHP configuration
COPY docker/php/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/app.ini

# Optimize for production
RUN composer dump-autoload --optimize --classmap-authoritative

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
