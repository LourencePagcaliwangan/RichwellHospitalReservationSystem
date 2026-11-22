
FROM php:8.2-apache

WORKDIR /var/www/html

COPY . /var/www/html/

RUN a2enmod rewrite \
    && mkdir -p /var/www/html/data

EXPOSE 80

CMD ["bash", "-lc", "mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data && chmod -R 775 /var/www/html/data && exec apache2-foreground"]