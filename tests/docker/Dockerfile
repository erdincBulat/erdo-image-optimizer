# Test matrix image: PHP <PHP> with Apache or nginx + PHP-FPM, GD (WebP, AVIF on
# PHP 8.1+) and optionally Imagick, plus WP-CLI. Built and driven by matrix.ps1.
ARG PHP=8.3
ARG VARIANT=apache
FROM php:${PHP}-${VARIANT}

ARG VARIANT=apache
ARG IMAGICK=1

# PHP 7.4 images are Debian 11, whose packages now live on archive.debian.org.
RUN set -eux; \
    if grep -q bullseye /etc/os-release; then \
        echo 'deb http://archive.debian.org/debian bullseye main' > /etc/apt/sources.list; \
        echo 'deb http://archive.debian.org/debian-security bullseye-security main' >> /etc/apt/sources.list; \
        rm -f /etc/apt/sources.list.d/*; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev libzip-dev less; \
    avif=''; \
    if php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then apt-get install -y --no-install-recommends libavif-dev; avif='--with-avif'; fi; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype $avif; \
    docker-php-ext-install -j"$(nproc)" gd mysqli zip exif; \
    if [ "$IMAGICK" = 1 ]; then \
        apt-get install -y --no-install-recommends libmagickwand-dev; \
        pecl install imagick; docker-php-ext-enable imagick; \
    fi; \
    if [ "$VARIANT" = fpm ]; then apt-get install -y --no-install-recommends nginx; fi; \
    if [ "$VARIANT" = apache ]; then a2enmod rewrite headers; fi; \
    curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
    chmod +x /usr/local/bin/wp; \
    printf 'memory_limit=512M\nupload_max_filesize=64M\npost_max_size=64M\nmax_execution_time=300\n' > /usr/local/etc/php/conf.d/erdo.ini; \
    rm -rf /var/lib/apt/lists/*

COPY nginx.conf /etc/nginx/sites-available/default
RUN if [ "$VARIANT" = fpm ]; then mkdir -p /etc/nginx/erdo && touch /etc/nginx/erdo/webp.conf && chown -R www-data /etc/nginx/erdo; fi

CMD if [ -x /usr/sbin/nginx ]; then php-fpm -D && exec nginx -g 'daemon off;'; else exec apache2-foreground; fi
