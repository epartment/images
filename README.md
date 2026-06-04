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

## Images

All images are published to `ghcr.io/<owner>/roll/<name>:<version>`. The canonical
upstream namespace is **`ghcr.io/epartment/roll/`**; forks publish under their own
owner automatically (the namespace is derived from `github.repository_owner`).

| Image | Tags (versions) |
| --- | --- |
| `php-fpm` | see [PHP-FPM image graph](#php-fpm-the-layered-image-graph) |
| `php-fpm-debug`, `php-fpm-magento1`, `php-fpm-magento2`, `php-fpm-magento2-debug`, `php-fpm-wordpress` | per PHP version |
| `nginx` | `1.16`, `1.25`, `1.26`, `1.27` |
| `varnish` | `6.0` (legacy/lts), `6.6`, `7.0`–`7.7` |
| `mariadb` | `10.3`, `10.4`, `10.6`, `11.3`, `11.4` |
| `mysql` | `5.5`, `5.6`, `5.7`, `8.0`–`8.4`, `9.0`–`9.2` |
| `mongo` | `5`, `6`, `7` |
| `redis` | `5.0`, `6.0`, `6.2`, `7.0`, `7.2` |
| `dragonfly` | `1.3`–`1.17` |
| `elasticsearch` | `7.17`, `8.4`, `8.6`, `8.11`–`8.13` |
| `opensearch` | `2.9`–`2.13`, `2.19`, `3.1`–`3.3` |
| `rabbitmq` | `3.7`–`3.13`, `4.1` |
| `magepack` | `2.3`–`2.11` |
| `mailhog`, `dnsmasq`, `startpage` | single image (no version matrix) |

Image tags mirror the upstream version string. No `latest` tag is created.

## How builds work

**CI is the build system — there is no local build or test harness.** Images are
built and published exclusively by `.github/workflows/docker-image-*.yml`. Each
workflow triggers on:

- **`workflow_dispatch`** — manual run from the Actions tab,
- a daily **`schedule`** cron (`0 6 * * *`) — so images rebuild against fresh
  upstream bases,
- **`push`** touching that service's directory or its workflow file.

Images are only **pushed** when running on `master` (`github.ref == 'refs/heads/master'`).
On other branches and pull requests the workflow still builds the image to validate
the change, but skips the registry push.

### Forcing a rebuild

`.trigger` at the repo root is a no-op file listed in the `paths:` of many
workflows. Changing its contents (e.g. the UUID) and pushing rebuilds every image
that watches it, without having to touch a `Dockerfile`.

### Validating a change without merging

- Push to a non-`master` branch and watch the workflow build (it won't push), or
- Run a workflow locally with [`act`](https://github.com/nektos/act); the workflows
  detect `env.ACT` and skip registry logins and pushes.

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

### The version matrix is generated, not hand-written

`docker-image-php-fpm.yml` does not hard-code PHP/Node versions. Three PHP scripts
in `.github/workflows/php-matrix/` emit the GitHub Actions matrix as JSON:

- **`constants.php`** — the single source of truth: `PHP_VERSIONS`, `NODE_VERSIONS`,
  per-version Debian `OS_RELEASE`, EOL/experimental lists, and the `ARCHES` (amd64 /
  arm64 runner definitions). **Edit this to add or drop a PHP or Node version.**
- `php-generator.php` — base PHP × arch (for the base php-fpm build).
- `node-generator.php` — PHP × Node × arch (for the `+node` layer).
- `full-generator.php` — PHP × Node × arch with xdebug/EOL/`latest` flags (for the
  Magento / WordPress / xdebug layers).

### Multi-arch via digest + manifest merge

Each layer is built **per-architecture separately** on native amd64 and arm64
runners, pushed with `push-by-digest=true` (no tag), and the resulting digest is
uploaded as a workflow artifact. Separate `merge-*` jobs then download all digests
for a given image, group them by PHP version, and run `docker buildx imagetools
create` to assemble the multi-arch manifest tagged `:<php_version>`.

When adding a new layered image you must add **both** a build job (emitting digests)
and a matching `merge-*` job (assembling the manifest) — they go together.

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
.github/workflows/  one docker-image-*.yml per service
.github/workflows/php-matrix/   PHP/Node version matrix generators
.trigger            no-op file; bump to force a rebuild
```

## License

See individual upstream projects for their licenses. RollDev itself is released
under the MIT License.
