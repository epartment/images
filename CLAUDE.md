# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

This repo is `epartment/images` — the build pipeline for the Docker images used by
**RollDev** (`epartment/rolldev`, CLI command `roll`), "a powerful, flexible Docker
development environment for modern web applications." RollDev spins up local stacks
for Magento 1/2, Laravel, Symfony, TYPO3, Shopware, WordPress, Akeneo, and generic
PHP — which is why this repo's images and the nginx vhost templates
(`nginx/etc/nginx/available.d/`) cover more than just Magento.

There is no application code here — each top-level directory is one service image
(its `Dockerfile` plus supporting config), and each has a matching GitHub Actions
workflow that builds it for `linux/amd64` + `linux/arm64` and publishes it to
`ghcr.io/epartment/roll/<name>`. Only GHCR is pushed (the old Docker Hub login/tags
have been removed).

Services: `php-fpm` (the complex one — see below), `nginx`, `varnish`,
`mariadb`, `mysql`, `mongo`, `redis`, `dragonfly`, `elasticsearch`, `opensearch`,
`rabbitmq`, `mailhog`, `dnsmasq`, `magepack`, `startpage`.

## How builds run (CI is the build system)

There is no local build/test harness — images are built and published exclusively
by `.github/workflows/docker-image-*.yml`. Each workflow triggers on:

- `workflow_dispatch` (manual),
- a daily `schedule` cron (`0 6 * * *`), so images rebuild against upstream bases,
- `push` touching that service's directory or its workflow file.

Images are only pushed when `github.ref == 'refs/heads/master'` (and not under
`act`). On other branches/PRs the workflow runs the build but skips the push.

Every workflow has a two-stage shape: a **`discover`** job decides which versions to
build, then a **build matrix** builds each version as an independent job. `fail-fast:
false` is set everywhere, so one version failing never stops the others.

**Robustness invariants to preserve when editing workflows:**
- Build-matrix jobs are independent (`fail-fast: false`) — never introduce a shared
  failure point across versions.
- php-fpm `merge-*` jobs run with `if: ${{ !cancelled() && ... }}` and publish only
  versions that have **both** arch digests (`EXPECTED_ARCHES=2`), skipping incomplete
  ones rather than failing. This is what stops one flaky combination from blocking
  every tag — don't revert it to a plain `needs:`-gated merge.
- EOL/experimental combinations are `continue-on-error` (see `constants.php`).
- In-Dockerfile downloads use `curl --retry` / retry loops, not bare `ADD <url>` or a
  single `composer require`/`npm install`.

**`.trigger`** at the repo root is a no-op file listed in the `paths:` of many
workflows. Editing it (e.g. changing the UUID) is the way to force a rebuild of
all images that watch it without touching any Dockerfile.

To validate a change without merging, push to a non-master branch and watch the
workflow build (it won't push), or run the relevant workflow with `act` (it
detects `env.ACT` and skips registry logins/pushes).

## The php-fpm image graph (most important architecture)

`php-fpm` is not one image — it's a chain of layered images, each built `FROM`
the previous via the `ENV_SOURCE_IMAGE` + `PHP_VERSION` build-args. All share the
single build context `php-fpm/context/` but use different Dockerfiles:

```
php (official)
  └─ php-fpm/Dockerfile            → roll/php-fpm        (extensions, tools, composer, oh-my-zsh)
       └─ php-fpm/node/Dockerfile  → roll/php-fpm        (+ Node/yarn/phantomjs, re-tagged same repo)
            ├─ php-fpm/xdebug3/Dockerfile           → roll/php-fpm-debug
            ├─ php-fpm/magento1/Dockerfile          → roll/php-fpm-magento1
            ├─ php-fpm/magento2/Dockerfile          → roll/php-fpm-magento2   (+ magerun, mydumper, cache-clean)
            │    └─ php-fpm/magento2/xdebug3/Dockerfile → roll/php-fpm-magento2-debug
            └─ php-fpm/wordpress/Dockerfile         → roll/php-fpm-wordpress
```

Each Dockerfile starts with `ARG ENV_SOURCE_IMAGE` / `ARG PHP_VERSION` then
`FROM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}` — the workflow passes the previous
stage's registry image as `ENV_SOURCE_IMAGE` so layers stack. Version-conditional
logic inside the Dockerfiles is done with shell `sort -g`/`sort -V` comparisons on
`${PHP_VERSION}` (e.g. "install imagick only if PHP > 7.2 && < 8.3").

### Version matrix is discovered + generated, not hard-coded

`docker-image-php-fpm.yml` does not hard-code versions. Its **`discover`** job lists
the upstream `php`/`node` tags with crane (preferring the GHCR `base-images` mirror,
falling back to Docker Hub) and passes them to three PHP generator scripts in
`.github/workflows/php-matrix/` via the `DISCOVERED_PHP_VERSIONS` /
`DISCOVERED_NODE_VERSIONS` env vars. The generators emit the GitHub Actions matrix
JSON (one entry per PHP × Node × arch):

- `constants.php` — the **policy / single source of truth**: floor (`*_MIN_VERSION`),
  always-build + fallback (`*_PIN_VERSIONS`), exclude (`*_DENY_VERSIONS`), per-version
  Debian `OS_RELEASE` maps, EOL/experimental/xdebug lists, and `ARCHES`. The
  `php_versions()` / `node_versions()` helpers resolve discovered ∪ pinned − deny,
  above the floor. **Edit this to add/pin/exclude a PHP or Node version** (see the
  file header and README → "Adding or changing a version").
- `php-generator.php` — base PHP × arch (for the base php-fpm build).
- `node-generator.php` — PHP × Node × arch (for the +node layer).
- `full-generator.php` — PHP × Node × arch with xdebug/EOL/`latest` flags (for the
  Magento/WordPress/xdebug layers).

Run a generator locally to preview the matrix (it falls back to the pinned lists when
the `DISCOVERED_*` env vars are unset):
`php .github/workflows/php-matrix/full-generator.php | sed 's/^matrix=//' | jq`.

The simple per-service workflows use the same floor/pin/deny policy, but inline as
shell variables (`MIN` / `PIN` / `DENY`) in their own `discover` job, plus a
`versions` `workflow_dispatch` input for ad-hoc builds.

### Multi-arch via digest + manifest merge

Each layer is built **per-architecture separately** (on native amd64 and arm64
runners — no QEMU), pushed `push-by-digest=true` (no tag), and the digest uploaded as
an artifact. Separate `merge*` jobs then download all digests for a given image,
group them by PHP version, and run `docker buildx imagetools create` to assemble
the multi-arch manifest tagged `:<php_version>`. A version is published **only when
both arch digests are present** (incomplete ones are skipped, keeping their previous
image); the merge runs even if some builds failed. No `latest` tag is created. When
adding a new layered image, you must add both a build job (emitting digests) and a
matching `merge-*` job (assembling the manifest) — they go together.

There is also an optional `mirror-base-images.yml` workflow that mirrors the upstream
bases (`php`/`node`/`composer`/`phantomjs`) into `ghcr.io/<owner>/base-images/*` to
insulate builds from Docker Hub rate limits; php-fpm's `discover` uses it when present
and falls back to Docker Hub otherwise.

## Runtime behaviour of php-fpm images

`php-fpm/context/docker-entrypoint` runs on container start (not build) and is
Roll-specific: it recreates the `www-data` user with the host's `USER_ID`/`GROUP_ID`
(macOS file-permission parity), renders `05-additions.ini` from a template via
`envsubst` (Mailhog host/port), trusts a mounted Roll root CA, proxies the host SSH
agent socket with `socat`, starts `cron`, selects the Composer version from
`COMPOSER_VERSION` (`1`/`2`/`2.2` — three Composer binaries are baked in as
`composer1`/`composer2`/`composer2lts`), `chown`s dirs listed in `CHOWN_DIR_LIST`,
and installs extra extensions named in `ADD_PHP_EXT`. Keep these env-var contracts
intact when editing the entrypoint.

## Conventions

- Image tags are the upstream version string (`redis:7.2`, `php-fpm-magento2:8.3`),
  driven by each workflow's `matrix.version`. Every service now derives its matrix
  from a `discover` job (floor/pin/deny policy); php-fpm additionally runs the
  `php-matrix` generators. No service hard-codes its full version list anymore
  (legacy fixed jobs like `mysql-legacy` / `varnish-lts` are the exception).
- Registry owner comes from `github.repository_owner` (so forks publish under their
  own namespace) — env blocks that say `ghcr.io/epartment/...` are the canonical
  upstream targets.
- `.gitignore` only ignores `.idea`; there is no dependency tooling, linter, or test
  suite to run.
