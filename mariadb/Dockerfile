ARG MARIADB_VERSION
FROM mariadb:${MARIADB_VERSION}
# Create symlink from /usr/sbin/mysqld → /usr/sbin/mariadbd if it doesn't already exist
RUN if [ ! -e /usr/sbin/mysqld ]; then \
      ln -s /usr/sbin/mariadbd /usr/sbin/mysqld; \
    fi

# Add mydumper repo + key and install mydumper
RUN set -eux; \
  apt-get update; \
  apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
  install -m 0755 -d /etc/apt/keyrings; \
  # Import key E82E34BA from Ubuntu keyserver, store as dearmored keyring
  gpg --keyserver hkps://keyserver.ubuntu.com --recv-keys E82E34BA; \
  gpg --export --armor E82E34BA | gpg --dearmor -o /etc/apt/keyrings/mydumper.gpg; \
  chmod 0644 /etc/apt/keyrings/mydumper.gpg; \
  # Detect Ubuntu codename and add the repo with signed-by pinning
  . /etc/os-release; \
  echo "deb [signed-by=/etc/apt/keyrings/mydumper.gpg] https://mydumper.github.io/mydumper/repo/apt/ubuntu ${VERSION_CODENAME} main" \
    > /etc/apt/sources.list.d/mydumper.list; \
  apt-get update; \
  apt-get install -y --no-install-recommends mydumper; \
  rm -rf /var/lib/apt/lists/*