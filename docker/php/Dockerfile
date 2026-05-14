FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    $PHPIZE_DEPS \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    sqlite-dev \
    curl \
    unzip \
    git

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    pdo_sqlite \
    bcmath \
    gd \
    zip \
    intl \
    pcntl \
    opcache

RUN pecl install redis && docker-php-ext-enable redis

RUN apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
