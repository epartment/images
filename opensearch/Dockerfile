ARG OPENSEARCH_VERSION
FROM docker.io/opensearchproject/opensearch:${OPENSEARCH_VERSION}
# Retry each plugin so a dropped connection mid-download doesn't fail the build.
RUN set -eu; \
    for plugin in analysis-phonetic analysis-icu; do \
      n=0; until bin/opensearch-plugin install "$plugin"; do \
        n=$((n + 1)); [ "$n" -ge 5 ] && { echo "installing $plugin failed after $n attempts"; exit 1; }; \
        echo "installing $plugin failed, retry $n..."; sleep 10; \
      done; \
    done
