FROM richarvey/nginx-php-fpm:3.1.6

# 1. Install Node.js, NPM, and MongoDB extension
RUN apk add --no-cache $PHPIZE_DEPS openssl-dev nodejs npm \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY . .

# 2. Build frontend assets
RUN npm ci --audit false && npm run build

# 3. Install composer dependencies
RUN composer install --no-dev --optimize-autoloader

# 4. Copy startup script — richarvey/nginx-php-fpm runs all scripts/xx-*.sh at boot,
#    by which point Render has already injected the real APP_URL env var.
COPY scripts/00-scribe-generate.sh /var/www/html/scripts/00-scribe-generate.sh
RUN chmod +x /var/www/html/scripts/00-scribe-generate.sh

# Image config
ENV SKIP_COMPOSER 0
ENV WEBROOT /var/www/html/public
ENV PHP_CATCHALL 1
ENV PHP_ERRORS_STDERR 1
ENV RUN_SCRIPTS 1
ENV REAL_IP_HEADER 1

# Laravel config
ENV APP_ENV production
ENV APP_DEBUG false
ENV LOG_CHANNEL stderr

# Allow composer to run as root
ENV COMPOSER_ALLOW_SUPERUSER 1

CMD ["/start.sh"]
