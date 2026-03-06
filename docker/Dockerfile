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

# Ensure upload directories exist and are writable
RUN mkdir -p /var/www/html/public/uploads/products \
    && chown -R www-data:www-data /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/public/uploads

# Ensure SRI storage directories exist with correct permissions
RUN mkdir -p /var/www/html/storage/sri/xml \
    /var/www/html/storage/sri/signed \
    /var/www/html/storage/sri/authorized \
    /var/www/html/storage/sri/ride \
    /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage/sri \
    && chmod -R 775 /var/www/html/storage/logs

# Install dependencies if composer.json exists
RUN if [ -f "composer.json" ]; then git config --global --add safe.directory /var/www/html && composer install --no-interaction --prefer-dist --optimize-autoloader; fi

EXPOSE 9000

CMD ["php-fpm"]
