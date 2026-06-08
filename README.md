# RollDev Images

Docker images for **[RollDev](https://github.com/epartment/rolldev)** — *a powerful,
flexible Docker development environment for modern web applications.*

RollDev (CLI command `roll`) spins up local development stacks for Magento 1 & 2,
Laravel, Symfony, TYPO3, Shopware, WordPress, Akeneo, and generic PHP projects.
This repository (`epartment/images`) is the build pipeline that produces every
service image RollDev pulls at runtime.

There is no application code here — each top-level directory is one service image
(its `Dockerfile` plus supporting config), and each has a matching GitHub Actions
workflow that builds it for `linux/amd64` + `linux/arm64` and publishes it to the
GitHub Container Registry.

> **New here?** Jump to [How builds work](#how-builds-work) for the big picture, or
> [Adding or changing a version](#adding-or-changing-a-version) if you just need to
> get a new version building.

## Images

All images are published to `ghcr.io/<owner>/roll/<name>:<version>`. The canonical
upstream namespace is **`ghcr.io/epartment/roll/`**; forks publish under their own
owner automatically (the namespace is derived from `github.repository_owner`).

| Image | Versions |
| --- | --- |
| `php-fpm` and its variants (`php-fpm-debug`, `php-fpm-magento1`, `php-fpm-magento2`, `php-fpm-magento2-debug`, `php-fpm-wordpress`) | per PHP × Node combination — see [the PHP-FPM image graph](#php-fpm-the-layered-image-graph) |
| `nginx` | `1.16`, `1.25`, `1.26`, `1.27` + newer (auto) |
| `varnish` | `6.0` (LTS), `6.6`, `7.0`–`7.7` + newer (auto) |
| `mariadb` | `10.3`, `10.4`, `10.6`, `11.3`, `11.4` + newer (auto) |
| `mysql` | `5.5`–`5.7` (legacy), `8.0`–`8.4`, `9.0`–`9.2` + newer (auto) |
| `mongo` | `5`, `6`, `7` + newer (auto) |
| `redis` | `5.0`, `6.0`, `6.2`, `7.0`, `7.2` + newer (auto) |
| `dragonfly` | `1.3`–`1.17` + newer (auto) |
| `elasticsearch` | `7.17`, `8.4`, `8.6`, `8.11`–`8.13` + newer (auto) |
| `opensearch` | `2.9`–`2.13`, `2.19`, `3.1`–`3.3` + newer (auto) |
| `rabbitmq` | `3.7`–`3.13`, `4.1` + newer (auto) |
| `magepack` | `2.3`–`2.11` |
| `mailhog`, `dnsmasq`, `startpage` | single image (no version matrix) |

Image tags mirror the upstream version string. No `latest` tag is created for the
service images. "**+ newer (auto)**" means the workflow **discovers** new upstream
releases and starts building them on its own — see
[Adding or changing a version](#adding-or-changing-a-version).

## How builds work

**CI is the build system — there is no local build or test harness.** Images are
built and published exclusively by `.github/workflows/docker-image-*.yml`. Each
service workflow triggers on:

- **`workflow_dispatch`** — manual run from the Actions tab (most workflows accept a
  `versions` input to build a specific set on demand),
- a daily **`schedule`** cron (`0 6 * * *`) — so images rebuild against fresh
  upstream bases,
- **`push`** touching that service's directory or its workflow file.

Images are only **pushed** when running on `master` (`github.ref == 'refs/heads/master'`).
On other branches and pull requests the workflow still runs to validate the change
but skips the registry push.

Every workflow has the same two-stage shape:

```
discover ──▶ build (matrix, one job per version, fail-fast: false)
```

1. A **`discover`** job lists the upstream tags and decides which versions to build
   (see below).
2. A **build matrix** builds each version as an independent job. Because
   `fail-fast: false` is set everywhere, **one version failing never stops the
   others** — each version succeeds or fails on its own.

### Robustness: one failure can't block the rest

This pipeline is designed so a single flaky build can never block publishing of
everything else:

- **Independent matrix jobs.** Every version is its own job with `fail-fast: false`,
  so a transient failure (a slow download, a registry hiccup) is isolated to that
  one version.
- **Resilient multi-arch merge (php-fpm).** php-fpm builds each architecture
  separately and then merges them into a multi-arch tag. The merge jobs run
  **even if some builds failed** (`if: !cancelled()`), publish every version that
  has *both* architectures, and **skip** any version missing an arch (it keeps its
  previous image) instead of failing the whole run. Previously a single flaky
  combination skipped the merge and blocked *every* php-fpm tag — that is fixed.
- **EOL combinations are non-fatal.** End-of-life or experimental PHP/Node
  combinations are marked `continue-on-error`, so a failure there can't fail the run.
- **Network downloads retry.** In-Dockerfile downloads (the PHP extension installer,
  magerun, wp-cli, `composer require`, `npm install`) use `curl --retry` / retry
  loops instead of a single attempt that fails the build on one bad packet.

### Forcing a rebuild

`.trigger` at the repo root is a no-op file listed in the `paths:` of many
workflows. Changing its contents (e.g. the UUID) and pushing rebuilds every image
that watches it, without having to touch a `Dockerfile`.

### Validating a change without merging

- Push to a non-`master` branch and watch the workflow build (it won't push), or
- Run a workflow locally with [`act`](https://github.com/nektos/act); the workflows
  detect `env.ACT` and skip registry logins and pushes.

## Adding or changing a version

Versions are **discovered automatically** from the upstream registry, on top of a
small, explicit policy you control. The policy has the same shape everywhere:

| Knob | Meaning |
| --- | --- |
| **floor** (`MIN`) | Ignore anything older than this. |
| **pin** (`PIN`) | Always build these. Also the fallback list if discovery is unavailable. |
| **deny** (`DENY`) | Never build these, even if upstream still publishes them. |

The selection rule is: **build the pinned list, plus any upstream version newer than
the highest pinned one**, minus the deny-list, above the floor. So:

- A brand-new upstream release (newer than your newest pin) starts building **on its
  own** — you usually do nothing.
- Intentionally-skipped *older* minors are **not** back-filled (only versions newer
  than your newest pin are auto-added), so curated lists stay curated.
- To guarantee a specific version builds, **add it to the pin**. To stop building
  one, **add it to the deny-list** (or raise the floor).

### …for a simple service (redis, mysql, mariadb, mongo, nginx, elasticsearch, opensearch, varnish, dragonfly, rabbitmq)

Edit the `discover` job at the top of that service's
`.github/workflows/docker-image-<service>.yml`. The knobs are plain shell variables:

```yaml
MIN="5.0"                       # floor
PIN="5.0 6.0 6.2 7.0 7.2"       # always build (+ fallback)
DENY=""                         # never build
```

You can also build an ad-hoc set without editing anything: run the workflow from the
Actions tab and fill in the **`versions`** input (e.g. `7.2, 7.4`).

### …for php-fpm (PHP and Node)

php-fpm has a richer matrix (PHP × Node × architecture × variant), so its policy
lives in **[`.github/workflows/php-matrix/constants.php`](.github/workflows/php-matrix/constants.php)**
instead of inline YAML:

```php
const PHP_MIN_VERSION  = '7.3';                  // floor
const PHP_DENY_VERSIONS = [];                     // never build
const PHP_PIN_VERSIONS  = ['7.3', ..., '8.4'];    // always build (+ fallback)
// …and the same trio for Node (NODE_MIN_VERSION / NODE_DENY_VERSIONS / NODE_PIN_VERSIONS)
```

The same file also holds:

- **`PHP_VERSIONS_OS_RELEASE` / `NODE_VERSIONS_OS_RELEASE`** — pin the Debian base
  (`bullseye`/`bookworm`) for a version (a version not listed defaults to bullseye).
- **`EOL_PHP_VERSIONS` / `EOL_NODE_VERSIONS` / `EXPERIMENTAL_PHP_VERSIONS`** — mark a
  version so a flaky build of it can't fail the run (it becomes `continue-on-error`).
- **`NOT_STABLE_XDEBUG_PHP_VERSIONS`** — which versions get the non-stable Xdebug.

The three generator scripts (`php-generator.php`, `node-generator.php`,
`full-generator.php`) read this config and emit the build matrix as JSON. You can run
them locally to preview the matrix — no CI needed:

```bash
php .github/workflows/php-matrix/full-generator.php | sed 's/^matrix=//' | jq
```

To build a specific set on demand, run the **Docker Image PHP-FPM** workflow from the
Actions tab and fill in the `php_versions` / `node_versions` inputs.

## PHP-FPM: the layered image graph

`php-fpm` is not a single image — it's a chain of layered images, each built `FROM`
the previous one. Every layer shares the single build context `php-fpm/context/`
but uses a different `Dockerfile`, and the build passes the previous stage's
published image in via the `ENV_SOURCE_IMAGE` + `PHP_VERSION` build-args so the
layers stack:

```
php (official)
  └─ php-fpm/Dockerfile            → roll/php-fpm        (extensions, tools, composer, oh-my-zsh)
       └─ php-fpm/node/Dockerfile  → roll/php-fpm        (+ Node / yarn / phantomjs, re-tagged same repo)
            ├─ php-fpm/xdebug3/Dockerfile               → roll/php-fpm-debug
            ├─ php-fpm/magento1/Dockerfile              → roll/php-fpm-magento1
            ├─ php-fpm/magento2/Dockerfile              → roll/php-fpm-magento2   (+ magerun, mydumper, cache-clean)
            │    └─ php-fpm/magento2/xdebug3/Dockerfile → roll/php-fpm-magento2-debug
            └─ php-fpm/wordpress/Dockerfile             → roll/php-fpm-wordpress
```

Version-conditional steps inside these Dockerfiles use shell `sort -g` / `sort -V`
comparisons against `${PHP_VERSION}` (e.g. "install imagick only if PHP > 7.2 &&
< 8.3").

### Multi-arch via digest + manifest merge

Each layer is built **per-architecture separately** on native amd64 and arm64
runners (no slow QEMU emulation), pushed with `push-by-digest=true` (no tag), and the
resulting digest is uploaded as a workflow artifact. Separate `merge-*` jobs then
download all digests for a given image, group them by version, and run
`docker buildx imagetools create` to assemble the multi-arch manifest tagged
`:<php_version>`. As described in [Robustness](#robustness-one-failure-cant-block-the-rest),
a version is only published once **both** architectures are present.

When adding a new layered image you must add **both** a build job (emitting digests)
and a matching `merge-*` job (assembling the manifest) — they go together.

### Base-image mirror (optional)

[`mirror-base-images.yml`](.github/workflows/mirror-base-images.yml) copies the
upstream bases php-fpm builds `FROM` (`php`, `node`, `composer`, `phantomjs`) into
`ghcr.io/<owner>/base-images/*`. This insulates the heavy php-fpm matrix from Docker
Hub pull-rate limits — a common cause of transient failures.

It is **optional**: php-fpm's `discover` job uses the mirror when it exists and
transparently falls back to Docker Hub when it doesn't. Run it once manually to
populate the mirror; a weekly cron keeps it fresh. (Currently only the php base layer
consumes the mirror; the node/composer/phantomjs layers still pull from Docker Hub —
wiring those through the mirror is a possible follow-up.)

## Runtime behaviour of php-fpm images

`php-fpm/context/docker-entrypoint` runs on container start (not at build time) and
is RollDev-specific. It:

- recreates the `www-data` user with the host's `USER_ID` / `GROUP_ID` (for macOS
  file-permission parity),
- renders `05-additions.ini` from a template via `envsubst` (Mailhog host/port),
- trusts a mounted RollDev root CA certificate,
- proxies the host SSH agent socket into the container with `socat`,
- starts `cron`,
- selects the active Composer version from `COMPOSER_VERSION` (`1` / `2` / `2.2`;
  three binaries are baked in as `composer1` / `composer2` / `composer2lts`),
- `chown`s the directories listed in `CHOWN_DIR_LIST`, and
- installs any extra PHP extensions named in `ADD_PHP_EXT`.

Keep these environment-variable contracts intact when editing the entrypoint —
RollDev relies on them.

## Repository layout

```
php-fpm/            base + layered PHP-FPM images and the shared build context/
  context/          shared build context (entrypoint, php.d, profile.d)
  node/ xdebug3/ magento1/ magento2/ wordpress/   layer Dockerfiles
nginx/              nginx image + per-framework vhost templates (available.d/)
varnish/ mariadb/ mysql/ mongo/ redis/ dragonfly/ ...   one directory per service
.github/workflows/  one docker-image-*.yml per service + mirror-base-images.yml
.github/workflows/php-matrix/   php-fpm version policy (constants.php) + matrix generators
.trigger            no-op file; bump to force a rebuild
```

## License

See individual upstream projects for their licenses. RollDev itself is released
under the MIT License.
