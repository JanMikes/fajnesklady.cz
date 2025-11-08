#!/bin/sh
set -e

echo "Waiting for PostgreSQL to be ready..."
until pg_isready -h postgres -U app; do
  echo "PostgreSQL is unavailable - sleeping"
  sleep 1
done

echo "PostgreSQL is up - continuing"

# Install Composer dependencies if vendor directory doesn't exist
if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction
fi

# Run Doctrine migrations automatically
if [ -f "bin/console" ]; then
    echo "Running database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
fi

# Set proper permissions for var directory
if [ -d "var" ]; then
    echo "Setting permissions for var/ directory..."
    chown -R www-data:www-data var
    chmod -R 775 var
fi

echo "Initialization complete - starting FrankenPHP"

# Execute the original Docker PHP entrypoint
exec frankenphp run --config /etc/caddy/Caddyfile
