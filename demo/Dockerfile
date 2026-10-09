# Grav 2 + Admin2 demo with the Supertext Translation plugin.
# Build context is the repository root:  docker build -f demo/Dockerfile .
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip intl opcache \
 && a2enmod rewrite expires headers \
 && rm -rf /var/lib/apt/lists/*

COPY demo/php.ini /usr/local/etc/php/conf.d/grav.ini
COPY demo/apache.conf /etc/apache2/conf-available/grav.conf
RUN a2enconf grav

# Grav core with Admin2 and the API plugin bundled (GitHub release asset:
# getgrav.org only serves the latest version).
ARG GRAV_VERSION=2.2.4
RUN curl -fsSL -o /tmp/grav.zip "https://github.com/getgrav/grav/releases/download/${GRAV_VERSION}/grav-admin-v${GRAV_VERSION}.zip" \
 && unzip -q /tmp/grav.zip -d /tmp \
 && rm -rf /var/www/html \
 && mv /tmp/grav-admin /var/www/html \
 && rm /tmp/grav.zip \
 # Keep the stock user/ content as the seed for a fresh volume.
 && cp -a /var/www/html/user /usr/src/grav-user

# The plugin itself, always from this build (never from the volume).
COPY supertext-translation.php supertext-translation.yaml blueprints.yaml CHANGELOG.md LICENSE README.md /var/www/html/user/plugins/supertext-translation/
COPY classes /var/www/html/user/plugins/supertext-translation/classes
COPY admin-next /var/www/html/user/plugins/supertext-translation/admin-next
COPY languages.yaml /var/www/html/user/plugins/supertext-translation/

COPY demo /usr/src/demo
RUN chmod +x /usr/src/demo/entrypoint.sh \
 && chown -R www-data:www-data /var/www/html

ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["/usr/src/demo/entrypoint.sh"]
