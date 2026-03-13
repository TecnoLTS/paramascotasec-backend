FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    tzdata

# Configurar zona horaria para Ecuador
ENV TZ=America/Guayaquil
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql zip

# Configurar zona horaria de PHP
RUN echo "date.timezone = America/Guayaquil" > /usr/local/etc/php/conf.d/timezone.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy existing application directory contents
COPY . .

# Ensure upload directories exist and are writable
RUN mkdir -p /var/www/html/public/uploads/products \
    && chown -R www-data:www-data /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/public/uploads

# Force correct ownership for all storage directories
RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage/logs

# Install dependencies if composer.json exists
RUN if [ -f "composer.json" ]; then git config --global --add safe.directory /var/www/html && composer install --no-interaction --prefer-dist --optimize-autoloader; fi

EXPOSE 9000

CMD ["php-fpm"]
