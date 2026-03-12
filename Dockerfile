FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-install intl mbstring mysqli pdo pdo_mysql \
    && a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf



RUN echo 'CI_ENVIRONMENT = production\n\
app.baseURL = https://dbsubasta-production.up.railway.app/\n\
database.default.hostname = switchyard.proxy.rlwy.net\n\
database.default.database = railway\n\
database.default.username = root\n\
database.default.password = kphYyKHHiTBjQDacodwZONPuwpZipLPs\n\
database.default.port = 43411\n\
database.default.DBDriver = MySQLi' > /var/www/html/.env

RUN chown -R www-data:www-data /var/www/html/writable

EXPOSE 80