FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-install intl mbstring mysqli pdo pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

RUN chown -R www-data:www-data /var/www/html/writable

COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80