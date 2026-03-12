#!/bin/bash

cat > /var/www/html/.env << EOF
CI_ENVIRONMENT = ${CI_ENVIRONMENT}
app.baseURL = ${app_baseURL}
database.default.hostname = ${database_default_hostname}
database.default.database = ${database_default_database}
database.default.username = ${database_default_username}
database.default.password = ${database_default_password}
database.default.port = ${database_default_port}
database.default.DBDriver = MySQLi

EOF