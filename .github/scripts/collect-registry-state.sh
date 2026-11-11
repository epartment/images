#!/usr/bin/env bash
# Read the registry facts the matrix generator needs to decide what is unchanged.
#
# Usage: FINGERPRINT_LABEL=<label> collect-registry-state.sh < requests
#
#   requests  one "<kind><TAB><ref>" line per lookup, kind being
#               upstream   value = manifest digest of <ref>
#               published  value = the image label named by $FINGERPRINT_LABEL
#
# Prints "<kind><TAB><ref><TAB><value>" per request, in input order. The value is
# "-" for anything that cannot be read (missing tag, no label, registry error), so
# a lookup failure counts as "changed" for the caller. A multi-platform index
# counts as having the label only when all its platforms carry the same value.
# Exits 0 unless the input is unusable.
set -uo pipefail

INSPECT_PARALLELISM="${INSPECT_PARALLELISM:-8}"
# A transient registry error (429, timeout) would otherwise read as "changed" and rebuild the image.
export INSPECT_ATTEMPTS="${INSPECT_ATTEMPTS:-3}"
export INSPECT_RETRY_DELAY="${INSPECT_RETRY_DELAY:-5}"
: "${FINGERPRINT_LABEL:?FINGERPRINT_LABEL must name the label holding the source fingerprint}"
export FINGERPRINT_LABEL

# Prints "<index> <kind> <ref> <value>" for one request; the index lets the caller
# restore the input order after the parallel run.
inspect_ref() {
  local index="$1" kind="$2" ref="$3" json="" value="" attempt=1
  until json=$(docker buildx imagetools inspect "$ref" --format '{{json .}}' 2>/dev/null); do
    json=""
    [ "$attempt" -ge "$INSPECT_ATTEMPTS" ] && break
    sleep "$((INSPECT_RETRY_DELAY * attempt))"
    attempt=$((attempt + 1))
  done
  if [ -n "$json" ]; then
    if [ "$kind" = "upstream" ]; then
      value=$(jq -r '.manifest.digest // empty' <<<"$json")
    else
      value=$(jq -r --arg label "$FINGERPRINT_LABEL" '
        .image
        | (if has("config") then [.] else [.[]] end)
        | map(.config.Labels[$label] // empty)
        | unique
        | if length == 1 then .[0] else empty end
      ' <<<"$json")
    fi
  fi
  printf '%s\t%s\t%s\t%s\n' "$index" "$kind" "$ref" "${value:--}"
}
export -f inspect_ref

work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT

awk -F '\t' '
  NF == 2 && ($1 == "upstream" || $1 == "published") && $2 != "" { print ++n, $1, $2; next }
  NF > 0 { bad = 1 }
  END { exit (bad || n == 0) }
' >"${work_dir}/requests" || {
  echo "::error::collect-registry-state.sh expects '<upstream|published><TAB><ref>' lines on stdin." >&2
  exit 2
}

# shellcheck disable=SC2016  # $1/$2/$3 belong to the inner bash -c, not this shell
xargs -P "$INSPECT_PARALLELISM" -n 3 bash -c 'inspect_ref "$1" "$2" "$3"' _ \
  <"${work_dir}/requests" | sort -n -k1,1 | cut -f2-
