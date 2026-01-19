FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy existing application directory contents
COPY . .

# Install dependencies if composer.json exists
# RUN if [ -f "composer.json" ]; then composer install --no-interaction --optimize-autoloader; fi

EXPOSE 9000

CMD ["php-fpm"]
