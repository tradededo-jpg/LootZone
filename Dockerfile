FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_pgsql curl \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite headers
COPY public/ /var/www/html/
COPY backend/ /var/www/html/backend/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
