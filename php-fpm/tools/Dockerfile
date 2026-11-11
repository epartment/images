# Node-independent build outputs of one PHP version and architecture: the mydumper/myloader
# binaries for the magento2 images and the Xdebug module for the debug images. Compiled once from
# the base image instead of once per Node version, and consumed by the variant Dockerfiles through
# the named context `roll-tools`. `roll-base` is the base image, supplied by php-fpm/docker-bake.hcl.

# mydumper/myloader from source: upstream ships no Debian arm64 package, so a prebuilt .deb can't
# cover both architectures. Building it the way the Debian package is produced works on either,
# and the toolchain stays in this stage.
FROM roll-base AS mydumper
USER root
ARG MYDUMPER_VERSION=1.0.1-3
RUN set -eux; \
    apt-get update -y; \
    apt-get install -y --no-install-recommends \
        cmake g++ make git pkg-config \
        libglib2.0-dev libpcre2-dev zlib1g-dev libssl-dev \
        libmariadb-dev libmariadb-dev-compat; \
    n=0; until git clone --depth 1 --branch "v${MYDUMPER_VERSION}" https://github.com/mydumper/mydumper.git /tmp/mydumper; do \
      n=$((n + 1)); [ "$n" -ge 5 ] && { echo "git clone mydumper failed after $n attempts"; exit 1; }; \
      echo "git clone mydumper failed, retry $n..."; rm -rf /tmp/mydumper; sleep 10; \
    done; \
    cd /tmp/mydumper; \
    cmake -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/usr/local .; \
    make -j"$(nproc)"; \
    make install DESTDIR=/dist

# The module is staged under /out at its final absolute path, so a variant needs one COPY. The
# download steps here retry: a failure of this image leaves every variant of the PHP version
# unbuilt for the run, not just one image.
FROM roll-base AS xdebug
USER root
ARG XDEBUG_TYPE="xdebug-stable"
RUN set -eux; \
    n=0; until install-php-extensions "${XDEBUG_TYPE}"; do \
      n=$((n + 1)); [ "$n" -ge 5 ] && { echo "Xdebug install failed after $n attempts"; exit 1; }; \
      echo "Xdebug install failed, retry $n..."; sleep 10; \
    done; \
    ext_dir="$(php-config --extension-dir)"; \
    mkdir -p "/out${ext_dir}"; \
    cp "${ext_dir}/xdebug.so" "/out${ext_dir}/"

FROM scratch
COPY --from=mydumper /dist /dist
COPY --from=xdebug /out /out
