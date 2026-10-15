# Build context: the repository root (see docker-compose.yml) — the SwooleApp framework is
# taken from doc/SwooleApp as a composer "path" repository.
FROM phpswoole/swoole:6.0-php8.3

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer

COPY doc/SwooleApp /srv/doc/SwooleApp
WORKDIR /srv/back
COPY back/composer.json back/composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-autoloader

COPY back/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh

EXPOSE 9501
ENTRYPOINT ["/srv/back/docker/entrypoint.sh"]
