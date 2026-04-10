FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo_mysql zip

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# 1. Allow Composer to run as root
ENV COMPOSER_ALLOW_SUPERUSER=1

# 2. Copy only dependency files first to leverage caching
COPY composer.json composer.lock /var/www/

# 3. Install dependencies without scripts (prevents Laravel hooks from failing)
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# 4. Now copy the rest of your application
COPY . /var/www

# 5. Optional: Run dump-autoload to capture any new classes in your code
RUN composer dump-autoload --optimize

# Copy existing application directory contents
#COPY . /var/www

# Install dependencies
#RUN composer install --no-dev --no-scripts --optimize-autoloader

# Expose port 80
EXPOSE 80

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=80"]
