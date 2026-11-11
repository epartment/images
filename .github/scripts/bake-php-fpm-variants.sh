#!/usr/bin/env bash
# Build the variant images of one chunk of Node versions for one PHP version and
# one architecture, one `docker buildx bake` call per Node version.
#
# Usage: bake-php-fpm-variants.sh        (run from anywhere; bakes from the repo root)
#
# Environment:
#   PHP_VERSION, PHP_OS_RELEASE, XDEBUG_TYPE, ARCH_SUFFIX, REGISTRY, RUN_ID
#                       bake variables, see php-fpm/docker-bake.hcl
#   CHUNK_NODES         JSON list of {"v","os","gyp_python","fingerprint"}, the chunk's nodes
#   PUSH                true pushes the per-arch tags and builds on the pushed base and tools
#                       images; anything else builds into the cache only (local runs)
#   BASE_FINGERPRINT    expected fingerprint of the base (PUSH=true)
#   BASE_BUILT          true when the base job of THIS run built the base (PUSH=true)
#   BUILDER             optional buildx builder name (bake and prune use the same one)
#   BAKE_ARGS           optional extra bake flags, for example --no-cache
#   MIN_FREE_GB         free space on / below which the builder cache is pruned between
#                       Node versions (default 6)
#
# A failing Node version never stops the next one (`bake --jobs=defer-error` does not
# exist in buildx 0.33), so the script fails at the end. With PUSH=true the base and
# tools per-arch tags must be this run's (BASE_BUILT=true) or at least carry the
# expected fingerprint; otherwise nothing is built and the merge job keeps the previous
# images (exit 0 when this run's base job failed, exit 1 when a reused base is stale).
set -uo pipefail

: "${PHP_VERSION:?}" "${PHP_OS_RELEASE:?}" "${XDEBUG_TYPE:?}" "${ARCH_SUFFIX:?}"
: "${REGISTRY:?}" "${RUN_ID:?}" "${CHUNK_NODES:?}"
PUSH="${PUSH:-false}"
MIN_FREE_GB="${MIN_FREE_GB:-6}"
FINGERPRINT_LABEL="org.epartment.roll.source-fingerprint"
RUN_ID_LABEL="org.epartment.roll.ci-run-id"
BAKE_FILE="php-fpm/docker-bake.hcl"
BASE_TAG="${REGISTRY}/php-fpm:${PHP_VERSION}-arch-${ARCH_SUFFIX}"
TOOLS_TAG="${REGISTRY}/php-fpm:${PHP_VERSION}-tools-arch-${ARCH_SUFFIX}"
export PHP_VERSION PHP_OS_RELEASE XDEBUG_TYPE ARCH_SUFFIX REGISTRY RUN_ID PUSH

builder_args=()
if [ -n "${BUILDER:-}" ]; then
  builder_args=(--builder "$BUILDER")
fi

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

# Prints "<fingerprint> <run-id>" of a pushed tag, "-" for a value that cannot be read.
read_labels() {
  local json
  if ! json=$(docker buildx imagetools inspect "$1" --format '{{json .}}' 2>/dev/null); then
    echo "- -"
    return
  fi
  jq -r --arg fingerprint "$FINGERPRINT_LABEL" --arg run "$RUN_ID_LABEL" '
    .image as $image
    | ($image | if has("config") then [.] else [.[]] end) as $configs
    | [$fingerprint, $run]
    | map(. as $key
        | $configs
        | map(.config.Labels[$key] // empty)
        | unique
        | if length == 1 then .[0] else "-" end)
    | join(" ")
  ' <<<"$json" 2>/dev/null || echo "- -"
}

# Succeeds when the tag carries the expected fingerprint and, for a base built in this
# run, this run's id.
base_tag_usable() {
  local fingerprint run
  read -r fingerprint run < <(read_labels "$1")
  [ "$fingerprint" = "${BASE_FINGERPRINT}" ] || return 1
  [ "${BASE_BUILT}" != "true" ] || [ "$run" = "$RUN_ID" ]
}

free_gb_on_root() {
  df -Pk / | awk 'NR == 2 { print int($4 / 1048576) }'
}

if [ "$PUSH" = "true" ]; then
  : "${BASE_FINGERPRINT:?}" "${BASE_BUILT:?}"
  if ! base_tag_usable "$BASE_TAG" || ! base_tag_usable "$TOOLS_TAG"; then
    # A failed base job of this run is already reported there. A reused base that no longer
    # carries the planned fingerprint means the per-arch tags changed behind the plan: fail, so
    # the skipped variants are visible.
    if [ "${BASE_BUILT}" = "true" ]; then
      echo "::warning::Base or tools image of PHP ${PHP_VERSION} (${ARCH_SUFFIX}) from this run is missing; not building variants, the merge keeps the previous images."
      exit 0
    fi
    echo "::error::Base or tools image of PHP ${PHP_VERSION} (${ARCH_SUFFIX}) does not carry the planned fingerprint ${BASE_FINGERPRINT}; not building variants."
    exit 1
  fi
  export BASE_FINGERPRINT
  export BASE_CONTEXT="docker-image://${BASE_TAG}"
  export TOOLS_CONTEXT="docker-image://${TOOLS_TAG}"
fi

nodes_file=$(mktemp)
trap 'rm -f "$nodes_file"' EXIT
# One "<node> <single-entry NODES json> <NODE_FINGERPRINTS json>" line per Node version.
jq -r '.[] | [
  .v,
  ([{v, os, gyp_python: (.gyp_python // "")}] | tojson),
  ({(.v): (.fingerprint // "")} | tojson)
] | join(" ")' <<<"$CHUNK_NODES" >"$nodes_file" || {
  echo "::error::CHUNK_NODES is not a valid JSON list of Node versions." >&2
  exit 1
}
total=$(wc -l <"$nodes_file" | tr -d ' ')

index=0
failed=""
summary_lines=""
while read -r node nodes_json fingerprints_json; do
  index=$((index + 1))
  echo "::group::Variants of PHP ${PHP_VERSION} with Node ${node} (${ARCH_SUFFIX})"
  # shellcheck disable=SC2086  # BAKE_ARGS is a list of flags
  if NODES="$nodes_json" NODE_FINGERPRINTS="$fingerprints_json" \
    docker buildx bake "${builder_args[@]+"${builder_args[@]}"}" -f "$BAKE_FILE" --progress=plain ${BAKE_ARGS:-} variants </dev/null; then
    summary_lines="${summary_lines}Node ${node}: built"$'\n'
  else
    summary_lines="${summary_lines}Node ${node}: FAILED"$'\n'
    failed="${failed} ${node}"
  fi
  echo "::endgroup::"
  df -h /

  if [ "$index" -lt "$total" ] && [ "$(free_gb_on_root)" -lt "$MIN_FREE_GB" ]; then
    echo "Less than ${MIN_FREE_GB} GB free; pruning the builder cache (pushed images stay in the registry)."
    docker buildx prune "${builder_args[@]+"${builder_args[@]}"}" -af
  fi
done <"$nodes_file"

printf '%s' "$summary_lines"
if [ -n "$failed" ]; then
  echo "::error::Variant builds failed for PHP ${PHP_VERSION} (${ARCH_SUFFIX}), Node:${failed}"
  if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    echo "Failed variant builds, PHP ${PHP_VERSION} (${ARCH_SUFFIX}), Node:${failed}" >>"$GITHUB_STEP_SUMMARY"
  fi
  exit 1
fi
