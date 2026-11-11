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
| `valkey` | `8.0`, `8.1`, `9.0` + newer (auto); the newest minor of each major also as `8`, `9` |
| `dragonfly` | `1.3`–`1.17` + newer (auto) |
| `elasticsearch` | `7.17`, `8.4`, `8.6`, `8.11`–`8.13` + newer (auto) |
| `opensearch` | `2.9`–`2.13`, `2.19`, `3.1`–`3.3` + newer (auto) |
| `rabbitmq` | `3.7`–`3.13`, `4.1` + newer (auto) |
| `magepack` | `2.3`–`2.11` |
| `mailhog`, `dnsmasq`, `startpage` | `latest` (single image, no version matrix) |

Image tags mirror the upstream version string. The **version-matrix** images do not
get a `latest` tag; the single-image utilities (`mailhog`, `dnsmasq`, `startpage`)
are published only as `:latest`. "**+ newer (auto)**" means the workflow
**discovers** new upstream releases and starts building them on its own — see
[Adding or changing a version](#adding-or-changing-a-version).

## How builds work

**CI is the build system — there is no local build or test harness.** Images are
built and published exclusively by `.github/workflows/docker-image-*.yml`. Each
service workflow triggers on:

- **`workflow_dispatch`** — manual run from the Actions tab (most workflows accept a
  `versions` input to build a specific set on demand),
- a daily **`schedule`** cron (`0 6 * * *`) — so images rebuild against fresh
  upstream bases,
- **`push`** to `master` touching that service's directory or its workflow file.

CI runs on **`master` only**: push triggers are limited to `master`, there is no
pull-request trigger, and a manual run started from another branch skips all jobs.
Feature branches and pull requests therefore start no builds.

The simple service workflows have the same two-stage shape:

```
discover ──▶ build (matrix, one job per version, fail-fast: false)
```

1. A **`discover`** job lists the upstream tags and decides which versions to build
   (see below).
2. A **build matrix** builds each version as an independent job. Because
   `fail-fast: false` is set everywhere, **one version failing never stops the
   others** — each version succeeds or fails on its own.

`php-fpm` has five stages, because it is a chain of layered images built with
`docker buildx bake` (see [the PHP-FPM image graph](#php-fpm-the-layered-image-graph)):

```
discover ──▶ plan ──▶ base ──▶ variants ──▶ merge
```

| Job | What it does |
| --- | --- |
| `discover` | Lists the upstream PHP and Node tags and resolves the version set. |
| `plan` | Computes a fingerprint of each image's inputs, compares it with the label on the published image and emits the matrix of what has to be built. A run with nothing changed ends here. |
| `base` | One job per PHP version × architecture: builds the `php-fpm` base image and the `tools` image. |
| `variants` | One job per PHP version × architecture × chunk of up to three Node versions: builds the Node image and every variant on top of the base. |
| `merge` | One leg per image that has something to publish: assembles the multi-arch tags. |

### Robustness: one failure can't block the rest

This pipeline is designed so a single flaky build can never block publishing of
everything else:

- **Independent matrix jobs.** Every version is its own job with `fail-fast: false`,
  so a transient failure (a slow download, a registry hiccup) is isolated to that
  one version.
- **A failing Node version does not stop its neighbours (php-fpm).** A `variants` job
  builds up to three Node versions one after the other; each has its own bake call, and
  the job fails at the end if any of them failed.
- **Resilient multi-arch merge (php-fpm).** php-fpm builds each architecture
  separately and then merges them into a multi-arch tag. The `variants` and `merge` jobs
  run **even if some earlier builds failed** (`if: !cancelled()`), publish every version that
  has *both* architectures built in this run, and **skip** any version missing one (it
  keeps its previous image) instead of failing the whole run. Each skip is listed in
  the job's step summary. A single flaky combination can't block *every* php-fpm tag.
- **Queued runs.** The php-fpm workflow has a `concurrency` group with
  `cancel-in-progress: false`: runs share the per-arch hand-off tags, so overlapping runs
  would mix run ids and skip each other's merges.
- **EOL combinations are non-fatal.** End-of-life or experimental PHP/Node
  combinations are marked `continue-on-error`, so a failure there can't fail the run.
- **Network downloads retry.** In-Dockerfile downloads (the PHP extension installer,
  magerun, wp-cli, `composer require`, `npm install`) use `curl --retry` / retry
  loops instead of a single attempt that fails the build on one bad packet.

### Forcing a rebuild

`.trigger` at the repo root is a no-op file listed in the `paths:` of many
workflows. Changing its contents (e.g. the UUID) and pushing rebuilds every image
that watches it, without having to touch a `Dockerfile`. For php-fpm the file is part of
the input fingerprint, so changing it makes every image count as changed.

For php-fpm you can also run the **Docker Image PHP-FPM** workflow from the Actions tab
with **`force`** on (the default for manual runs): it builds the selected images even
when their inputs did not change. **`no_cache`** additionally builds the base images
without the registry cache.

### Validating a change without merging

- Build a simple service locally with `docker buildx build`, passing the same build args
  the workflow uses.
- Build php-fpm locally with `docker buildx bake` and `php-fpm/docker-bake.hcl`. Print the
  resolved targets first, then build on a throwaway builder with a CPU and memory limit:

  ```bash
  docker buildx bake -f php-fpm/docker-bake.hcl --print
  docker buildx create --name roll-bake-test --driver docker-container \
    --driver-opt cpuset-cpus=0-3 --driver-opt memory=16g
  docker buildx bake --builder roll-bake-test -f php-fpm/docker-bake.hcl --progress=plain
  docker buildx rm -f roll-bake-test
  ```

  The variables are `PHP_VERSION`, `PHP_OS_RELEASE`, `XDEBUG_TYPE` and `NODES` (a JSON list
  of `{"v","os","gyp_python"}`). Measured with PHP 8.3 on an arm64 builder with 4 CPUs and
  16 GB: the base and tools images take about 3 GB and about 5 minutes cold, and every Node
  version adds about 1.9 GB (two Node versions with six images each took 1 to 2 minutes). Build
  at most 4 Node versions per call, or the builder runs out of disk. `REGISTRY` defaults to
  `localhost:5000/roll` and `PUSH` to `false` (results stay in the build cache). Never run
  with `PUSH=true` and `REGISTRY` pointing at `ghcr.io`: that overwrites published images.
- Run a workflow locally with [`act`](https://github.com/nektos/act). The jobs only run
  for the `master` ref, so on a feature branch pass an event file containing
  `"ref": "refs/heads/master"` (`act -e event.json`). Registry logins, pushes and manifest
  merges are skipped under `act`.

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

### …for a simple service (redis, valkey, mysql, mariadb, mongo, nginx, elasticsearch, opensearch, varnish, dragonfly, rabbitmq)

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

php-fpm has a richer matrix (PHP × Node × architecture × image), so its policy
lives in **[`.github/workflows/php-matrix/constants.php`](.github/workflows/php-matrix/constants.php)**
instead of inline YAML:

```php
const PHP_MIN_VERSION  = '7.3';                  // floor
const PHP_DENY_VERSIONS = [];                     // never build
const PHP_PIN_VERSIONS  = ['7.3', ..., '8.5'];    // always build (+ fallback)
// …and the same trio for Node (NODE_MIN_VERSION / NODE_DENY_VERSIONS / NODE_PIN_VERSIONS)
```

The same file also holds:

- **`PHP_VERSIONS_OS_RELEASE` / `NODE_VERSIONS_OS_RELEASE`** — pin the Debian base
  (`bullseye`/`bookworm`) for a version. A version not listed defaults to bookworm.
- **`EOL_PHP_VERSIONS` / `EOL_NODE_VERSIONS` / `EXPERIMENTAL_PHP_VERSIONS`** — mark a
  version so a flaky build of it can't fail the run (it becomes `continue-on-error`).
- **`NOT_STABLE_XDEBUG_PHP_VERSIONS`** — which versions get the non-stable Xdebug.
- **`NODE_CHUNK_SIZE`** — how many Node versions one `variants` job builds (3). EOL and
  supported Node versions are chunked separately, so `continue-on-error` keeps its meaning.
  With the pinned lists that makes 80 `variants` jobs (96 with today's discovered Node
  versions), next to 16 `base` jobs.

[`bake-generator.php`](.github/workflows/php-matrix/bake-generator.php) reads this config and
emits what the `plan` job needs: the upstream image references to look up (`refs`) and the
build matrix (`matrix`). Preview the references locally, no CI needed:

```bash
php .github/workflows/php-matrix/bake-generator.php refs
```

`matrix` additionally needs the fingerprint inputs: `TREE_HASH` and `BUILD_WEEK`, or `FORCE=1`
to build everything.

To build a specific set on demand, run the **Docker Image PHP-FPM** workflow from the
Actions tab and fill in the `php_versions` / `node_versions` inputs (they replace the
resolved set).

#### Versions that appear unused

Every php-fpm image is built for the full PHP × Node cross product, so each version on the lists
below costs builds in every run that rebuilds it. Nothing here has been removed: deciding what to stop
building is a team decision. The figures below are there to make that decision easy.

Sources:

- **Seen in use:** the RollDev project configurations (`.env.roll`) on one developer machine,
  2026-11-10. The build server and other machines were not checked, so a zero here does not mean a
  version is unused everywhere.
- **RollDev default:** the `PHP_VERSION` / `NODE_VERSION` in RollDev's `environments/<type>/init.env`
  (epartment/rolldev, 2026-11-09), which a new project of that type starts with.
- **Upstream support:** [php.net supported versions](https://www.php.net/supported-versions.php) and
  [php.net end-of-life branches](https://www.php.net/eol.php); the Node.js release schedule
  ([nodejs/Release `schedule.json`](https://github.com/nodejs/Release/blob/main/schedule.json)).
  Read 2026-11-11.

| PHP | Upstream support (2026-11-11) | Seen in use | RollDev default for | Candidate to stop building |
|---|---|---|---|---|
| 7.3 | end of life since 2021-12-06 | 0 | – | yes |
| 7.4 | end of life since 2022-11-28 | 0 | akeneo, shopware, symfony, typo3, wordpress | no (RollDev default) |
| 8.0 | end of life since 2023-11-26 | 0 | – | yes |
| 8.1 | end of life since 2025-12-31 | yes | php, vuejs | no (in use) |
| 8.2 | security fixes until 2026-12-31 | yes | – | no (in use) |
| 8.3 | security fixes until 2027-12-31 | yes | laravel, magento2 | no |
| 8.4 | active until 2026-12-31, security until 2028-12-31 | yes | – | no |
| 8.5 | active until 2027-12-31, security until 2029-12-31 | 0 | – | no (current release) |

| Node | Upstream support (2026-11-11) | Seen in use (projects) | RollDev default for | Candidate to stop building |
|---|---|---|---|---|
| 10 | end of life since 2021-04-30 | 0 | – | yes |
| 12 | end of life since 2022-04-30 | 4 | magento1, shopware, symfony, typo3, wordpress | no (RollDev default) |
| 13 | end of life since 2020-06-01 (never LTS) | 0 | – | yes |
| 14 | end of life since 2023-04-30 | 3 | – | no (in use) |
| 15 | end of life since 2021-06-01 (never LTS) | 0 | – | yes |
| 16 | end of life since 2023-09-11 | 19 | akeneo | no (in use) |
| 17 | end of life since 2022-06-01 (never LTS) | 0 | – | yes |
| 18 | end of life since 2025-04-30 | 4 | vuejs | no (in use) |
| 19 | end of life since 2023-06-01 (never LTS) | 5 | php | no (in use) |
| 20 | end of life since 2026-04-30 | 3 | – | no (in use) |
| 21 | end of life since 2024-06-01 (never LTS) | 0 | – | yes |
| 22 | maintenance LTS until 2027-04-30 | 8 | laravel, magento2 | no |
| 23 | end of life since 2025-06-01 (never LTS) | 0 | – | yes |
| 24 | maintenance LTS until 2028-04-30 | 0 | – | no (supported LTS) |
| 25 | end of life since 2026-06-01 (never LTS) | 0 | – | yes |
| 26 | active LTS until 2029-04-30 | 0 | – | no (current LTS) |

Node 10 and 12–22 are pinned in `constants.php`. 23–26 are built because discovery finds them
upstream. Node 26 has no `bullseye` image on Docker Hub's supported-tags list; like every Node
version not listed in `NODE_VERSIONS_OS_RELEASE`, it is taken from `node:26-bookworm`.

To stop building a version, add it to `PHP_DENY_VERSIONS` or `NODE_DENY_VERSIONS` in
[`.github/workflows/php-matrix/constants.php`](.github/workflows/php-matrix/constants.php)
(one entry; it also overrides discovery and the pins). Its existing tags stay in the registry
but are no longer refreshed. A project pinned to that version keeps working on the last image
until the tags are removed by hand.

#### Debian release per PHP version

PHP 8.1 and later build on Debian bookworm. PHP 7.3, 7.4 and 8.0 stay on bullseye, because
upstream publishes no newer base image for them (their bullseye base is frozen, and
`php-fpm/context/apt-snapshot-bullseye-security` keeps its security suite installable). Node 22
and later are copied from the bookworm Node images, older Node versions from the release listed in
`NODE_VERSIONS_OS_RELEASE`; the php+node image only takes the Node binary, npm and Yarn from them,
so the Node image's release does not have to match the PHP base.

What a project sees on bookworm compared with bullseye: OpenSSL 3.0 (PHP's openssl offers no
`rc4` and no `bf-cbc`), ICU 72, MariaDB client 10.11, Python 3.11 and no `python2`. node-gyp 7
and older cannot run on Python 3.11, so the bookworm base image carries a standalone Python 3.10 in
`/opt/python-node-gyp`, and the php+node images with Node below 18 point `NODE_GYP_FORCE_PYTHON` at
it (`node_gyp_python()` in `constants.php`). That keeps node-sass 5 and 6 building from source;
node-sass 4.x needs Python 2 and no longer builds on arm64, so such a project has to move to
node-sass 6 or later.

## PHP-FPM: the layered image graph

`php-fpm` is not a single image — it's a chain of layered images, each built `FROM`
the previous one. Every layer shares the single build context `php-fpm/context/`
but uses a different `Dockerfile`. The whole chain for one PHP version is described in
`php-fpm/docker-bake.hcl` and built with `docker buildx bake`. Each layer gets its parent as a
named build context: another target of the same bake call (`target:…`), or, in the `variants`
jobs, the base and tools images the `base` job pushed (`docker-image://…`). The Dockerfiles say
`FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}`, which the bake file points at that context:

```
php (official)
  └─ php-fpm/Dockerfile            → roll/php-fpm        (extensions, tools, composer, oh-my-zsh)    [base job]
       ├─ php-fpm/tools/Dockerfile → roll/php-fpm:<php>-tools   (FROM scratch: mydumper/myloader, Xdebug module)
       └─ php-fpm/node/Dockerfile  → roll/php-fpm        (+ Node / yarn / phantomjs, re-tagged same repo)   [variants jobs]
            ├─ php-fpm/xdebug3/Dockerfile               → roll/php-fpm-debug
            ├─ php-fpm/magento1/Dockerfile              → roll/php-fpm-magento1
            ├─ php-fpm/magento2/Dockerfile              → roll/php-fpm-magento2   (+ magerun, mydumper from tools, cache-clean)
            │    └─ php-fpm/xdebug3/Dockerfile          → roll/php-fpm-magento2-debug
            └─ php-fpm/wordpress/Dockerfile             → roll/php-fpm-wordpress
```

The **tools image** is built once per PHP version, in the `base` job, and holds what is
expensive to compile and does not depend on Node: mydumper/myloader (built from source) and
the Xdebug module. The Magento 2 layer copies mydumper/myloader from it, and
`php-fpm/xdebug3/Dockerfile` copies the Xdebug module. Before this, both were compiled once per
Node version. That single Xdebug Dockerfile builds both `php-fpm-debug` and
`php-fpm-magento2-debug` (only the parent differs) and fails the build when `php -m` does not
list xdebug.

Version-conditional steps inside these Dockerfiles use shell `sort -g` / `sort -V`
comparisons against `${PHP_VERSION}` (e.g. "install imagick only if PHP > 7.2 &&
< 8.3").

### Multi-arch via per-arch tags + manifest merge

Each image is built **per architecture separately** on native amd64 and arm64
runners (no slow QEMU emulation). The `base` jobs run per PHP version and architecture and push
`php-fpm:<php>-arch-<x86|arm64>` and `php-fpm:<php>-tools-arch-<x86|arm64>`, with a registry
cache under `php-fpm:<php>-<x86|arm64>-buildcache` (and `-tools`). The `variants` jobs
(PHP version × architecture × chunk of up to `NODE_CHUNK_SIZE` Node versions) build the Node
image and all variants on top of that base through
`.github/scripts/bake-php-fpm-variants.sh`, which runs one bake call per Node version, so a
failing Node version does not stop the next one, and prunes the builder when the runner has
less than 6 GB of disk free. Each variant pushes `<version>-arch-<x86|arm64>` (e.g.
`php-fpm-magento2:8.3-node20-arch-arm64`).

Every per-arch tag carries the label `org.epartment.roll.ci-run-id` (the id of the run that
built it). The script refuses to build on a base whose labels do not match the plan: a base
that should have been built in this run but is missing only logs a warning and builds nothing,
and a reused base without the planned fingerprint is an error.

The `merge` job has one leg per image that has something to publish (at most six, from the
plan's `images` list). Each leg runs
`.github/scripts/merge-per-arch-tags.sh` over the versions the plan expects. For each version
the script:

1. reads each architecture's per-arch tag with `docker buildx imagetools inspect`;
2. if both tags carry **this run's** id, runs `docker buildx imagetools create` to
   assemble the multi-arch manifest `:<version>` from their digests;
3. otherwise skips the version, which keeps its previous image (see
   [Robustness](#robustness-one-failure-cant-block-the-rest)).

The run-id check means a failed leg can never pair an old image with a new one. The
`-arch-` and `-tools` tags are internal hand-off tags; use the plain `:<version>` tags. Because
runs share these tags, the workflow queues runs (`concurrency`, no cancelling). A push during
the daily build waits for it to finish rather than overwriting its tags halfway.

The hand-off deliberately does **not** use workflow artifacts. A run lists only about
its first 1000 artifacts, and the previous layout produced well over 1000 jobs. Every digest
past that cutoff was silently invisible to the merge jobs. The current layout is 16 `base`,
80 to 96 `variants` and up to 6 `merge` jobs (plus `discover` and `plan`).

#### Skipping unchanged images

The `plan` job computes a **source fingerprint** (sha256) per image and compares it with the
`org.epartment.roll.source-fingerprint` label of the published image. An image whose label
equals this run's fingerprint is skipped. A run where nothing changed ends after `plan`.

The fingerprint covers:

- the git tree hash of `php-fpm`, the php-fpm workflow, `.github/workflows/php-matrix/`,
  `.github/scripts` and `.trigger`;
- the ISO week (`date -u +%G-W%V`);
- the digests of `composer:1`, `composer:2.2`, `composer:2`, `golang:alpine` and
  `99designs/phantomjs:2.1.1`;
- for the base image, also the digest of the upstream PHP image and the Xdebug type; for a Node
  image, the digest of the `node:<version>-<os>` image.

A registry lookup that fails (after three tries) counts as changed, so an outage causes extra
builds, never a skipped one. Because the ISO week is part of the fingerprint, **the first run of
each week rebuilds everything**; scheduled runs also build the base stage with `--no-cache`. Manual runs skip unchanged images only when
`force` is off. The skip saves CI time, but it has not yet been measured in CI.

When adding a new layered image you must add its build to `docker-bake.hcl` and the variants
script, and a matching `merge` leg (assembling the manifest) — they go together.

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
  docker-bake.hcl   bake definition of the whole layer chain for one PHP version
  tools/            tools image (mydumper/myloader, Xdebug module), built once per PHP version
  node/ xdebug3/ magento1/ magento2/ wordpress/   layer Dockerfiles
nginx/              nginx image + per-framework vhost templates (available.d/)
varnish/ mariadb/ mysql/ mongo/ redis/ valkey/ dragonfly/ ...   one directory per service
.github/workflows/  one docker-image-*.yml per service + mirror-base-images.yml
.github/workflows/php-matrix/   php-fpm version policy (constants.php) + bake-generator.php (plan + matrix)
.github/scripts/    merge-per-arch-tags.sh, collect-registry-state.sh, bake-php-fpm-variants.sh
.trigger            no-op file; bump to force a rebuild
```

## License

See individual upstream projects for their licenses. RollDev itself is released
under the MIT License.
