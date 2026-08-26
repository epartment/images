# Image feature requests

Gaps found while running these images from an automated build pipeline that provisions many Magento 2
environments in parallel, unattended, on a single Linux host. Each item is something that produced a
failed build, a warning on every single run, or a failure that the build pipeline could not diagnose.

Each item cites the file it concerns. Where a claim could not be verified it is labelled *unverified*
in the sentence that makes it.

## Severity

- **High** — causes failures, or allows a broken image to be published.
- **Medium** — works, but warns on every run or blocks a supported workflow.
- **Low** — consistency and hygiene.

## Requests

### High

**H1. No image declares a `HEALTHCHECK`** — all `Dockerfile`s

- *What:* No `HEALTHCHECK` instruction exists anywhere in this repository (verified —
  `grep -rln "HEALTHCHECK" --include="Dockerfile*"` matches nothing), including the images where
  readiness is slowest and matters most: `elasticsearch/Dockerfile`, `opensearch/Dockerfile`,
  `mariadb/Dockerfile`, `mysql/Dockerfile`, `redis/Dockerfile`.
- *Why it matters:* Without a healthcheck, an orchestrator has no way to distinguish *started* from
  *ready*. `docker compose up --wait` and `depends_on: condition: service_healthy` both depend on it,
  so neither can be used, and every consumer has to reimplement readiness polling — or, more
  commonly, not implement it and race. The visible symptom is an application failing to reach a
  search cluster that is running but not yet accepting connections, which reads like a
  misconfiguration rather than a race, and gets worse under parallel load.
- *Suggested fix:* Add a `HEALTHCHECK` to each service image, testing the thing a client actually
  needs: `/_cluster/health` for the search images, a connection for the database images, `PING` for
  redis. This is the correct layer for it — one definition here removes the problem for every
  consumer of the image, whereas a compose-level healthcheck has to be repeated in every environment
  definition that uses the service.

**H2. Images are built and published without ever being run** — `.github/workflows/docker-image-*.yml`

- *What:* The per-image workflows build and push. None of them starts the image they just built and
  asserts anything about it; the `docker run` invocations that do appear (for example
  `.github/workflows/docker-image-opensearch.yml:44`) run the `crane` tool for upstream tag
  discovery, not the built artefact. `.github/workflows/docker-image-php-fpm.yml` contains no
  `docker run` of the built image at all.
- *Why it matters:* A green pipeline currently means "the Dockerfile built", not "the image works".
  This repository already documents an instance of exactly that gap:
  `MYDUMPER_CI_FINDINGS.md` records a change that was "correct and builds cleanly" while
  `roll mydumper` failed at runtime with `executable file not found in $PATH`. That class of defect —
  a binary that is missing, not on `PATH`, or missing a shared library — is invisible to a build and
  obvious to a one-line smoke test. It reaches every developer and build host before anyone notices.
- *Suggested fix:* A smoke-test step per image that runs the built tag and asserts its contract:
  `php -v` plus a representative extension check for `php-fpm`; `mydumper --version` and
  `myloader --version` for the magento2 variant; a `/_cluster/health` request for the search images;
  a trivial query for the database images. This need not be elaborate — the failures it catches are
  the crude ones.

### Medium

**M1. `zstd` is absent although `mydumper`/`myloader` ship in the image** — `php-fpm/magento2/Dockerfile:37`

- *What:* The magento2 variant builds `mydumper` and `myloader` from source and installs both
  (`php-fpm/magento2/Dockerfile:13-37`), but `zstd` is not installed in any image in this repository
  (verified — `grep -rn "zstd" --include="Dockerfile*"` matches nothing).
- *Why it matters:* Every `myloader` invocation logs
  `WARNING **: zstd was not found in PATH, use --exec-per-thread for non default locations` before
  doing anything else. On an uncompressed or gzip dump that is only noise — but it is noise on *every
  run*, at the top of every log, which trains readers to skip the start of myloader output where the
  real errors also appear. A zstd-compressed dump, which mydumper can produce, cannot be loaded at
  all. Since this image is the one that ships mydumper, it is the natural place for mydumper's
  compression dependency.
- *Suggested fix:* Add `zstd` to the package list in `php-fpm/magento2/Dockerfile` (the base
  `php-fpm/Dockerfile` already installs a comparable set of tools around lines 75-100). Whether the
  matching library is also needed for linked decompression rather than the CLI binary is
  *unverified*.

## Checked, and found correct

Recorded so the next reader does not re-investigate them. Two of these were open questions raised
against this repository from the outside; both turned out to be non-issues, and the entries record
how that was established.

| Considered | Finding |
|---|---|
| Does `php` in the `php-fpm` image resolve to a non-CLI SAPI? | **No.** The base is the official `php:${PHP_VERSION}-fpm-${OS_RELEASE}` image (`php-fpm/Dockerfile:8`) and neither `php-fpm/Dockerfile` nor `php-fpm/magento2/Dockerfile` replaces or shadows the `php` binary. Verified directly: `docker run --rm php:8.1-fpm-bullseye php -r 'var_dump(PHP_SAPI, defined("STDERR"));'` reports `"cli"` and `true`. So the `STDIN`/`STDOUT`/`STDERR` constants **are** available, and a script piped into `php` inside the container can use them. |
| Is `curl` present in the `php-fpm` image? | **Yes** — it ships in the official base image (noted at `php-fpm/Dockerfile:21` and verified by running the base image). Health-probing a service from inside the container needs no image change. |
| Are the search images doing anything unusual? | No — both are thin wrappers over the upstream vendor image that add the `analysis-phonetic` and `analysis-icu` plugins (`opensearch/Dockerfile`, `elasticsearch/Dockerfile`). Search-container memory limits are set by the consuming compose file, not here. |
