# Development image: the PHP version under test, plus what Composer needs to unpack packages.
ARG PHP_VERSION=8.1

FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install --no-install-recommends --assume-yes git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
