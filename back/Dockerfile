# Build context: the repository root (see docker-compose.yml).
# The SwooleApp framework is a regular composer dependency (sidalex/swoole-app from Packagist,
# version pinned in composer.lock) — https://github.com/SwooleApp/SwooleApp
#
# Stages:
#   prod  — the service image (docker-compose.yml builds it with target: prod), no dev dependencies;
#   test  — the same code + dev dependencies, runs the unit tests:
#             docker build -f back/Dockerfile --target test -t wps-backend-test .
#             docker run --rm wps-backend-test --testsuite unit
FROM phpswoole/swoole:6.0-php8.3 AS base

# bcmath: exact decimal comparison and arithmetic (cast "decimal", md/tasks_list/done/3.0-type-cast.md)
# intl: month and day names in format_date_locale() (md/tasks_list/done/5.2.2-expr-dates.md), optional — without it
# the function is simply not registered
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install bcmath intl \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer

WORKDIR /srv/back

FROM base AS prod

COPY back/composer.json back/composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-autoloader

COPY back/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh

EXPOSE 9501
ENTRYPOINT ["/srv/back/docker/entrypoint.sh"]

FROM base AS test

COPY back/composer.json back/composer.lock* ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-autoloader

COPY back/ ./
RUN composer dump-autoload

ENTRYPOINT ["vendor/bin/phpunit"]
