FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY src/public/ /var/www/html/
COPY src/functions.php /var/www/functions.php

