ARG ENV_SOURCE_IMAGE
ARG PHP_VERSION
ARG OS_RELEASE
ARG NODE_VERSION

FROM node:${NODE_VERSION}-${OS_RELEASE:-bullseye} AS node
# Retry the global install so a transient npm registry blip doesn't fail the build.
RUN set -eux; \
    n=0; until npm install -g grunt-cli gulp; do \
      n=$((n + 1)); [ "$n" -ge 5 ] && { echo "npm install failed after $n attempts"; exit 1; }; \
      echo "npm install failed, retry $n..."; sleep 10; \
    done
RUN mv /opt/yarn* /opt/yarn

FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}
USER root

# Install libraries and libc-bin for ldd command
RUN apt-get update \
    && apt-get install -y libc6 libstdc++6 libc-bin

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
    && ldd /usr/local/bin/node \
    && /usr/local/bin/node -v || echo "Node failed to run"

# Copy PhantomJS
COPY --from=99designs/phantomjs:2.1.1 /usr/local/bin/phantomjs /usr/local/bin/phantomjs

RUN ln -s /usr/local/bin/node /usr/local/bin/nodejs \
    && ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/gulp/bin/gulp.js /usr/local/bin/gulp \
    && ln -s /usr/local/lib/node_modules/grunt-cli/bin/grunt /usr/local/bin/grunt \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && ln -s /opt/yarn/bin/yarn /usr/local/bin/yarn \
    && ln -s /opt/yarn/bin/yarnpkg /usr/local/bin/yarnpkg

# Copy Yarn
COPY --from=node /opt /opt