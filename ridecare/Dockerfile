FROM php:8.2-apache

# MySQL extensions (mysqli is what the app uses; PDO kept for compatibility)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Apache: rewrite + block direct web access to internal folders
RUN a2enmod rewrite headers
COPY docker/apache-security.conf /etc/apache2/conf-available/app-security.conf
RUN a2enconf app-security

# PHP: production defaults + our overrides (errors logged, not shown to visitors)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-custom.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

# App code
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Render supplies $PORT; locally it falls back to 80
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh
CMD ["start.sh"]
