#!/usr/bin/env bash
#
# Inside the clean room's app container: a fresh Laravel app, Marque installed
# into it from the mounted working tree, then the checks. tools/clean-room runs
# this; it isn't meant to be run on the host.

set -euo pipefail

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

cd /site

say 'A fresh Laravel app'
composer create-project laravel/laravel . --no-interaction --prefer-dist --quiet

say "Configuring it: ${CLEAN_ROOM_ENGINE} database, the stack's Redis and Mailpit"
env_set() {
    if grep -qE "^#? ?$1=" .env; then
        sed -i -E "s|^#? ?$1=.*|$1=$2|" .env
    else
        echo "$1=$2" >> .env
    fi
}
env_set APP_URL http://127.0.0.1:18000
env_set REDIS_HOST redis
env_set REDIS_CLIENT phpredis
env_set MAIL_MAILER smtp
env_set MAIL_HOST mail
env_set MAIL_PORT 1025
# Sent while the install runs, so the checks can find the owner's mail.
env_set QUEUE_CONNECTION sync

case "$CLEAN_ROOM_ENGINE" in
    sqlite) ;;
    mysql|pgsql)
        env_set DB_CONNECTION "$CLEAN_ROOM_ENGINE"
        env_set DB_HOST "$CLEAN_ROOM_ENGINE"
        env_set DB_PORT "$([ "$CLEAN_ROOM_ENGINE" = mysql ] && echo 3306 || echo 5432)"
        env_set DB_DATABASE marque
        env_set DB_USERNAME marque
        env_set DB_PASSWORD marque
        ;;
    *) echo "Unknown engine: $CLEAN_ROOM_ENGINE" >&2; exit 2 ;;
esac

say 'Pointing composer at the working tree'
# Symlinked rather than copied: each package directory holds its own vendor/
# for its suite, which a copy would drag along.
composer config repositories.marque '{"type": "path", "url": "/marque/packages/*", "options": {"symlink": true}}'
composer config minimum-stability dev
composer config prefer-stable true

say 'composer require marque/marque'
composer require marque/marque:@dev --no-interaction --quiet

say 'Building assets'
npm install --silent
npm run build --silent

say 'marque:install, unattended, from the preseed'
php artisan marque:install --preseed=/preseed.yml --no-interaction

say 'Rebuilding assets with the packages wired into the stylesheet'
npm run build --silent

say 'Checks'
php /marque/docker/clean-room/checks.php
