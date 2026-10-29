FROM php:8.2-apache

# MySQL extensions (mysqli is what the app uses; PDO kept for compatibility)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Apache: rewrite + headers, and block direct web access to internal folders.
# The config is written right here instead of COPY'd from a docker/ folder, so the build
# cannot fail because that folder is missing from the repo or excluded by .dockerignore.
RUN a2enmod rewrite headers
RUN printf '%s\n' \
    '# Internal folders are only read by PHP from disk, never by browsers.' \
    '<DirectoryMatch "^/var/www/html/(includes|config|sql|docker)">' \
    '    Require all denied' \
    '</DirectoryMatch>' \
    '<FilesMatch "^(Dockerfile|\.dockerignore)$">' \
    '    Require all denied' \
    '</FilesMatch>' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    'Header always set X-Content-Type-Options "nosniff"' \
    'Header always set X-Frame-Options "SAMEORIGIN"' \
    'Header always set Referrer-Policy "strict-origin-when-cross-origin"' \
    > /etc/apache2/conf-available/app-security.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf app-security servername

# PHP: production defaults + our overrides (errors are logged, never shown to visitors)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf '%s\n' \
    'display_errors = Off' \
    'display_startup_errors = Off' \
    'log_errors = On' \
    'error_reporting = E_ALL' \
    'expose_php = Off' \
    'session.use_strict_mode = 1' \
    'session.cookie_httponly = 1' \
    'session.cookie_samesite = Lax' \
    > "$PHP_INI_DIR/conf.d/zz-app.ini"

# App code
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Fail the build early, and say exactly what is missing, if the repo or .dockerignore left out part of the app.
RUN for f in index.php dashboard.php setup_db.php config/db.php includes/bootstrap.php includes/schema.php includes/layout.php; do \
      [ -f "/var/www/html/$f" ] || { echo "BUILD ERROR: $f is missing from the build context (check the GitHub repo and .dockerignore)"; exit 1; }; \
    done

# Render supplies $PORT; locally it falls back to 80
RUN printf '%s\n' \
    '#!/bin/sh' \
    'PORT="${PORT:-80}"' \
    'sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf' \
    'sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
    'exec apache2-foreground' \
    > /usr/local/bin/start.sh \
 && chmod +x /usr/local/bin/start.sh
CMD ["start.sh"]
