ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
ARG OS_RELEASE
ARG NODE_VERSION

FROM node:${NODE_VERSION}-${OS_RELEASE:-bullseye} AS node
RUN npm install -g grunt-cli gulp
RUN mv /opt/yarn* /opt/yarn

FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

# Install ARM libraries
RUN apt-get update \
    && apt-get install -y libc6 libstdc++6

# Copy Node to PHP
COPY --from=node /usr/local/include/node /usr/local/include/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node /opt /opt
COPY --from=node /usr/local/bin/node /usr/local/bin/node

# Ensure node binary has correct permissions
RUN chmod +x /usr/local/bin/node

# Debugging step to check node binary
RUN ls -la /usr/local/bin/node \
    && file /usr/local/bin/node \
    && ldd /usr/local/bin/node || echo "ldd command not found" \
    && /usr/local/bin/node -v || echo "Node failed to run"

# Copy PhantomJS
COPY --from=99designs/phantomjs:2.1.1 /usr/local/bin/phantomjs /usr/local/bin/phantomjs

RUN ln -s /usr/local/bin/node /usr/local/bin/nodejs \
    &&