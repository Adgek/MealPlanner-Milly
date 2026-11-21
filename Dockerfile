# syntax=docker/dockerfile:1
#
# One image for both PHP services:
#   - `app`       runs Apache + mod_php and serves the web UI
#   - `scheduler` runs the same image with `artisan schedule:work`
#
# Node lives in here too, so `build` (Vite) also runs inside the container and
# the host never needs a toolchain of its own.

FROM php:8.4-apache-bookworm

# Match the host user so files written into the bind mount (vendor/, public/build/,
# storage/) stay editable outside the container on Linux hosts. Docker Desktop on
# macOS virtualises ownership, so the default is fine there.
ARG APP_UID=1000
ARG APP_GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    NPM_CONFIG_UPDATE_NOTIFIER=false \
    NPM_CONFIG_FUND=false

# --- System packages and PHP extensions -------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        libicu-dev \
        libzip-dev \
        tzdata \
        unzip; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pdo_mysql \
        zip; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# --- Composer ---------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- Node + npm (for Vite) --------------------------------------------------
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
RUN set -eux; \
    ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx; \
    node --version; \
    npm --version

# --- Apache -----------------------------------------------------------------
RUN set -eux; \
    a2enmod rewrite headers; \
    # Without this Apache logs a "could not reliably determine the server's fully
    # qualified domain name" warning on every start.
    echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername
COPY docker/apache/app.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# --- Run as the host user ---------------------------------------------------
RUN set -eux; \
    groupmod -o -g "${APP_GID}" www-data; \
    usermod  -o -u "${APP_UID}" -g "${APP_GID}" www-data

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["apache2-foreground"]
