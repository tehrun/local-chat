FROM php:8.3-apache

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite \
    && a2enmod rewrite headers \
    && sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-local-chat.sh
COPY . /var/www/html

RUN mkdir -p /var/www/html/storage/db /var/www/html/storage/uploads /var/www/html/storage/tmp \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint-local-chat.sh"]
CMD ["apache2-foreground"]
