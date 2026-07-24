FROM richarvey/nginx-php-fpm:3.1.6

# 1. Install Node.js, NPM, and MongoDB extension
RUN apk add --no-cache $PHPIZE_DEPS openssl-dev nodejs npm \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY . .

# 2. Build frontend assets
RUN npm ci --audit false && npm run build

# 3. Hardcode the production URL for the Scribe compiler
ENV APP_URL=https://joboffers-emoj.onrender.com

# 4. Install composer dependencies and immediately compile Scribe
RUN composer install --no-dev --optimize-autoloader \
    && php artisan config:clear \
    && php artisan scribe:generate

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
