FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && docker-php-ext-install mysqli opcache \
    && a2enmod headers rewrite expires deflate \
    && rm -rf /var/lib/apt/lists/*

# PHP performance tuning
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.enable_cli=0'; \
    echo 'opcache.jit=1255'; \
    echo 'opcache.jit_buffer_size=64M'; \
    echo 'session.lazy_write=1'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
    echo 'output_buffering=4096'; \
    echo 'zlib.output_compression=On'; \
    echo 'zlib.output_compression_level=4'; \
    } > /usr/local/etc/php/conf.d/perf.ini

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
