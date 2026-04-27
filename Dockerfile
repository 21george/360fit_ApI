# Use the latest official PHP image with Apache
FROM php:8.3-apache

# Install system dependencies and PHP extensions
RUN apt-get update \
    && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev libzip-dev zip unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip \
    && rm -rf /var/lib/apt/lists/*

# Install MongoDB extension
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better layer caching
COPY composer.json composer.lock* ./

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies as www-data won't have write access later
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Copy project files
COPY . /var/www/html

# Set recommended permissions (more restrictive)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 750 /var/www/html \
    && chmod -R 770 /var/www/html/storage

# Expose port 80
EXPOSE 80

# Set environment variables
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

# Update Apache config to use public as document root
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# Switch to non-root user
USER www-data

# Healthcheck
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/v1/health || exit 1
