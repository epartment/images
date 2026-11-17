# Xdebug variant of a php-fpm image (php-fpm-debug and php-fpm-magento2-debug). Xdebug only
# depends on the PHP build, so it is compiled once per PHP version in php-fpm/tools/Dockerfile and
# copied in here. This file needs the named context `roll-tools`: build it through
# php-fpm/docker-bake.hcl.
ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

COPY --from=roll-tools /out/ /
COPY xdebug3/etc/*.ini /usr/local/etc/php/
COPY xdebug3/etc/conf.d/* /usr/local/etc/php/conf.d/

# Raise xdebug.max_nesting_level to 500, and fail the build when the copied module does not load.
RUN set -eux; \
    sed -i 's/xdebug.max_nesting_level=.*/xdebug.max_nesting_level=500/' /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    php -m | grep -qx xdebug
