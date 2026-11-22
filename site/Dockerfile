# --- Pages statiques (Astro) + photos d'origine ------------------------
FROM node:24-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm test && npm run build && node scripts/assemble.mjs

# --- PHP + Apache, comme l'hébergement (GD pour les photos) -----------
FROM php:8.3-apache AS php-base
RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd exif \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers expires \
 && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-security.conf \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nupload_max_filesize=16M\npost_max_size=20M\n' > /usr/local/etc/php/conf.d/zz-prod.ini \
 && mkdir -p /data && chown www-data:www-data /data
ENV DATA_DIR=/data TRUST_PROXY=1 LOG_FILE=stderr

# --- Image de production ------------------------------------------------
FROM php-base
COPY --from=build /app/build/public_html/ /var/www/html/
VOLUME /data
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/config") ? 0 : 1);'
