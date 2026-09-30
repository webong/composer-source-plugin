ARG PHP_VERSION=8.1
FROM composer:2 AS composer
FROM php:${PHP_VERSION}-cli-alpine
RUN apk add --no-cache bash git unzip libxml2 oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libxml2-dev oniguruma-dev \
    && for extension in dom mbstring xml xmlwriter; do \
         if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then docker-php-ext-install "$extension"; fi; \
       done \
    && apk del .build-deps
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer
WORKDIR /plugin
COPY composer.json phpunit.xml.dist README.md ./
COPY src ./src
COPY tests ./tests
RUN composer update --no-interaction --prefer-dist --no-progress --no-plugins --no-scripts
CMD ["sh", "-c", "composer test && bash tests/integration/mirror.sh"]
