ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION

# ---------------------------------------------------------------------------
# Build mydumper/myloader from source.
#
# Upstream ships no Debian "bullseye arm64" package, yet these images target
# Debian bullseye on both linux/amd64 and linux/arm64, so a prebuilt .deb can't
# cover both arches. Building from source (the same way the official bullseye
# package is produced) works on either arch. Done in a throwaway builder stage
# so the build toolchain never lands in the final image.
# ---------------------------------------------------------------------------
FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION} AS mydumper-builder
USER root
# The source image may predate the bullseye-security fix in the base layer (layers build on the
# previously published tag), so apply it here too before touching apt.
COPY apt-snapshot-bullseye-security /usr/local/bin/apt-snapshot-bullseye-security
RUN apt-snapshot-bullseye-security
ARG MYDUMPER_VERSION=1.0.1-3
RUN set -eux; \
    apt-get update -y; \
    apt-get install -y --no-install-recommends \
        cmake g++ make git pkg-config \
        libglib2.0-dev libpcre2-dev zlib1g-dev libssl-dev \
        libmariadb-dev libmariadb-dev-compat; \
    git clone --depth 1 --branch "v${MYDUMPER_VERSION}" https://github.com/mydumper/mydumper.git /tmp/mydumper; \
    cd /tmp/mydumper; \
    cmake -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local .; \
    make -j"$(nproc)"; \
    make install DESTDIR=/dist

# ---------------------------------------------------------------------------
ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

# The source image may predate the bullseye-security fix in the base layer (layers build on the
# previously published tag), so apply it here too before touching apt.
COPY apt-snapshot-bullseye-security /usr/local/bin/apt-snapshot-bullseye-security
RUN apt-snapshot-bullseye-security

# Install the mydumper/myloader binaries built above plus their runtime libs.
# `roll mydumper` / `roll myloader` (Magento 2 only) execute these inside the
# php-fpm container.
COPY --from=mydumper-builder /dist/usr/local/bin/mydumper /usr/local/bin/mydumper
COPY --from=mydumper-builder /dist/usr/local/bin/myloader /usr/local/bin/myloader
RUN set -eux; \
    apt-get update -y; \
    apt-get install -y --no-install-recommends \
        libglib2.0-0 libpcre2-8-0 zlib1g libssl1.1 libmariadb3 libatomic1; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*; \
    mydumper --version; \
    myloader --version

# Magerun
# The unversioned phar is whatever n98 released last, and its Composer runtime requirement rises
# over time: the current 9.x line needs PHP >= 8.1, so PHP 7.4 and 8.0 were shipping a magerun
# that aborts on every invocation. Each PHP version therefore names the newest phar verified to
# run on it, and the smoke test below turns a future recurrence into a failed image build rather
# than a broken image. Downloaded with curl --retry (not ADD) so a transient files.magerun.net
# blip retries instead of failing the build.
RUN set -eux; \
    case "${PHP_VERSION}" in \
        7.0|7.1|7.2) magerun_phar="n98-magerun2-4.7.0.phar" ;; \
        7.3)         magerun_phar="n98-magerun2-6.1.1.phar" ;; \
        7.4)         magerun_phar="n98-magerun2-7.5.0.phar" ;; \
        8.0)         magerun_phar="n98-magerun2-8.1.1.phar" ;; \
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
