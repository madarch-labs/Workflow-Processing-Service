#!/bin/sh
# composer dependencies are installed at build time; migrations run on every start (idempotent)
set -e
cd /srv/back
[ -f .env ] || cp .env.example .env
php migrations/migrate.php
exec php server.php
