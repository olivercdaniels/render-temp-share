FROM php:8.2-apache

RUN a2enmod rewrite headers \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY experimental.zip /tmp/experimental.zip
COPY propertydata.php /var/www/html/api/propertydata.php
COPY index.php /var/www/html/index.php

RUN mkdir -p /var/www/html/api /var/www/html/experimental \
    && unzip -q /tmp/experimental.zip -d /var/www/html/experimental \
    && rm -f /tmp/experimental.zip \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
