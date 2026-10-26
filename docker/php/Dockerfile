ARG PHP_VERSION

FROM php:${PHP_VERSION}-fpm-alpine

# Установка и компиляция расширений с помощью php-extension-installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions  \
    mysqli \
    pdo_mysql \
    gd \
    zip \
    intl \
    opcache \
    bcmath \
    exif \
    sockets \
    redis

ARG INSTALL_XDEBUG=false
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then install-php-extensions xdebug; fi

RUN apk add --no-cache  \
    # замена sendmail для mail(); \
    msmtp \
    # usermod/groupmod \
    shadow \
    # база часовых поясов \
    tzdata

ARG HOST_UID
ARG HOST_GID
RUN groupmod -o -g ${HOST_GID} www-data \
    && usermod -o -u ${HOST_UID} -g www-data www-data

RUN mkdir -p /var/lib/php/sessions \
    && chown www-data:www-data /var/lib/php/sessions \
    && chmod 700 /var/lib/php/sessions

WORKDIR /var/www/html