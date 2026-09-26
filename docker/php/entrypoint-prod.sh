#!/bin/sh
# Producción: cachea configuración, rutas y eventos con las variables del ambiente.
set -e
su-exec www-data php artisan optimize
exec "$@"
