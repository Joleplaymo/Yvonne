# --- Pages statiques (Astro) ------------------------------------------
FROM node:24-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm test && npm run build && node scripts/assemble.mjs

# --- Serveur PHP + Apache (même code que sur Hostinger) ---------------
FROM php:8.3-apache
RUN a2enmod rewrite headers expires \
 && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-security.conf \
 && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/zz-prod.ini \
 && mkdir -p /data && chown www-data:www-data /data
ENV DATA_DIR=/data TRUST_PROXY=1 LOG_FILE=stderr
COPY --from=build /app/build/public_html/ /var/www/html/
VOLUME /data
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api/config") ? 0 : 1);'
