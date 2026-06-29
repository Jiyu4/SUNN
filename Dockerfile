# SUNN Conference Website - Dockerfile
# Multi-stage build for optimized production image

# ============================================
# Stage 1: Composer dependencies
# ============================================
FROM composer:2.6 AS vendor

WORKDIR /app

# Copy composer files
COPY backend.php setup-admin.php ./

# Install dependencies (none required for this project)
# COPY composer.json composer.lock ./
# RUN composer install --no-dev --optimize-autoloader

# ============================================
# Stage 2: Production image
# ============================================
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install zip pdo pdo_json

# Enable Apache modules
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY --from=vendor /app ./
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Create data directories with proper permissions
RUN mkdir -p /var/www/html/data/uploads \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

# Apache configuration
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache
CMD ["apache2-foreground"]
