FROM php:8.4-cli-alpine

# ── System dependencies ───────────────────────────────────────────
RUN apk add --no-cache \
        bash \
        git \
        curl \
        libpq-dev \
        libzip-dev \
        zip \
        unzip \
        oniguruma-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        mbstring \
        pcntl \
    && rm -rf /var/cache/apk/*

# ── Composer ─────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ── Install PHP dependencies (cached layer) ───────────────────────
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --optimize-autoloader

# ── Copy source ───────────────────────────────────────────────────
COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/docker-entrypoint.sh"]
