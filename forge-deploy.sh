#!/usr/bin/env bash
set -e

cd /home/forge/backend.anfrage-professionalclean.ch/current/backend

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache

echo "Deploy complete."
