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

RUN printf 'CI_ENVIRONMENT = development\napp.baseURL = https://dbsubasta-production.up.railway.app/\ndatabase.default.hostname = switchyard.proxy.rlwy.net\ndatabase.default.database = railway\ndatabase.default.username = root\ndatabase.default.password = kphYyKHHiTBjQDacodwZONPuwpZipLPs\ndatabase.default.port = 43411\ndatabase.default.DBDriver = MySQLi\njwt.secretkey = eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VybmFtZSI6ImFkbWluIiwiaXNzIjoib25saW5lX2F1Y3Rpb25fYXBpIiwiaWF0IjoxNjgxODk1MzkxLCJleHAiOjE2ODE5ODE3OTEsIm5iZiI6MTY4MTg5NTM5MSwianRpIjoxNjgxODk1MzkxfQ.qTCBNs6xddi3idkHSqxc4qBWEzNf5H6rWt7K7LgpzIU\njwt.ttl = 10080\nCLOUDINARY_CLOUD_NAME = dak11qeos\nCLOUDINARY_API_KEY = 457791725813288\nCLOUDINARY_API_SECRET = z2olEJDVdlSr4OtnM2X4BGJo_u0\n' > /var/www/html/.envRUN chown -R www-data:www-data /var/www/html/writable

EXPOSE 80