# syntax=docker/dockerfile:1
# Image applicative TrouveMoi — FrankenPHP (Caddy + PHP 8.4). Cibles : dev (Xdebug off par défaut) et prod.

# PHP 8.4.27 minimum (roave/security-advisories) — tag figé sur le patch PHP exact.
# gd + exif : ré-encodage des images téléversées (ADR-0008 : EXIF retiré, polyglottes neutralisés).
FROM dunglas/frankenphp:1.13.3-php8.4.27-bookworm AS base
WORKDIR /app
RUN install-php-extensions intl pdo_pgsql opcache apcu redis zip sodium gd exif \
 && useradd --uid 1000 --create-home app \
 && chown -R app:app /app /data /config
COPY --link docker/php/conf.d/10-app.ini $PHP_INI_DIR/conf.d/
COPY --from=composer/composer:2-bin --link /composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=0

FROM base AS dev
ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
USER app

FROM base AS prod
ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link docker/php/conf.d/20-prod.ini $PHP_INI_DIR/conf.d/
USER app
COPY --chown=app:app --link composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY --chown=app:app --link . ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
 && composer dump-env prod \
 && php bin/console asset-map:compile \
 && php bin/console cache:warmup
