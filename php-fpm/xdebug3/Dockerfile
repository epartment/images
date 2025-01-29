ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
ARG XDEBUG_TYPE="xdebug-stable"

USER root

RUN export PHP_INI_DIR="/usr/local/etc/php"; install-php-extensions ${XDEBUG_TYPE}

COPY xdebug3/etc/*.ini /usr/local/etc/php/
COPY xdebug3/etc/conf.d/* /usr/local/etc/php/conf.d/

# Set the xdebug.max_nesting_level to 500
RUN sed -i 's/xdebug.max_nesting_level=.*/xdebug.max_nesting_level=500/' /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
