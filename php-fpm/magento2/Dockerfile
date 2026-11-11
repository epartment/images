ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION

FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

# The base layer already applies the bullseye-security fix; repeating it (a no-op there) keeps this
# layer safe on any parent that predates it.
COPY apt-snapshot-bullseye-security /usr/local/bin/apt-snapshot-bullseye-security
RUN apt-snapshot-bullseye-security

# Install the mydumper/myloader binaries plus their runtime libs. They are compiled once per PHP
# version and architecture in php-fpm/tools/Dockerfile and reach this build through the named
# context `roll-tools`, so build this file through php-fpm/docker-bake.hcl. `roll mydumper` /
# `roll myloader` (Magento 2 only) execute them inside the php-fpm container.
# The OpenSSL runtime package is named per Debian release (libssl1.1 on bullseye, libssl3 on
# bookworm, libssl3t64 on trixie), so install whichever one this release offers.
COPY --from=roll-tools /dist/usr/local/bin/mydumper /usr/local/bin/mydumper
COPY --from=roll-tools /dist/usr/local/bin/myloader /usr/local/bin/myloader
RUN set -eux; \
    apt-get update -y; \
    libssl_pkg=""; \
    for pkg in libssl3t64 libssl3 libssl1.1; do \
        if apt-cache show "$pkg" 2>/dev/null | grep -q '^Version:'; then libssl_pkg="$pkg"; break; fi; \
    done; \
    [ -n "$libssl_pkg" ] || { echo "no libssl runtime package found"; exit 1; }; \
    apt-get install -y --no-install-recommends \
        libglib2.0-0 libpcre2-8-0 zlib1g "$libssl_pkg" libmariadb3 libatomic1; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*; \
    mydumper --version; \
    myloader --version

# Magerun
# The unversioned phar is whatever n98 released last, and its Composer runtime requirement rises
# over time: the 9.x line needs PHP >= 8.1 and the 10.x line PHP >= 8.2, so older PHP versions
# were shipping a magerun that aborts on every invocation. Each PHP version therefore names the
# newest phar verified to run on it, and the smoke test below turns a future recurrence into a
# failed image build rather than a broken image. Downloaded with curl --retry (not ADD) so a
# transient files.magerun.net blip retries instead of failing the build.
# The version is read from the PHP binary, not from ${PHP_VERSION}: in this stage that name
# resolves to the base php image's ENV (the full version, e.g. 7.4.33, since ENV overrides an ARG
# of the same name), and the build-arg itself carries a node suffix (8.1-node20), so neither
# form would ever match a major.minor pattern.
RUN set -eux; \
    php_minor_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"; \
    case "${php_minor_version}" in \
        7.0|7.1|7.2) magerun_phar="n98-magerun2-4.7.0.phar" ;; \
        7.3)         magerun_phar="n98-magerun2-6.1.1.phar" ;; \
        7.4)         magerun_phar="n98-magerun2-7.5.0.phar" ;; \
        8.0)         magerun_phar="n98-magerun2-8.1.1.phar" ;; \
        8.1)         magerun_phar="n98-magerun2-9.5.1.phar" ;; \
        *)           magerun_phar="n98-magerun2.phar" ;; \
    esac; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /usr/local/bin/n98-magerun2 "https://files.magerun.net/${magerun_phar}"; \
    php /usr/local/bin/n98-magerun2 --version; \
    php /usr/local/bin/n98-magerun2 list > /dev/null

RUN chmod +rx /usr/local/bin/n98-magerun2 \
    && ln -s /usr/local/bin/n98-magerun2 /usr/local/bin/n98-magerun \
    && ln -s /usr/local/bin/n98-magerun2 /usr/local/bin/magerun2 \
    && ln -s /usr/local/bin/n98-magerun2 /usr/local/bin/magerun \
    && ln -s /usr/local/bin/n98-magerun2 /usr/local/bin/mr \
    && ln -s /usr/local/bin/n98-magerun2 /usr/local/bin/n98-magerun2.phar

# Magerun Auto-complete (curl --retry instead of ADD for resilience).
# Unlike ADD, curl won't create missing parent dirs, so create them first.
RUN set -eux; \
    mkdir -p /etc/bash_completion.d /etc/fish/completions /usr/share/zsh/site-functions; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /etc/bash_completion.d/n98-magerun2.phar https://raw.githubusercontent.com/netz98/n98-magerun2/master/res/autocompletion/bash/n98-magerun2.phar.bash; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /etc/fish/completions/n98-magerun2.phar.fish https://raw.githubusercontent.com/netz98/n98-magerun2/master/res/autocompletion/fish/n98-magerun2.phar.fish; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /usr/share/zsh/site-functions/_n98-magerun2 https://raw.githubusercontent.com/netz98/n98-magerun2/master/res/autocompletion/zsh/n98-magerun2.plugin.zsh

RUN sed -i 's/\(#compdef n98-magerun2.phar\)/\1 n98-magerun2=n98-magerun2.phar n98-magerun=n98-magerun2.phar magerun2=n98-magerun2.phar magerun=n98-magerun2.phar/' /usr/share/zsh/site-functions/_n98-magerun2 \
    && sed -i 's/\(compdef _n98_magerun2 n98-magerun2.phar\)/\1 n98-magerun2=n98-magerun2.phar n98-magerun=n98-magerun2.phar magerun2=n98-magerun2.phar magerun=n98-magerun2.phar/' /usr/share/zsh/site-functions/_n98-magerun2 \
    && sed -i 's/\(complete -f -c n98-magerun2.phar\)/\1 -c n98-magerun2 -c n98-magerun -c magerun2 -c magerun/' /etc/fish/completions/n98-magerun2.phar.fish \
    && ln -s /etc/fish/completions/n98-magerun2.phar.fish /etc/fish/completions/n98-magerun2.fish \
    && ln -s /etc/fish/completions/n98-magerun2.phar.fish /etc/fish/completions/n98-magerun.fish \
    && ln -s /etc/fish/completions/n98-magerun2.phar.fish /etc/fish/completions/magerun2.fish \
    && ln -s /etc/fish/completions/n98-magerun2.phar.fish /etc/fish/completions/magerun.fish \
    && chmod +r /etc/fish/completions/n98-magerun2.phar.fish \
    && chmod +r /etc/bash_completion.d/n98-magerun2.phar \
    && chmod +r /usr/share/zsh/site-functions/_n98-magerun2

# Mage2 TV Cache
RUN mkdir /home/www-data/mage-cache-clean \
    && cd /home/www-data/mage-cache-clean \
    && ( n=0; until composer2 require mage2tv/magento-cache-clean; do \
           n=$((n + 1)); [ "$n" -ge 5 ] && { echo "composer require failed after $n attempts"; exit 1; }; \
           echo "composer require failed, retry $n..."; sleep 10; \
         done ) \
    && ln -s /home/www-data/mage-cache-clean/vendor/bin/cache-clean.js /usr/local/bin/cache-clean.js \
    && ln -s /home/www-data/mage-cache-clean/vendor/bin/cache-clean.js /usr/local/bin/cache-clean \
    && chmod +rx -R /home/www-data/mage-cache-clean
