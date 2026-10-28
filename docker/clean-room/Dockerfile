# The clean room's app container: what a fresh Laravel host for Marque needs.
#
# PHP 8.3 on purpose. It's the floor every package declares (VERSIONING.md),
# and the dev machine runs 8.4, so this is the only place an install is
# exercised on the oldest PHP we promise.
FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libpq-dev libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite intl zip bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node from the official image: Laravel 13's Vite needs a newer Node than
# Debian ships.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/cache/composer \
    npm_config_cache=/cache/npm

# The monorepo is mounted from the host, owned by another uid; git refuses
# to read it otherwise, and composer needs git to version the path packages.
RUN git config --global --add safe.directory '*'

WORKDIR /site
