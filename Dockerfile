# ==========================================
# STAGE 1: Build PHP Dependencies via Composer
# ==========================================
FROM composer:latest as composer_stage
WORKDIR /app
COPY . .
# Clean vendor lama jika ter-copy
RUN rm -rf vendor
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# ==========================================
# STAGE 2: Build Asset Frontend via Node.js
# ==========================================
FROM node:20-alpine as node_stage
WORKDIR /app
COPY --from=composer_stage /app /app
RUN npm install
RUN npm run build

# ==========================================
# STAGE 3: Production Image (PHP 8.3)
# ==========================================
FROM php:8.3-fpm

# Install system dependencies & PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd sockets

WORKDIR /var/www

# Copy hasil build dari Stage 1 & 2
COPY --from=node_stage /app /var/www

# Set permissions storage
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Copy Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 7000 7070 514/udp

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]