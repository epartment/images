ARG ES_VERSION
FROM docker.elastic.co/elasticsearch/elasticsearch:${ES_VERSION}
# Retry each plugin so a truncated download (reported as a SHA-512 mismatch) or a dropped
# connection doesn't fail the build; a failed install rolls itself back, so a retry starts clean.
RUN set -eu; \
    for plugin in analysis-phonetic analysis-icu; do \
      n=0; until bin/elasticsearch-plugin install --batch "$plugin"; do \
        n=$((n + 1)); [ "$n" -ge 5 ] && { echo "installing $plugin failed after $n attempts"; exit 1; }; \
        echo "installing $plugin failed, retry $n..."; sleep 10; \
      done; \
    done
