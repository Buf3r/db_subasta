#!/bin/bash

# Escribir variables al .env de CodeIgniter
cat > /var/www/html/.env << EOF
CI_ENVIRONMENT = ${CI_ENVIRONMENT}
app.baseURL = ${app.baseURL}
database.default.hostname = ${database.default.hostname}
database.default.database = ${database.default.database}
database.default.username = ${database.default.username}
database.default.password = ${database.default.password}
database.default.port = ${database.default.port}
database.default.DBDriver = MySQLi
EOF

# Iniciar Apache
apache2-foreground