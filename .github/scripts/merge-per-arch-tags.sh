#!/usr/bin/env bash
# Assemble the multi-arch manifest of every version from the per-architecture
# tags the build jobs pushed in this workflow run.
#
# Usage: merge-per-arch-tags.sh <registry-image> <run-id> < expected-entries
#
#   expected-entries  one "<version> <arch-suffix>" line per build-matrix entry,
#                     e.g. "8.3-node20 x86" and "8.3-node20 arm64".
#
# Each build leg pushes <registry-image>:<version>-arch-<arch-suffix>, labelled
# with the id of the run that built it (label key in $PER_ARCH_RUN_LABEL). A
# version is published only when every expected arch tag carries THIS run's id;
# otherwise it keeps its previous multi-arch tag, so a failed leg never pairs a
# stale image with a fresh one. Hand-off goes through the registry, not workflow
# artifacts, because a run lists only about its first 1000 artifacts.
#
# GitHub shows at most 10 warning annotations per step, so the closing summary
# annotation (and the step summary) list every skipped version in one place.
set -uo pipefail

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <registry-image> <run-id> < expected-entries" >&2
  exit 2
fi

REGISTRY_IMAGE="$1"
RUN_ID="$2"
: "${PER_ARCH_RUN_LABEL:?PER_ARCH_RUN_LABEL must name the label holding the build run id}"
INSPECT_PARALLELISM="${INSPECT_PARALLELISM:-8}"
export REGISTRY_IMAGE PER_ARCH_RUN_LABEL

work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT

# Prints "<version> <suffix> <digest> <run-id>" for one per-arch tag, with "-"
# for a digest or run id that cannot be read (tag missing, or no label). The
# label is read from the image config; an index (built for several platforms)
# counts only when all its platforms carry the same run id.
inspect_per_arch_tag() {
  local version="$1" suffix="$2" json digest="" run=""
  if json=$(docker buildx imagetools inspect "${REGISTRY_IMAGE}:${version}-arch-${suffix}" --format '{{json .}}' 2>/dev/null); then
    digest=$(jq -r '.manifest.digest // empty' <<<"$json")
    run=$(jq -r --arg label "$PER_ARCH_RUN_LABEL" '
      .image
      | (if has("config") then [.] else [.[]] end)
      | map(.config.Labels[$label] // empty)
      | unique
      | if length == 1 then .[0] else empty end
    ' <<<"$json")
  fi
  echo "${version} ${suffix} ${digest:--} ${run:--}"
}
export -f inspect_per_arch_tag

# Groups the inspected tags per version. Prints "publish <version> <digest>..."
# when every expected arch was built by this run, otherwise
# "skip <version> <suffix>:<state>..." naming each arch that is missing or stale.
plan_versions() {
  awk -v run_id="$RUN_ID" '
    {
      version = $1
      if (!(version in seen)) { seen[version] = 1; order[++count] = version }
      if ($3 != "-" && $4 == run_id) {
        digests[version] = digests[version] " " $3
      } else {
        state = ($3 == "-") ? "missing" : "stale"
        problems[version] = problems[version] " " $2 ":" state
      }
    }
    END {
      for (i = 1; i <= count; i++) {
        version = order[i]
        if (version in problems) print "skip " version problems[version]
        else print "publish " version digests[version]
      }
    }
  '
}

sort -u >"${work_dir}/expected"
if [ ! -s "${work_dir}/expected" ]; then
  echo "::error::No expected versions were passed for ${REGISTRY_IMAGE}; is the build matrix empty?"
  exit 1
fi

echo "Inspecting $(wc -l <"${work_dir}/expected" | tr -d ' ') per-arch tag(s) of ${REGISTRY_IMAGE}..."
# shellcheck disable=SC2016  # $1/$2 belong to the inner bash -c, not this shell
xargs -P "$INSPECT_PARALLELISM" -n 2 bash -c 'inspect_per_arch_tag "$1" "$2"' _ \
  <"${work_dir}/expected" | sort >"${work_dir}/inspected"

published=0
skipped=0
skipped_list=""
while read -r action version rest; do
  if [ "$action" = "skip" ]; then
    echo "::warning::Skipping ${REGISTRY_IMAGE}:${version} (${rest}); keeping previous image."
    skipped=$((skipped + 1))
    skipped_list="${skipped_list} ${version}"
    continue
  fi

  references=""
  for digest in $rest; do
    references="${references} ${REGISTRY_IMAGE}@${digest}"
  done
  echo "Creating manifest for ${version}:${references}"
  # shellcheck disable=SC2086
  if docker buildx imagetools create -t "${REGISTRY_IMAGE}:${version}" ${references}; then
    published=$((published + 1))
  else
    echo "::warning::Failed to publish ${REGISTRY_IMAGE}:${version}; continuing with remaining versions."
    skipped=$((skipped + 1))
    skipped_list="${skipped_list} ${version}"
  fi
done < <(plan_versions <"${work_dir}/inspected")

echo "Published ${published} tag(s), skipped ${skipped}, for ${REGISTRY_IMAGE}."
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### ${REGISTRY_IMAGE}"
    echo "Published ${published} tag(s), skipped ${skipped}."
    [ -n "$skipped_list" ] && echo "Skipped:${skipped_list}"
  } >>"$GITHUB_STEP_SUMMARY"
fi
if [ "$skipped" -gt 0 ]; then
  echo "::warning::${skipped} ${REGISTRY_IMAGE} tag(s) not published:${skipped_list}"
fi
if [ "$published" -eq 0 ]; then
  echo "::error::No ${REGISTRY_IMAGE} tags could be published."
  exit 1
fi
