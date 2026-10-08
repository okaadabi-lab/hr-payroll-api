FROM php:8.3-cli-alpine

RUN docker-php-ext-install pdo_mysql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize

EXPOSE 8082
CMD ["sh", "-c", "php bin/migrate && php -S 0.0.0.0:8082 -t public"]
