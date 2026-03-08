FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/Uploads/equipment /var/www/html/Uploads/profiles \
    && chown -R www-data:www-data /var/www/html/Uploads

RUN printf '%s\n' \
'#!/bin/sh' \
'set -eu' \
'' \
'if [ ! -s /var/www/html/config.php ]; then' \
'  cat > /var/www/html/config.php <<EOF' \
'<?php' \
'define('\''DB_HOST'\'', getenv('\''DB_HOST'\'') ?: '\''db'\'');' \
'define('\''DB_NAME'\'', getenv('\''DB_NAME'\'') ?: '\''equipment_borrowing'\'');' \
'define('\''DB_USER'\'', getenv('\''DB_USER'\'') ?: '\''appuser'\'');' \
'define('\''DB_PASS'\'', getenv('\''DB_PASS'\'') ?: '\''apppassword'\'');' \
'' \
'try {' \
'    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);' \
'    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);' \
'    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);' \
'} catch (PDOException $e) {' \
'    die("Database connection failed: " . $e->getMessage());' \
'}' \
'EOF' \
'fi' \
'' \
'exec apache2-foreground' \
> /usr/local/bin/start-app.sh \
    && chmod +x /usr/local/bin/start-app.sh

EXPOSE 80

CMD ["/usr/local/bin/start-app.sh"]
