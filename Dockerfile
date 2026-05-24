FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && docker-php-ext-install mysqli \
    && a2enmod headers rewrite expires \
    && rm -rf /var/lib/apt/lists/*

COPY infrastructure/apache/ridesync.conf /etc/apache2/conf-available/ridesync.conf
RUN a2enconf ridesync

WORKDIR /var/www/html/ridesync
COPY . /var/www/html/ridesync

RUN mkdir -p storage/cache storage/logs storage/rate_limits storage/secure_driver_documents storage/uploads storage/temp storage/exports uploads/profile_photos uploads/driver_documents \
    && chown -R www-data:www-data storage uploads \
    && find storage uploads -type d -exec chmod 0750 {} \; \
    && find storage uploads -type f -exec chmod 0640 {} \;

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/ridesync/api/live.php >/dev/null || exit 1
