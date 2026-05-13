FROM ghcr.io/thedevs-cz/php:8.5-fajnesklady

ENV APP_ENV="prod" \
    APP_DEBUG=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN rm $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini

COPY --link --chmod=755 .docker/on-startup.sh /docker-entrypoint.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY . .

# Runs cache:clear + assets:install + importmap:install via Flex auto-scripts,
# warming the prod container cache before the two console calls below.
RUN composer install --no-dev --no-interaction --classmap-authoritative

RUN bin/console tailwind:build
RUN bin/console asset-map:compile

ARG APP_VERSION
ENV SENTRY_RELEASE="${APP_VERSION}"
