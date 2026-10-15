# Build context: the repository root (see docker-compose.yml).
# The SwooleApp framework is a regular composer dependency (sidalex/swoole-app from Packagist,
# version pinned in composer.lock) — https://github.com/SwooleApp/SwooleApp
FROM phpswoole/swoole:6.0-php8.3

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer

WORKDIR /srv/back
COPY back/composer.json back/composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-autoloader

COPY back/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh

EXPOSE 9501
ENTRYPOINT ["/srv/back/docker/entrypoint.sh"]
