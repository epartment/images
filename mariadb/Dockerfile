ARG MARIADB_VERSION
FROM mariadb:${MARIADB_VERSION}
# Create symlink from /usr/sbin/mysqld → /usr/sbin/mariadbd if it doesn't already exist
RUN if [ ! -e /usr/sbin/mysqld ]; then \
      ln -s /usr/sbin/mariadbd /usr/sbin/mysqld; \
    fi