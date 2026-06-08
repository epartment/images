ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

# curl --retry instead of ADD so a transient download blip retries.
RUN set -eux; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /usr/local/bin/n98-magerun https://files.magerun.net/n98-magerun.phar
RUN chmod +rx /usr/local/bin/n98-magerun \
    && ln -s /usr/local/bin/n98-magerun /usr/local/bin/magerun \
    && ln -s /usr/local/bin/n98-magerun /usr/local/bin/mr

RUN set -eux; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /etc/bash_completion.d/n98-magerun.phar https://raw.githubusercontent.com/netz98/n98-magerun/master/res/autocompletion/bash/n98-magerun.phar.bash
RUN chmod +r /etc/bash_completion.d/n98-magerun.phar
