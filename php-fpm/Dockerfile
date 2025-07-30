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

# PHP Extension Installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

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
RUN if [ "$(printf "7.1\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" = "7.1" ]; \
    then apt-get install -y --no-install-recommends libmariadb3 libmariadb-dev python3 python3-pip python2; \
    sed -i '/disable ghostscript format types/,+6d' /etc/ImageMagick-6/policy.xml; \
    if [ ! -f "/usr/bin/pip" ]; then ln -s /usr/bin/pip3 /usr/bin/pip; ln -s /usr/bin/python3 /usr/bin/python; fi; fi

# If PHP >= 7.3
RUN if [ "$(printf "7.3\n${PHP_VERSION}" | sort -g | head -n1 | awk -F"." '{print $1"."$2}')" = "7.3" ]; \
    then apt-get install -y --no-install-recommends bind9-utils; fi

RUN /usr/bin/pip install segno

RUN mkdir -p /etc/zsh/zshrc.d  \
    && echo "autoload -U compinit; compinit" > /etc/zsh/zshrc.d/completions.zsh \
    && ln -s /usr/bin/fish /bin/fish

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

# Install Oh My Zsh including the following Plugins: git, composer and n98-magerun
RUN sh -c "$(curl -fsSL https://raw.github.com/ohmyzsh/ohmyzsh/master/tools/install.sh)"
RUN git clone https://github.com/zsh-users/zsh-autosuggestions.git $ZSH_CUSTOM/plugins/zsh-autosuggestions \
    && git clone https://github.com/zsh-users/zsh-syntax-highlighting.git $ZSH_CUSTOM/plugins/zsh-syntax-highlighting \
    && sed -i 's/plugins=(git)/plugins=(git composer n98-magerun zsh-autosuggestions zsh-syntax-highlighting)/g' /root/.zshrc

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