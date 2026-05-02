#!/bin/bash

# Escribir variables normales al .env
printf "CI_ENVIRONMENT = development\napp.baseURL = https://dbsubasta-production.up.railway.app/\ndatabase.default.hostname = switchyard.proxy.rlwy.net\ndatabase.default.database = railway\ndatabase.default.username = root\ndatabase.default.password = kphYyKHHiTBjQDacodwZONPuwpZipLPs\ndatabase.default.port = 43411\ndatabase.default.DBDriver = MySQLi\nJWT_SECRET_KEY = ${JWT_SECRET_KEY}\nJWT_TTL = ${JWT_TTL}\nCLOUDINARY_CLOUD_NAME = ${CLOUDINARY_CLOUD_NAME}\nCLOUDINARY_API_KEY = ${CLOUDINARY_API_KEY}\nCLOUDINARY_API_SECRET = ${CLOUDINARY_API_SECRET}\n" > /var/www/html/.env

# Agregar FCM_CREDENTIALS por separado para evitar problemas con caracteres especiales
echo "FCM_CREDENTIALS = ${FCM_CREDENTIALS}" >> /var/www/html/.env

echo "ADMIN_KEY = ${ADMIN_KEY}" >> /var/www/html/.env
echo "ZERNIO_API_KEY = ${ZERNIO_API_KEY}" >> /var/www/html/.env
echo "ZERNIO_PHONE_ID = ${ZERNIO_PHONE_ID}" >> /var/www/html/.env

# Correr migraciones si se pasa el argumento
if [ "$1" = "migrate" ]; then
    php spark migrate --all
fi

php spark serve --host 0.0.0.0 --port $PORT