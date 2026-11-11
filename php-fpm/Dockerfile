ARG ENV_SOURCE_IMAGE="php"
ARG PHP_VERSION
ARG OS_RELEASE="bullseye"

FROM golang:alpine AS mhs-builder
RUN go install github.com/axllent/mailpit/sendmail@latest

FROM --platform=$BUILDPLATFORM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}-fpm-${OS_RELEASE}
USER root
ARG PHP_VERSION

ENV MAILHOG_HOST    mailhog
ENV MAILHOG_PORT    1025
ENV USER_ID         501
ENV GROUP_ID        20
ENV OSTYPE          darwin220
ENV ADD_PHP_EXT     ""

# Keep bullseye-security installable after its pool was purged (see the script header).
# Runs before any apt use; every layer built on this image inherits the rewritten sources.
COPY apt-snapshot-bullseye-security /usr/local/bin/apt-snapshot-bullseye-security
RUN apt-snapshot-bullseye-security

# PHP Extension Installer.
# Fetched with curl (not ADD) so a transient network blip retries instead of
# failing the whole build. curl ships in the official php base image.
RUN set -eux; \
    curl -fSL --retry 5 --retry-delay 5 --retry-all-errors \
      -o /usr/local/bin/install-php-extensions \
      https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions

# Copy mhsendmail to PHP
COPY --from=mhs-builder /go/bin/sendmail /usr/local/bin/mhsendmail

# Install PHP Extensions required by Magento OS, Adobe Commerce, and the UCT (pcntl)
RUN chmod +x /usr/local/bin/install-php-extensions;
RUN install-php-extensions apcu;
RUN install-php-extensions amqp;
RUN install-php-extensions bcmath;
RUN install-php-extensions calendar;
RUN install-php-extensions exif;
RUN install-php-extensions ftp;
RUN install-php-extensions intl;
RUN install-php-extensions imap;
RUN install-php-extensions mysqli;
RUN install-php-extensions pcntl;
RUN install-php-extensions pdo_mysql;
RUN install-php-extensions redis;
RUN install-php-extensions soap;
RUN install-php-extensions sockets;
RUN install-php-extensions sodium;
RUN install-php-extensions xsl;
RUN install-php-extensions zip;

# If PHP >= 7.4 Since the installation above is not working for gd, we need to install gd this way
# Also install opcache for PHP >= 7.4
RUN if [ "$(printf "7.4\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" = "7.4" ]; \
    then  \
    apt-get update -y; \
    apt-get install -y zlib1g-dev libpng-dev libjpeg-dev libfreetype6-dev libavif-dev;  \
    \
    docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg; \
    docker-php-ext-install gd; \
    install-php-extensions opcache; \
    fi

# If PHP > 7.2 && < 8.3 install imagick
RUN if [ "$(printf "7.3\n${PHP_VERSION}" | sort -V | tail -n1)" = "${PHP_VERSION}" ] && \
       [ "$(printf "8.3\n${PHP_VERSION}" | sort -V | head -n1)" = "${PHP_VERSION}" ]; \
    then install-php-extensions imagick; fi

# If PHP < 8.2 install mcrypt
RUN if [ "$(printf "8.2\n${PHP_VERSION}" | sort -g | head -n1)" != "8.2" ]; \
    then install-php-extensions mcrypt; fi

# Install helpful utilities
RUN set -eux; \
    apt-get update -y; \
    apt-get install -y --no-install-recommends \
    jq \
    bash \
    patch \
    pv \
    procps \
    nano \
    vim \
    mariadb-client \
    sudo \
    gettext \
    ca-certificates \
    git \
    rsync \
    bash-completion \
    nmap  \
    less \
    zsh \
    fish \
    pwgen \
    openssh-client \
    unzip \
    rsync \
    socat \
    oathtool \
    wget \
    ack  \
    automake  \
    autoconf \
    cron \
    htop \
    chromium \
    imagemagick \
    graphicsmagick \
    qrencode;

COPY docker-entrypoint /usr/local/bin/
COPY etc/profile.d/*.sh /etc/profile.d/
COPY etc/php.d/*.ini /usr/local/etc/php/conf.d
COPY etc/php.d/05-additions.ini.template /usr/local/etc/php/conf.d

RUN set -eux \
    && cp ${PHP_INI_DIR}/php.ini-development ${PHP_INI_DIR}/php.ini \
    && perl -pi -e 's/memory_limit(.*)/memory_limit=-1/g' ${PHP_INI_DIR}/php.ini

# Install Composer
COPY --from=composer:1 /usr/bin/composer /usr/bin/composer1
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer2lts
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer2

# If PHP < 7.2
RUN if [ "$(printf "7.2\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" != "7.2" ]; \
    then mv -f /usr/bin/composer2lts /usr/bin/composer2; fi

# If PHP < 7.3
RUN if [ "$(printf "7.3\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" != "7.3" ]; \
    then apt-get install -y --no-install-recommends bind9utils; fi

# If PHP >= 7.1
# python2 exists up to bullseye only. Bookworm keeps it as a virtual name (`apt-cache show` exits 0),
# so the probe requires a real Version; `set -e` stops a failed install from passing silently.
RUN set -e; if [ "$(printf "7.1\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" = "7.1" ]; \
    then python2_pkg=""; if apt-cache show python2 2>/dev/null | grep -q '^Version:'; then python2_pkg="python2"; fi; \
    apt-get install -y --no-install-recommends libmariadb3 libmariadb-dev python3 python3-pip ${python2_pkg}; \
    sed -i '/disable ghostscript format types/,+6d' /etc/ImageMagick-6/policy.xml; \
    if [ ! -f "/usr/bin/pip" ]; then ln -s /usr/bin/pip3 /usr/bin/pip; ln -s /usr/bin/python3 /usr/bin/python; fi; fi

# node-gyp 7 and older (node-sass 5 and 6, npm's bundled node-gyp on Node 12 and 14) cannot run on
# Python 3.11, and bookworm ships no older Python. Install a checksum-pinned standalone CPython 3.10
# for node-gyp only; the php+node image points NODE_GYP_FORCE_PYTHON at it. On bullseye (Python 3.9)
# nothing is installed.
ARG NODE_GYP_PYTHON_RELEASE="20261009"
ARG NODE_GYP_PYTHON_VERSION="3.10.22"
ARG NODE_GYP_PYTHON_SHA256_AARCH64="ecbe978a7ecbeb3a9aed67bb5d161180701513b4fa8101037b2a3f1ac11e96be"
ARG NODE_GYP_PYTHON_SHA256_X86_64="caa66b308e10f35b8114054df7d927903f971f631cfa3237b84c40d5d5f35549"
RUN set -eux; \
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)'; then \
      case "$(dpkg --print-architecture)" in \
        arm64) py_arch="aarch64"; py_sha256="${NODE_GYP_PYTHON_SHA256_AARCH64}" ;; \
        amd64) py_arch="x86_64"; py_sha256="${NODE_GYP_PYTHON_SHA256_X86_64}" ;; \
        *) echo "No standalone Python for architecture $(dpkg --print-architecture)"; exit 1 ;; \
      esac; \
      py_tar="cpython-${NODE_GYP_PYTHON_VERSION}+${NODE_GYP_PYTHON_RELEASE}-${py_arch}-unknown-linux-gnu-install_only_stripped.tar.gz"; \
      curl -fSL --retry 5 --retry-delay 5 --retry-all-errors -o "/tmp/${py_tar}" \
        "https://github.com/astral-sh/python-build-standalone/releases/download/${NODE_GYP_PYTHON_RELEASE}/${py_tar}"; \
      echo "${py_sha256}  /tmp/${py_tar}" | sha256sum -c -; \
      mkdir -p /opt/python-node-gyp; \
      tar -xzf "/tmp/${py_tar}" -C /opt/python-node-gyp --strip-components=1; \
      rm "/tmp/${py_tar}"; \
      /opt/python-node-gyp/bin/python3 --version; \
    fi

# If PHP >= 7.3
RUN if [ "$(printf "7.3\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" = "7.3" ]; \
    then apt-get install -y --no-install-recommends bind9-utils; fi

# Bookworm's pip refuses system-wide installs (PEP 668) unless told otherwise; older pip ignores
# the variable, so the same line works on bullseye.
RUN PIP_BREAK_SYSTEM_PACKAGES=1 /usr/bin/pip install segno

# Bookworm merges /bin into /usr/bin, so /bin/fish already exists there.
RUN mkdir -p /etc/zsh/zshrc.d  \
    && echo "autoload -U compinit; compinit" > /etc/zsh/zshrc.d/completions.zsh \
    && { [ -e /bin/fish ] || ln -s /usr/bin/fish /bin/fish; }

RUN set -eux; \
	{ \
		echo 'if [ -d /etc/profile.d ]; then'; \
        echo '  for i in /etc/profile.d/*.sh; do'; \
        echo '    if [ -r $i ]; then'; \
        echo '      . $i'; \
        echo '    fi'; \
        echo '  done'; \
        echo '  unset i'; \
        echo 'fi'; \
	} >> /etc/bash.bashrc;

# Install Oh My Zsh including the following Plugins: git, composer and n98-magerun.
# curl retries transient failures; the git clones retry in a loop so a flaky
# network fetch doesn't fail the whole image build.
RUN sh -c "$(curl -fsSL --retry 5 --retry-delay 5 --retry-all-errors https://raw.github.com/ohmyzsh/ohmyzsh/master/tools/install.sh)"
RUN set -eux; \
    ZSH_CUSTOM="${ZSH_CUSTOM:-${ZSH:-/root/.oh-my-zsh}/custom}"; \
    for repo in zsh-users/zsh-autosuggestions zsh-users/zsh-syntax-highlighting; do \
      dest="$ZSH_CUSTOM/plugins/$(basename "$repo")"; \
      mkdir -p "$(dirname "$dest")"; \
      n=0; until git clone "https://github.com/${repo}.git" "$dest"; do \
        n=$((n + 1)); [ "$n" -ge 5 ] && { echo "git clone $repo failed after $n attempts"; exit 1; }; \
        echo "git clone $repo failed, retry $n..."; sleep 10; \
      done; \
    done; \
    sed -i 's/plugins=(git)/plugins=(git composer n98-magerun zsh-autosuggestions zsh-syntax-highlighting)/g' /root/.zshrc

# Configure www-data user as primary php-fpm user for better local dev experience
RUN mkdir -p /home/www-data \
    && chown www-data:www-data /home/www-data \
    && usermod -d /home/www-data www-data \
    && install -d -m 0755 -o www-data -g www-data /home/www-data \
    && chmod 0755 ~www-data \
    && mkdir -p /var/www/html \
    && chown www-data:www-data /var/www/html \
    && echo "www-data ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers.d/nopasswd


# clean up apt packages and temporary files
RUN set -eux; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*

STOPSIGNAL SIGQUIT

ENTRYPOINT ["docker-entrypoint", "php-fpm"]
WORKDIR /var/www/html
EXPOSE 9000