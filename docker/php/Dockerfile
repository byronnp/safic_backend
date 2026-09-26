# SAFIC API · PHP 8.5 FPM + Nginx en una sola imagen.
# Etapas: base (común) → dev (local con Xdebug y código montado) → prod (código copiado).

FROM php:8.5-fpm-alpine AS base

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache nginx supervisor shadow su-exec bash git unzip openssl postgresql-client \
    && install-php-extensions pdo_pgsql pgsql redis intl gd zip bcmath pcntl opcache exif sockets \
    && usermod -u ${UID} www-data && groupmod -g ${GID} www-data \
    && sed -i 's/^user nginx;/user www-data;/' /etc/nginx/nginx.conf \
    && mkdir -p /run/nginx /var/log/supervisor \
    && chown -R www-data:www-data /var/lib/nginx /var/log/nginx /run/nginx

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-safic.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html
EXPOSE 8080
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# ---------------------------------------------------------------- dev
FROM base AS dev
RUN install-php-extensions xdebug \
    && printf "xdebug.mode=debug\nxdebug.start_with_request=trigger\nxdebug.client_host=host.docker.internal\n" \
       > /usr/local/etc/php/conf.d/zz-xdebug.ini
USER www-data

# ---------------------------------------------------------------- prod
# supervisord arranca como root y php-fpm/nginx trabajan como www-data.
# La configuración se cachea al arrancar (entrypoint), cuando ya existen las
# variables de entorno y los secretos del ambiente, no al construir la imagen.
FROM base AS prod
COPY --chown=www-data:www-data . /var/www/html
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/php/entrypoint-prod.sh /usr/local/bin/entrypoint-prod
RUN chmod +x /usr/local/bin/entrypoint-prod
ENTRYPOINT ["entrypoint-prod"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
