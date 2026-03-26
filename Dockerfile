FROM drupal:11-php8.3-fpm-alpine AS base

# Install nginx inside the container (combined image)
RUN apk add --no-cache nginx

# Copy nginx config
COPY deploy/nginx-drupal.conf /etc/nginx/http.d/default.conf

# Set working directory
WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code
COPY web/ web/
COPY config/ config/

# Fix permissions
RUN chown -R www-data:www-data web/sites/default/files 2>/dev/null || true \
    && mkdir -p web/sites/default/files \
    && chown -R www-data:www-data web/sites/default/files

# Expose port for host nginx to proxy to
EXPOSE 8080

# Start both php-fpm and nginx
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
