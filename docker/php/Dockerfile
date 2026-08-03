FROM php:8.5-fpm

# Системные библиотеки: Postgres, Firebird-клиент, zip/icu/oniguruma для PHP-расширений
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libfbclient2 firebird-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install \
        pdo_pgsql \
        pdo_firebird \
        intl \
        zip \
        bcmath \
        pcntl \
    && rm -rf /var/lib/apt/lists/*
# mbstring, PDO, OPcache уже встроены в базовый образ php:8.5 — отдельно не ставим.

# Composer из официального образа
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Непривилегированный пользователь с uid/gid хоста (bind mounts без проблем с правами)
ARG UID=1000
ARG GID=1000
RUN groupmod -g "${GID}" www-data 2>/dev/null || true \
    && usermod  -u "${UID}" -g "${GID}" www-data 2>/dev/null || true

WORKDIR /var/www/html
USER www-data
