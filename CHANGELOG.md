# Changelog

All notable changes to the RollDev Docker images in this repository.

This project has no release versions. Images are rebuilt daily and published under their upstream
version tag (`ghcr.io/epartment/roll/<image>:<version>`), so changes are grouped by the date they
reached `master`, newest first. Categories follow [Keep a Changelog](https://keepachangelog.com/):
**Added**, **Changed**, **Fixed**, **Removed**. Several trial-and-error commits on the same day are
summarised as the net result.

## 2026-09-24

### Fixed

- **php-fpm (all images):** builds failed at `install-php-extensions amqp` on every PHP version.
  Debian 11 (bullseye) LTS ended on 2026-08-31, and its security pool was purged while the index
  still lists the packages, so downloads return 404. The `bullseye-security` source now points at
  the last intact snapshot on snapshot.debian.org (`20260901T000000Z`), with apt retries. This is
  applied in the base, node and magento2 layers
  (`php-fpm/context/apt-snapshot-bullseye-security`).
- **php-fpm + Node 26:** `node:26` images no longer bundle Yarn. The node layer now installs
  Yarn 1.22.22 into `/opt/yarn` when the base image has none.
- **dnsmasq:** the build failed for the same bullseye reason. Moved to Debian trixie.
- **varnish 9.x:** 9.x publishes no `-alpine` tags. `envsubst` is now installed with `apk` or
  `apt-get`, whichever the base image has.
- **elasticsearch, opensearch:** plugin installs are retried up to 5 times, so a truncated download
  or a dropped connection no longer fails the build.
- **magepack:** the arm64 build crashed with `Illegal instruction` under QEMU. Each architecture
  now builds on a native runner, followed by a digest merge that publishes only versions with both
  architectures.

### Changed

- **CI (all workflows):** runs on `master` only. Push triggers are limited to `master`, the php-fpm
  `pull_request` trigger is removed, and entry jobs are gated on the `master` ref, so manual runs
  from other branches do nothing. Merge-job steps that need digest artifacts are skipped under
  `act`.

## 2026-09-17

### Fixed

- **php-fpm-magento2:** n98-magerun2 failed to start on PHP 7.4 and 8.0, because the unversioned
  phar requires PHP ≥ 8.1. The image now installs a phar version that matches each PHP version.

## 2026-08-27

### Fixed

- **php-fpm:** layer jobs no longer skip every PHP version when one base version fails. PHP 8.5 is
  excluded until the move to bookworm, because upstream does not publish a bullseye base for it.
  All three matrix generators now emit `continue_on_error`.

### Added

- `workflow_dispatch` version inputs can narrow a php-fpm build to specific PHP/Node versions.

## 2026-08-26

### Fixed

- **php-fpm:** `ZSH_CUSTOM` was unset under `set -u`, which had failed every base build since
  2026-05-22. The oh-my-zsh plugins are now installed into the correct directory.

### Added

- `CI_BUILD_REVIEW.md`, `FEATURE-REQUESTS.md` and `MYDUMPER_CI_FINDINGS.md`: pipeline review and
  known issues.

## 2026-06-26

### Added

- PHP 8.5 in the php-fpm fallback (pinned) version list.

## 2026-06-08

### Changed

- **CI (all images):** reworked for resilience and dynamic version discovery.
  - Each workflow runs a `discover` job that lists upstream tags and applies a floor/pin/deny policy,
    so new upstream releases are built automatically.
  - php-fpm versions come from `.github/workflows/php-matrix/constants.php`.
  - php-fpm merge jobs now publish every version that has both architecture digests, instead of
    publishing nothing when any single build fails.
  - In-Dockerfile downloads retry.

### Added

- Optional `mirror-base-images.yml` workflow that mirrors upstream base images to GHCR, avoiding
  Docker Hub rate limits.

## 2026-06-02

### Added

- **php-fpm-magento2:** `mydumper`/`myloader`, built from source for both amd64 and arm64.
- `README.md` and `CLAUDE.md`.

## 2025-11-17

### Added

- **mariadb:** `mysql` command symlink to `mariadb`.

## 2025-11-04

### Added

- **opensearch:** 3.1, 3.2 and 3.3.

## 2025-10-01

### Added

- **elasticsearch:** 8.4.

## 2025-09-05

### Added

- **mariadb:** `mydumper`/`myloader` from the upstream mydumper repository.

## 2025-08-12

### Changed

- **php-fpm (all images):** now multi-arch. Each architecture builds on a native runner
  (amd64 and arm64) and pushes by digest, and merge jobs assemble the per-version multi-arch
  manifests. This applies to every layer: node, xdebug, magento1, magento2 and wordpress.

## 2025-07-30

### Added

- **php-fpm:** `opcache` extension (PHP ≥ 7.4).

### Removed

- **php-fpm:** PHP 7.2 builds, which kept failing. `imagick` is no longer installed on PHP 7.2.

## 2025-07-11

### Added

- **php-fpm:** `ftp` extension.
- **mariadb:** `mysqld` symlink to `mariadbd`.

## 2025-07-04

### Added

- **nginx:** 1.16 restored.

## 2025-07-03

### Changed

- **All images:** now published under the `/roll/` namespace
  (`ghcr.io/epartment/roll/<image>`).

### Added

- **elasticsearch:** 8.6.

## 2025-06-20

### Added

- **mariadb:** 11.4 as a stable release (was `-rc`).
- **rabbitmq:** 4.1. Together with the mariadb change, this covers Magento 2.4.8 requirements.

### Changed

- **opensearch:** now published only to GHCR (the `wardenenv/opensearch` tag was dropped); pushes
  fixed to trigger on `master`.

## 2025-05-06

### Removed

- **nginx:** HTTP/3 (QUIC) directives reverted from the default vhost.

## 2025-04-18

### Added

- **opensearch:** 2.19 (Magento 2.4.8 compatibility).
- **varnish:** 7.6 and 7.7.
- **mysql:** 8.0.28 and 8.1.

### Changed

- **dragonfly, elasticsearch, mysql, opensearch, varnish:** workflows updated; push-trigger paths
  fixed.

### Fixed

- **mailhog:** build fixed by building on the unpinned `golang:alpine`.

## 2025-04-03

### Added

- **nginx:** HTTP/3 support (later reverted on 2025-05-06).

### Removed

- **nginx:** versions 1.16–1.24, which cannot do HTTP/3.

## 2025-02-26

### Added

- PHP 8.4 as the newest PHP version.

### Fixed

- **php-fpm (node layer):** Node failed to run on arm64. Added the missing runtime libraries and a
  node binary check.

## 2025-02-04

### Changed

- **php-fpm-debug, php-fpm-magento2-debug:** `xdebug.max_nesting_level` raised from 250 to 500.

## 2025-02-03

### Fixed

- **php-fpm:** `gd` is now built with `docker-php-ext-install` (freetype, jpeg, avif) on PHP ≥ 7.4,
  because `install-php-extensions gd` failed.

### Added

- `continue_on_error` flags in the php-fpm matrix, so EOL/experimental combinations no longer fail
  the run.

## 2025-01-31

### Changed

- **php-fpm:** each PHP extension is installed in its own `install-php-extensions` step, so a
  failure points at the exact extension.
- **php-fpm:** `mhsendmail` replaced by the mailpit `sendmail` fork, which supports arm64.
- **CI:** `docker/build-push-action` bumped to v6.

## 2025-01-29

### Changed

- **php-fpm:** base image arguments now default to `php` / `bullseye`. PHP extension installation
  reworked after build failures.

## 2024-08-28

### Added

- **mariadb:** 10.6.

### Removed

- **varnish:** legacy (pre-6.0) build.

## 2024-08-27

### Added

- **mariadb:** 10.3 and 10.4.
- **redis:** 5.0.
- Node 18 and 22 in the php-fpm matrix.

### Removed

- PHP 7.0 and 7.1 from the php-fpm matrix.

## 2024-08-26

### Changed

- References to `dockergiant` replaced with `epartment`.

### Removed

- Pushes to Docker Hub. Images are published to GHCR only.

## 2024-07-26

### Added

- Every workflow can be started manually (`workflow_dispatch`).

### Fixed

- **nginx:** accepts all encodings, so the response `sub_filter` works.

## 2024-05-06

### Added

- **php-fpm:** Python 2 for PHP ≥ 7.1.
- **php-fpm:** n98-magerun2 versions that work on PHP ≤ 7.3.

## 2024-04-24

### Added

- PHP 8.3 (without `imagick`, which was not yet compatible).
- **varnish:** 7.4 and 7.5.
- **rabbitmq:** 3.13.
- **nginx:** 1.26.

### Removed

- Old, unsupported versions of dragonfly, elasticsearch (5, 6, 7.x, 8.0–8.2), mariadb (10.0–10.11),
  nginx, opensearch (1.x, 2.0–2.4), rabbitmq, redis and varnish.

## 2024-04-10

### Fixed

- **nginx:** the admin autologin no longer depends on a 2FA module being installed.

## 2024-04-09

### Added

- **nginx:** Magento 2 admin autologin script for local environments, including handling of the 2FA
  redirect.
- **php-fpm:** `python` alias for `python3`.

## 2024-03-27

### Added

- **mongo:** new MongoDB image.

## 2024-03-25

### Added

- **php-fpm:** `qrencode` and Python `segno` for QR code generation in the terminal.

### Fixed

- **php-fpm:** PHP 7.0 builds use the archived Debian stretch repositories. `pip` works on
  PHP 7.1/7.2.

## 2024-03-15

### Changed

- **nginx, php-fpm:** upload limit raised to 4 GB.

## 2024-03-08

### Added

- Node 20 and 21 in the php-fpm matrix. PHP 8.3 was added and then removed again the next week
  (not yet compatible).

## 2023-09-12

### Added

- **php-fpm:** `ADD_PHP_EXT` environment variable to install extra PHP extensions at container
  start.

### Changed

- **CI:** `docker/login-action` v3, `docker/build-push-action` v5, `docker/setup-buildx-action` v3.

## 2023-09-04

### Changed

- **CI:** `actions/checkout` v4.

## 2023-08-01

### Fixed

- **php-fpm:** permission errors fixed by running the FPM master process as root.

## 2023-06-15

### Added

- **varnish:** 7.3.
- **elasticsearch:** 8.7 and 8.8.
- **mariadb:** 11.1-rc (latest moved to 11.0).
- **nginx:** 1.24 and 1.25.
- **opensearch:** 2.7 and 2.8.
- **redis:** 7.2-rc.
- **startpage:** update check.

### Changed

- **rabbitmq:** 3.12 is now a stable release (`-rc` dropped).

## 2023-05-24

### Added

- **dragonfly:** new DragonflyDB image (drop-in Redis replacement).

### Fixed

- **php-fpm:** the correct user ID is written to the Docker log.

## 2023-03-22

### Added

- **magepack:** new Magepack image with `generate` and `bundle` commands.

### Changed

- **php-fpm:** upload limit raised to 1024 MB.

## 2023-03-21

### Added

- Initial import of the images from the main RollDev repository: dnsmasq, elasticsearch, mailhog,
  mariadb, mysql, nginx, opensearch, php-fpm, rabbitmq, redis, startpage and varnish, each with its
  own build workflow.
- **opensearch, rabbitmq:** new versions.
