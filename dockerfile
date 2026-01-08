FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Instalar composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar TODO el proyecto (no solo src)
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

RUN echo '<Directory /var/www/html/>\n\
Options Indexes FollowSymLinks\n\
AllowOverride All\n\
Require all granted\n\
</Directory>' > /etc/apache2/conf-available/docker-php.conf \
 && a2enconf docker-php

EXPOSE 80
CMD ["apache2-foreground"]
