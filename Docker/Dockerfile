FROM php:8.3-fpm-alpine

LABEL maintainer="KutStudio"
LABEL description="KutSocial — Instancia ligera de Fediverso / ActivityPub"

# Dependencias del sistema y librerías para extensiones PHP (SQLite, PostgreSQL, MySQL, GD, Intl, Zip)
RUN apk add --no-cache \
    nginx \
    curl \
    bash \
    sqlite-libs \
    postgresql-libs \
    libpng \
    libjpeg-turbo \
    libwebp \
    freetype \
    libzip \
    icu-libs \
    oniguruma \
    libxml2 \
    && apk add --no-cache --virtual .build-deps \
    sqlite-dev \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    curl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        pdo_pgsql \
        pdo_mysql \
        gd \
        curl \
        mbstring \
        zip \
        intl \
        xml \
        fileinfo \
        opcache \
        pcntl \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Configuración Nginx y PHP
COPY Docker/nginx.conf /etc/nginx/nginx.conf
COPY Docker/default.conf /etc/nginx/http.d/default.conf
COPY Docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-kutsocial.conf
COPY Docker/php.ini /usr/local/etc/php/conf.d/kutsocial.ini

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

RUN mkdir -p /var/www/html/data/uploads /var/www/html/data/cache /var/www/html/data/logs \
    /var/log/nginx /var/lib/nginx/tmp \
    && chown -R www-data:www-data /var/www/html /var/log/nginx /var/lib/nginx

COPY Docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

VOLUME ["/var/www/html/data"]
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
