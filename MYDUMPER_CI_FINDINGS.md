# Handoff — mydumper/myloader image publish & CI findings

_Date: 2026-06-08_

## TL;DR

The mydumper/myloader change is **correct and builds cleanly**. The reason
`roll mydumper` fails with `executable file not found in $PATH` is that the
**new `php-fpm-magento2` images were never published** — the `docker-image-php-fpm`
GitHub Actions workflow is **failing on a single flaky matrix job**, which skips the
publish/merge step, so every `php-fpm-magento2` tag still points at the old
pre-mydumper image.

**This is a CI/publish problem, not a Dockerfile problem.**

## Symptom

Running `roll mydumper` in a Magento 2 environment:

```
OCI runtime exec failed: exec failed: unable to start container process:
exec: "mydumper": executable file not found in $PATH
```

## What was verified

1. **The running container uses a stale image.**
   - Container `…-php-fpm-1` runs `ghcr.io/epartment/roll/php-fpm-magento2:8.3-node16`.
   - That tag (locally **and** on GHCR) is the **2026-05-30** build, digest
     `sha256:772ae79edf0edaebe6602671527900e21e98a3082b270f4e5beb67a2c89998b5`.
   - A fresh `docker pull` reports "Image is up to date" — i.e. GHCR itself has not
     been updated. The image contains **no** `mydumper`/`myloader`.

2. **The Dockerfile on `master` is correct.**
   - `php-fpm/magento2/Dockerfile` contains the multi-stage mydumper build
     (commit `a16961e`, merged via `ea23836`).

3. **mydumper compiles on every base tested** (real epartment base image, `linux/arm64`):
   - `php-fpm:8.2-node18` → builds `mydumper`/`myloader` OK.
   - `php-fpm:7.3-node21` (the combo that fails in CI) → also builds OK.
   - Conclusion: the CI failure is **not** the mydumper build.

4. **CI is failing, and was already failing before mydumper.**
   - Workflow: `docker-image-php-fpm.yml`.
   - Latest `master` run (`ea23836`): **failure**, with exactly **one** failed job:
     **`Magento 2 PHP-FPM 7.3 - Node 21`** (out of 864 jobs).
   - Previous pre-mydumper run (`ad4e7ea`): **failure**, but on a *different* set of
     combos (Magento1 8.0/8.1, Magento2 7.4/8.0, several XDebug, WordPress 8.0).
   - Different combos fail on different runs ⇒ **flaky transient failures**
     (downloads / registry / runner), not a deterministic build error.

## Root cause

`merge-magento2` declares `needs: [magento2]`. The `magento2` job is a matrix; if
**any** matrix entry fails, the aggregate `magento2` job is marked failed, so
`merge-magento2` is **skipped**. The merge job is what assembles the per-arch
digests into the final multi-arch tag and pushes it. Skipping it means **no
`php-fpm-magento2` tag is updated at all** — even the (many) combos that built and
pushed their per-arch digests successfully.

So a single flaky combo (here `7.3-node21`) blocks publishing of *every* Magento 2
image.

## Fixes

### Immediate — re-run the failed job
GitHub → images repo → Actions → failed `Docker Image PHP-FPM` run on `master` →
**Re-run failed jobs**. If the flaky `7.3-node21` job passes on retry, the matrix
goes green, `merge-magento2` runs, and all Magento 2 tags publish with mydumper.

Then on the affected environment:
```bash
roll env pull
roll env up --force-recreate
roll mydumper   # should now work
```

### Local stopgap (no CI, no registry push)
Build the Magento 2 image locally for the host arch and tag it as the tag the
environment already uses, then force-recreate so the container picks up the local
image:
```bash
cd <images-repo>
docker buildx build --platform linux/arm64 --load \
  --build-arg ENV_SOURCE_IMAGE=ghcr.io/epartment/roll/php-fpm \
  --build-arg PHP_VERSION=8.3-node16 \
  -f php-fpm/magento2/Dockerfile \
  -t ghcr.io/epartment/roll/php-fpm-magento2:8.3-node16 \
  php-fpm/context
# then, in the project dir:
roll env up --force-recreate
```
Note: this overwrites the local tag with a single-arch (host) image; it is replaced
the next time you `docker pull` the real multi-arch tag.

### Durable — stop one flaky combo from blocking all publishing
Pick one or more:
- **Retry the flaky steps.** The transient failures are in network-bound steps
  (e.g. `ADD https://files.magerun.net/…`, `composer require mage2tv/magento-cache-clean`,
  GHCR push). Wrap them in a retry loop / use a retry action.
- **Make the merge resilient.** Let `merge-magento2` (and the other `merge-*` jobs)
  run on partial success and publish the digests that did succeed, instead of being
  skipped when any single matrix entry fails. Trade-off: a tag could be published
  missing one arch — guard accordingly (e.g. only proceed if both arch digests for a
  given php/node combo are present).
- **Reduce the matrix** if some legacy combos (e.g. `7.3-node21`) aren't actually
  needed.

## Key references

- Failing workflow: `.github/workflows/docker-image-php-fpm.yml`
- Changed file: `php-fpm/magento2/Dockerfile` (multi-stage mydumper build, `MYDUMPER_VERSION=1.0.1-3`)
- `-debug` variant inherits mydumper automatically — it builds
  `FROM …/php-fpm-magento2` via `php-fpm/magento2/xdebug3/Dockerfile`.
- mydumper has **no** Debian `bullseye arm64` prebuilt `.deb`; that is why the image
  builds it from source (works on both `amd64` and `arm64`).
- Stale tag digest (pre-mydumper): `sha256:772ae79edf0edaebe6602671527900e21e98a3082b270f4e5beb67a2c89998b5`
