FROM php:8.3-apache

# Install dependency PostgreSQL dan Composer
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Enable mod_rewrite
RUN a2enmod rewrite

# Copy project
COPY . /var/www/html/

WORKDIR /var/www/html

# Install library PHP
RUN composer install --no-dev --optimize-autoloader

# Permission
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
