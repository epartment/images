# Every php-fpm image of ONE PHP version on ONE architecture, built by `docker buildx bake`:
#
#   base ──┬─ tools          (mydumper + Xdebug module, compiled once; FROM scratch)
#          └─ node-<v> ──┬─ xdebug-<v>                    (uses tools)
#                        ├─ magento1-<v>
#                        ├─ magento2-<v> ── magento2-xdebug-<v>   (both use tools)
#                        └─ wordpress-<v>
#
# Children receive their parent through a named build context, so nothing is pushed or pulled
# between layers of one call. The layer Dockerfiles say `FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}`,
# which is pointed at the placeholder `roll-parent:latest` and replaced by the parent context.
# The base stage (group `base-stage`) and the variants of each Node version (group `variants`)
# can be built in separate calls: BASE_CONTEXT / TOOLS_CONTEXT then name the pushed base and tools
# images as `docker-image://<ref>`.
#
# Run from the repository root:
#   docker buildx bake -f php-fpm/docker-bake.hcl --print
#   NODES='[{"v":"20","os":"bullseye","gyp_python":""}]' docker buildx bake -f php-fpm/docker-bake.hcl

variable "PHP_VERSION" {
  default = "8.3"
}
variable "PHP_OS_RELEASE" {
  default = "bookworm"
}
# Upstream php repository (the GHCR base-images mirror in CI, Docker Hub otherwise).
variable "PHP_SOURCE_IMAGE" {
  default = "php"
}
variable "XDEBUG_TYPE" {
  default = "xdebug-stable"
}
# JSON list of {"v": Node major, "os": Debian release of the node image,
# "gyp_python": NODE_GYP_FORCE_PYTHON value or ""}.
variable "NODES" {
  default = "[{\"v\":\"20\",\"os\":\"bullseye\",\"gyp_python\":\"\"},{\"v\":\"22\",\"os\":\"bookworm\",\"gyp_python\":\"\"}]"
}
# Deliberately not the published registry: a local push must not overwrite the per-arch hand-off
# tags. CI passes REGISTRY=ghcr.io/<owner>/roll explicitly.
variable "REGISTRY" {
  default = "localhost:5000/roll"
}
# Per-arch tag suffix (x86 / arm64), as used by .github/scripts/merge-per-arch-tags.sh.
variable "ARCH_SUFFIX" {
  default = "arm64"
}
variable "RUN_ID" {
  default = "local"
}
# false builds into the build cache only; true pushes every tagged target.
variable "PUSH" {
  default = false
}
variable "BASE_FINGERPRINT" {
  default = ""
}
# JSON object: Node major -> source fingerprint of that Node version's images.
variable "NODE_FINGERPRINTS" {
  default = "{}"
}
variable "BASE_CONTEXT" {
  default = "target:base"
}
variable "TOOLS_CONTEXT" {
  default = "target:tools"
}
# Registry cache ref of the base image; empty disables the cache (local builds).
variable "BASE_CACHE_REF" {
  default = ""
}

# The run-id label lets merge-per-arch-tags.sh publish only this run's per-arch tags; the
# fingerprint label lets the next run skip an image whose inputs did not change.
function "roll_labels" {
  params = [fingerprint]
  result = {
    "org.epartment.roll.ci-run-id"         = RUN_ID
    "org.epartment.roll.source-fingerprint" = fingerprint
  }
}

function "node_fingerprint" {
  params = [node_version]
  result = lookup(jsondecode(NODE_FINGERPRINTS), node_version, "")
}

function "registry_cache" {
  params = [ref, mode]
  result = ref == "" ? [] : ["type=registry,ref=${ref}${mode}"]
}

group "default" {
  targets = ["base-stage", "variants"]
}

group "base-stage" {
  targets = ["base", "tools"]
}

group "variants" {
  targets = ["node", "xdebug", "magento1", "magento2", "magento2-xdebug", "wordpress"]
}

target "_common" {
  context = "php-fpm/context"
  output  = [PUSH ? "type=registry" : "type=cacheonly"]
}

# Arguments that make `FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}` resolve to the placeholder.
target "_child" {
  inherits = ["_common"]
  args = {
    ENV_SOURCE_IMAGE = "roll-parent"
    PHP_VERSION      = "latest"
  }
}

target "base" {
  inherits   = ["_common"]
  dockerfile = "../Dockerfile"
  args = {
    ENV_SOURCE_IMAGE = PHP_SOURCE_IMAGE
    PHP_VERSION      = PHP_VERSION
    OS_RELEASE       = PHP_OS_RELEASE
  }
  labels     = roll_labels(BASE_FINGERPRINT)
  tags       = ["${REGISTRY}/php-fpm:${PHP_VERSION}-arch-${ARCH_SUFFIX}"]
  cache-from = registry_cache(BASE_CACHE_REF, "")
  cache-to   = registry_cache(BASE_CACHE_REF, ",mode=max")
}

target "tools" {
  inherits   = ["_common"]
  dockerfile = "../tools/Dockerfile"
  args = {
    XDEBUG_TYPE = XDEBUG_TYPE
  }
  contexts = {
    roll-base = "target:base"
  }
  labels     = roll_labels(BASE_FINGERPRINT)
  tags       = ["${REGISTRY}/php-fpm:${PHP_VERSION}-tools-arch-${ARCH_SUFFIX}"]
  cache-from = registry_cache(BASE_CACHE_REF == "" ? "" : "${BASE_CACHE_REF}-tools", "")
  cache-to   = registry_cache(BASE_CACHE_REF == "" ? "" : "${BASE_CACHE_REF}-tools", ",mode=max")
}

target "node" {
  name       = "node-${node.v}"
  inherits   = ["_child"]
  matrix     = { node = jsondecode(NODES) }
  dockerfile = "../node/Dockerfile"
  args = {
    NODE_VERSION    = node.v
    OS_RELEASE      = node.os
    NODE_GYP_PYTHON = node.gyp_python
  }
  contexts = {
    roll-parent = BASE_CONTEXT
  }
  labels = roll_labels(node_fingerprint(node.v))
  tags   = ["${REGISTRY}/php-fpm:${PHP_VERSION}-node${node.v}-arch-${ARCH_SUFFIX}"]
}

target "xdebug" {
  name       = "xdebug-${node.v}"
  inherits   = ["_child"]
  matrix     = { node = jsondecode(NODES) }
  dockerfile = "../xdebug3/Dockerfile"
  contexts = {
    roll-parent = "target:node-${node.v}"
    roll-tools  = TOOLS_CONTEXT
  }
  labels = roll_labels(node_fingerprint(node.v))
  tags   = ["${REGISTRY}/php-fpm-debug:${PHP_VERSION}-node${node.v}-arch-${ARCH_SUFFIX}"]
}

target "magento1" {
  name       = "magento1-${node.v}"
  inherits   = ["_child"]
  matrix     = { node = jsondecode(NODES) }
  dockerfile = "../magento1/Dockerfile"
  contexts = {
    roll-parent = "target:node-${node.v}"
  }
  labels = roll_labels(node_fingerprint(node.v))
  tags   = ["${REGISTRY}/php-fpm-magento1:${PHP_VERSION}-node${node.v}-arch-${ARCH_SUFFIX}"]
}

target "magento2" {
  name       = "magento2-${node.v}"
  inherits   = ["_child"]
  matrix     = { node = jsondecode(NODES) }
  dockerfile = "../magento2/Dockerfile"
  contexts = {
    roll-parent = "target:node-${node.v}"
    roll-tools  = TOOLS_CONTEXT
  }
  labels = roll_labels(node_fingerprint(node.v))
  tags   = ["${REGISTRY}/php-fpm-magento2:${PHP_VERSION}-node${node.v}-arch-${ARCH_SUFFIX}"]
}

target "magento2-xdebug" {
  name       = "magento2-xdebug-${node.v}"
  inherits   = ["_child"]
  matrix     = { node = jsondecode(NODES) }
  dockerfile = "../xdebug3/Dockerfile"
  contexts = {
    roll-parent = "target:magento2-${node.v}"
    roll-tools  = TOOLS_CONTEXT
  }
  labels = roll_labels(node_fingerprint(node.v))
  tags   = ["${REGISTRY}/php-fpm-magento2-debug:${PHP_VERSION}-node${node.v}-arch-${ARCH_SUFFIX}"]
}

target "wordpress" {
  name       = "wordpress-${node.v}"
  inherits   = ["_child"]
  matrix     = { node = jsondecode(NODES) }
  dockerfile = "../wordpress/Dockerfile"
  contexts = {
    roll-parent = "target:node-${node.v}"
  }
  labels = roll_labels(node_fingerprint(node.v))
  tags   = ["${REGISTRY}/php-fpm-wordpress:${PHP_VERSION}-node${node.v}-arch-${ARCH_SUFFIX}"]
}
