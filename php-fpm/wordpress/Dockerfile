ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

# Wordpress Cli tool (curl --retry instead of ADD so a transient blip retries)
RUN set -eux; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /usr/local/bin/wp-cli https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# Create wp alias for wp-cli
RUN chmod +rx /usr/local/bin/wp-cli \
    && ln -s /usr/local/bin/wp-cli /usr/local/bin/wp