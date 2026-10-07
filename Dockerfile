# PHP 8.3 + Apache
FROM php:8.4-apache

# --------------------------------------------------
# System dependencies + PHP extensions
# --------------------------------------------------
RUN apt-get update --allow-releaseinfo-change && apt-get install -y \
    --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    zip \
    git \
    curl \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        zip \
        mbstring \
        xml \
        bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# --------------------------------------------------
# MongoDB PHP extension
# --------------------------------------------------
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# --------------------------------------------------
# Apache
# --------------------------------------------------
RUN a2enmod rewrite

WORKDIR /var/www/html

# --------------------------------------------------
# Composer
# --------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy Composer files first for Docker cache
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-dev \
    --no-scripts

# --------------------------------------------------
# Copy Laravel application
# --------------------------------------------------
COPY . .

# --------------------------------------------------
# Laravel directories
# --------------------------------------------------
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# --------------------------------------------------
# Permissions
# --------------------------------------------------
RUN chown -R www-data:www-data \
        /var/www/html/public \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && find /var/www/html/public -type d -exec chmod 755 {} + \
    && find /var/www/html/public -type f -exec chmod 644 {} + \
    && chmod -R 775 \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

# --------------------------------------------------
# Apache document root
# --------------------------------------------------
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf

# Allow Laravel .htaccess
RUN printf '<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' \
    > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

EXPOSE 80

# Health check
HEALTHCHECK --interval=30s \
    --timeout=5s \
    --start-period=20s \
    --retries=3 \
    CMD curl -f http://localhost/v1/health || exit 1

CMD ["apache2-foreground"]
