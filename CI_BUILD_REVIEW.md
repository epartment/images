# PHP-FPM image pipeline review

A review of the build pipeline that produces the `php-fpm` image family
(`.github/workflows/docker-image-php-fpm.yml`, `.github/workflows/php-matrix/`, `php-fpm/**`),
prompted by an arm64 image that could not be pulled. The investigation found that the pipeline had
been failing on every scheduled run for roughly three months, so nothing in the family had been
published in that time.

Findings below are grouped by severity. Every claim carries a `file:line`. Claims that could not be
verified in this repository are labelled *unverified* in the sentence that makes them.

> **Status.** The pipeline published nothing between 2026-05-22 and 2026-08-26. [H1](#h1) is fixed
> and confirmed in CI (run `32977963468`: 14 of 16 base builds green, up from 0 of 16). That run
> still published no layer image, because [H2](#h2) failed the two PHP 8.5 jobs and [H5](#h5) then
> skipped every layer job in the chain. Fixes for H2, H5 and [M6](#m6) are applied in the working
> tree and not yet committed, along with [M1](#m1) — which is what makes a targeted, minutes-long
> rebuild of a single PHP/Node pair possible instead of a multi-hour full run.

## Severity

Matching the scale used in `FEATURE-REQUESTS.md`:

- **High** — causes failures, or allows a broken or stale image to be published.
- **Medium** — works, but warns on every run or blocks a supported workflow.
- **Low** — consistency and hygiene.

## Findings

### High

<a id="h1"></a>
**H1. `ZSH_CUSTOM` is unset under `set -u`, failing every base image build** — `php-fpm/Dockerfile:164`

- *What:* The step installs two oh-my-zsh plugins into `"$ZSH_CUSTOM/plugins/…"`. `ZSH_CUSTOM` is a
  shell variable that oh-my-zsh defines inside an interactive zsh session; it is not a Docker `ENV`,
  and each `RUN` is a fresh `/bin/sh`, so it is never set. The step also runs `set -eux`, and `set -u`
  turns the unset expansion into a fatal error: `/bin/sh: 1: ZSH_CUSTOM: parameter not set`, exit
  code 2. Reproduced locally by building `php-fpm/Dockerfile` with `PHP_VERSION=8.4`,
  `OS_RELEASE=bullseye`, `ENV_SOURCE_IMAGE=php` for `linux/arm64`.
- *Why it matters:* This is the base of the whole image graph, so it fails for every PHP version on
  both architectures — 16 of 16 build jobs. Everything downstream (`php-node`, `xdebug`, `magento1`,
  `magento2`, `magento2-xdebug`, `wordpress`) is gated on it with `needs:` and is therefore
  **skipped**, and the six `merge-*` jobs then exit 1 with "No … tags could be published". The result
  is not a partial publish; it is a total one. Every tag in the `php-fpm`, `php-fpm-debug`,
  `php-fpm-magento1`, `php-fpm-magento2`, `php-fpm-magento2-debug` and `php-fpm-wordpress`
  repositories has been frozen since 2026-05-22, and the gaps in that frozen state are permanent
  until a run succeeds. One visible consequence: `php-fpm-magento2-debug:8.4-node19` exists only for
  `linux/amd64`, so `roll env up` on an Apple Silicon machine for a PHP 8.4 Magento 2 project aborts
  the whole `docker compose` pull with `no matching manifest for linux/arm64/v8`.
- *History:* The bug is older than the outage. Before commit `5ef0357` (2026-06-08) the step was a
  bare `git clone … $ZSH_CUSTOM/plugins/…` with no `set -u`, so `$ZSH_CUSTOM` expanded to the empty
  string and the plugins were cloned to `/plugins/` — the build passed and the plugins were never
  loaded by zsh. That commit added the `set -eux` retry wrapper and converted a silent
  misconfiguration into a hard failure. The scheduled runs between 2026-05-23 and 2026-06-08 failed
  for a different reason: the pre-rework workflow gated its merge jobs on a plain `needs:`, so the
  single failing combination in run `26326351559` (`PHP-FPM 8.0 + Node 21`) failed the entire run.
  That brittleness is what `5ef0357` set out to fix, and it did.
- *Suggested fix:* **Fixed** in commit `f4e9e15` — resolve the variable inside the step
  with a documented default and create the parent directory before cloning:

  ```
  ZSH_CUSTOM="${ZSH_CUSTOM:-${ZSH:-/root/.oh-my-zsh}/custom}"; \
  ```

  Confirmed in CI: run `32977963468` built 14 of the 16 base jobs successfully, including
  `PHP-FPM 8.4 (arm64)`; only the two PHP 8.5 jobs failed, for the unrelated reason in [H2](#h2).
  Verified locally as well: the `8.4` arm64 build completes, and the plugins land at
  `/root/.oh-my-zsh/custom/plugins/zsh-autosuggestions` and `…/zsh-syntax-highlighting`, with
  `plugins=(git composer n98-magerun zsh-autosuggestions zsh-syntax-highlighting)` in `/root/.zshrc`
  — which they did not before this change, at any point in the repository's history.

<a id="h2"></a>
**H2. PHP 8.5 is pinned to a Debian base tag upstream does not publish** — `.github/workflows/php-matrix/constants.php:72`

- *What:* `PHP_VERSIONS_OS_RELEASE` maps `'8.5' => 'bullseye'`, so the build resolves its base image
  to `php:8.5-fpm-bullseye`. That tag does not exist; upstream publishes 8.5 only on bookworm
  (verified with `docker manifest inspect`: `php:8.5-fpm-bullseye` fails, `php:8.5-fpm-bookworm`
  resolves). 8.5 is in `PHP_PIN_VERSIONS` (`constants.php:56`) so it is always built, and it is not
  in `EXPERIMENTAL_PHP_VERSIONS` (`constants.php:90`), so `continue_on_error` is false for it.
- *Why it matters:* Two jobs fail on every run, and the workflow conclusion stays `failure` even once
  [H1](#h1) is fixed. A permanently red scheduled build is indistinguishable from a newly broken one,
  which is the condition that let the three-month outage go unnoticed (see [H4](#h4)).
- *Root cause beneath it:* discovery and the OS map are two sources of truth that cannot disagree
  safely. The `discover` job accepts a version whose tag matched either suite
  (`.github/workflows/docker-image-php-fpm.yml:84`), while `php_os_release()`
  (`constants.php:171`) defaults every unmapped version to bullseye. Any PHP version that upstream
  ships on bookworm only — 8.5 today, 8.6 and later by default — is therefore discovered as buildable
  and then pointed at a base tag that does not exist. This recurs on every future release, not just
  8.5.
- *Suggested fix:* Applied in the working tree — `PHP_DENY_VERSIONS = ['8.5']`, with a comment
  recording that 8.5 returns once the bookworm migration in [H3](#h3) lands. Note that the other
  candidate fix does **not** work: adding `'8.5'` to `EXPERIMENTAL_PHP_VERSIONS` has no effect on the
  base build, because `php-generator.php` never emitted the `continue_on_error` key the workflow
  reads — see [M6](#m6). Structurally, have `discover` emit the suite it actually matched alongside
  the version (for example `8.5:bookworm`) and let `php_os_release()` use the pinned map only as an
  override, so the default follows upstream instead of guessing.

<a id="h3"></a>
**H3. The whole matrix is pinned to Debian bullseye, and one hardcoded package blocks the move off it** — `.github/workflows/php-matrix/constants.php:64-84`, `php-fpm/magento2/Dockerfile:42`

- *What:* All eight entries of `PHP_VERSIONS_OS_RELEASE` and eleven of the twelve
  `NODE_VERSIONS_OS_RELEASE` entries are `bullseye` (Debian 11), and `php_os_release()` /
  `node_os_release()` default anything unlisted to bullseye as well. Separately,
  `php-fpm/magento2/Dockerfile:42` installs `libssl1.1` by name as a runtime dependency of the
  `mydumper`/`myloader` binaries built in the stage above it.
- *Why it matters:* Debian 11's regular security support ended in August 2024 and it has been on LTS
  since; when that window closes the suite moves to `archive.debian.org` and `deb.debian.org` stops
  serving it. Every image here runs `apt-get update` against `deb.debian.org` (`php-fpm/Dockerfile:54,73`
  and `php-fpm/magento2/Dockerfile:17,40` among others), so the day that happens,
  every build in this repository fails at once — the same total outage as [H1](#h1), from a cause
  that is on a calendar rather than a surprise. The exact LTS end date is *unverified* here and
  should be confirmed against Debian's LTS page; it is close enough to treat as urgent. The migration
  is not a one-line `OS_RELEASE` change, because `libssl1.1` does not exist on bookworm — verified by
  running `apt-cache policy libssl1.1 libssl3` in `debian:bookworm`, which offers only
  `libssl3 3.0.20-1~deb12u2`. A naive suite bump therefore turns a working `php-fpm-magento2` build
  into a failing one.
- *Suggested fix:* Migrate to bookworm deliberately, magento2 layer first: make the OpenSSL runtime
  dependency suite-aware rather than hardcoded (install `libssl3` on bookworm, `libssl1.1` on
  bullseye, selected from `${OS_RELEASE}` or by probing `apt-cache policy`), then flip
  `PHP_VERSIONS_OS_RELEASE` and `NODE_VERSIONS_OS_RELEASE` version by version, keeping the older PHP
  versions on bullseye only for as long as upstream still publishes them there. Bullseye-only PHP
  versions that outlive the suite should move to `PHP_DENY_VERSIONS` rather than be left to fail.

<a id="h4"></a>
**H4. A daily scheduled build failed for 96 consecutive days without notifying anyone** — `.github/workflows/docker-image-php-fpm.yml:13-14`

- *What:* The workflow runs on `cron: "0 6 * * *"`. Every run from 2026-05-23 to 2026-08-26 concluded
  `failure`. No workflow in `.github/workflows/` contains any failure-notification step (verified —
  no `if: failure()` step, and no notification action, appears in any workflow file). GitHub emails
  the actor on a scheduled-run failure, and for `schedule` events that is the user whose commit last
  touched the default branch — a single recipient, easy to filter away.
- *Why it matters:* The consequence of [H1](#h1) was not that a build broke; it was that a broken
  build stayed broken for three months while consumers silently kept pulling images that got further
  and further out of date, with no security or upstream-base refresh — which is the entire stated
  purpose of the daily schedule. The gap only surfaced when a developer hit a missing arm64 manifest,
  three months later, on an unrelated task.
- *Suggested fix:* Add a notification step to a channel more than one person reads, gated on
  `if: ${{ failure() && github.event_name == 'schedule' }}`, reporting the workflow and the failing
  job names. Combine it with [H2](#h2): the alert is only useful once a green run is the normal state.

<a id="h5"></a>
**H5. One failing PHP version skips every layer build for every version** — `.github/workflows/docker-image-php-fpm.yml:251,311,376,440,504,569`

- *What:* The six layer jobs (`php-node`, `xdebug`, `magento1`, `magento2`, `magento2-xdebug`,
  `wordpress`) depend on the previous layer with a plain `needs:` and no `if:` guard. GitHub skips a
  job when **any** job it needs concluded `failure`, and a matrix job's conclusion is the worst of its
  entries — so `php-fpm-build` reports `failure` if a single PHP version out of eight fails, and every
  layer job is skipped for all versions.
- *Why it matters:* This is what made [H1](#h1) a total outage rather than a partial one, and it is
  still live. Run `32977963468` proves it: 14 of 16 base builds succeeded and the base `merge` job
  published its tags, yet `php-node` and all five layers below it were **skipped** and the five layer
  `merge-*` jobs failed with no digests — because the two PHP 8.5 jobs ([H2](#h2)) failed. Fourteen
  perfectly good PHP versions produced no `php-fpm-magento2`, `php-fpm-magento2-debug` or
  `php-fpm-wordpress` image because of one version nobody asked for. The repository documents the
  opposite as an invariant ("build-matrix jobs are independent (`fail-fast: false`) — never introduce a
  shared failure point across versions"); `fail-fast: false` delivers that only *within* one matrix,
  not *between* layers, and the `!cancelled()` treatment was applied to the `merge-*` jobs only.
- *Suggested fix:* Applied in the working tree — give each layer job the same treatment the merge jobs
  already have, gated on its matrix generator so `fromJson` always has input:

  ```
  if: ${{ !cancelled() && needs.full-matrix.result == 'success' }}
  ```

  (`needs.node-matrix.result` for `php-node`.) Combinations whose own base image is missing then fail
  individually, `fail-fast: false` keeps the rest going, and the `EXPECTED_ARCHES=2` guard in the
  merge jobs skips exactly those tags — which is the behaviour the merge jobs were already written to
  expect.

### Medium

<a id="m1"></a>
**M1. The `workflow_dispatch` version inputs cannot narrow the build** — `.github/workflows/php-matrix/constants.php:154`

- *What:* `resolve_versions()` computes `$versions = array_merge($pin, $discovered)`, so the pinned
  list is always included regardless of what discovery or the manual inputs supplied; discovery is
  further filtered to versions strictly newer than the highest pin (`constants.php:129-136`), so a
  dispatch input naming an existing version contributes nothing at all. Verified by running the
  generator directly: with `DISCOVERED_PHP_VERSIONS=8.4` and `DISCOVERED_NODE_VERSIONS=19`,
  `full-generator.php` still emits 192 entries covering PHP 7.3–8.5 and Node 10–22.
- *Why it matters:* The `php_versions` / `node_versions` dispatch inputs are documented as the way to
  run ad-hoc builds, and they are inert for that purpose. The escape hatch is missing exactly when it
  is most needed: rebuilding one tag after a targeted fix means sitting through a full run (see
  [M2](#m2)) instead of a two-job one.
- *Suggested fix:* Applied in the working tree. `resolve_versions()` takes an optional
  `$overrideEnvVar`; when that variable is non-empty its list REPLACES the resolved set, skipping both
  the pin merge and the `maxPin` filter, while the deny-list and floor still apply. The three matrix
  generator jobs pass `OVERRIDE_PHP_VERSIONS` / `OVERRIDE_NODE_VERSIONS` from `inputs.*`. Verified by
  running the generators: a dispatch of `php_versions=8.4, node_versions=19` now yields 2 entries per
  layer instead of 192, a scheduled run with no inputs is unchanged (14 base / 168 full), and an
  override naming a denied version (`8.5`) or one below the floor (`7.0`) still yields 0 — a dispatch
  cannot resurrect a deliberately excluded version.

<a id="m2"></a>
**M2. The matrix is an unpruned cross product throttled to two jobs at a time** — `.github/workflows/php-matrix/full-generator.php:12-19`, `.github/workflows/docker-image-php-fpm.yml:325,390,454,518,583`

- *What:* `full-generator.php` nests PHP × arch × Node with no compatibility filter, producing 192
  entries (8 PHP × 12 Node × 2 arches), consumed by five separate jobs; `node-generator.php` produces
  another 192, and `php-generator.php` 16. That is 1168 build jobs per run — consistent with the 1031
  jobs recorded on the last successful run. Each of the five layer jobs sets
  `max-parallel: ${{ github.ref == 'refs/heads/master' && 2 || 0 }}`.
- *Why it matters:* Two effects. First, combinations that nobody can use are built and published:
  PHP 8.5 with Node 10, PHP 7.3 with Node 22. Second, 192 entries at two concurrent jobs is 96
  sequential rounds per layer, which is why a full run takes many hours — and a slow run makes the
  feedback loop on a CI fix painfully long, which compounds [M1](#m1). The `|| 0` branch is also not a
  valid `max-parallel` value; it applies to `pull_request` runs, where the build steps are skipped
  anyway, so the effect is *unverified* but it is not expressing what it appears to express.
- *Suggested fix:* Prune the cross product to supported pairs — a per-PHP minimum/maximum Node range
  in `constants.php` would remove most of the matrix. Once the job count is realistic, raise
  `max-parallel` accordingly.

<a id="m3"></a>
**M3. BuildKit is pinned to a 2022 release in every build job** — `.github/workflows/docker-image-php-fpm.yml:204,268,333,398,462,526,591`

- *What:* Every build job sets `driver-opts: image=moby/buildkit:v0.10.6` while using
  `docker/build-push-action@v6` with `outputs: type=image,push-by-digest=true,name-canonical=true`
  and registry-backed `cache-from`/`cache-to`. The pin was introduced in commit `9cc74d1`
  ("Try build for ARM64") and never revisited.
- *Why it matters:* The pin was a workaround for an arm64 problem that is unlikely to still exist, and
  it now holds the builder four years behind the action driving it. `push-by-digest` and the registry
  cache formats are features that postdate or evolved well past v0.10.6; the combination works today
  but is unsupported, and a future bump of `build-push-action` may break against it in a way that is
  hard to attribute. Whether v0.10.6 is strictly required for anything here is *unverified* — nothing
  in the repository records why it was pinned.
- *Suggested fix:* Drop the `driver-opts` block and let `docker/setup-buildx-action@v3` select its
  default BuildKit; if a regression appears, pin forward to a recent tag with a comment stating the
  reason.

<a id="m4"></a>
**M4. `--platform=$BUILDPLATFORM` on the final stage builds for the builder, not the target** — `php-fpm/Dockerfile:8`

- *What:* The final `FROM` is `FROM --platform=$BUILDPLATFORM ${ENV_SOURCE_IMAGE}:${PHP_VERSION}-fpm-${OS_RELEASE}`.
  `BUILDPLATFORM` is the platform of the machine running the build; `TARGETPLATFORM` is what
  `--platform` on the build command asked for. Pinning the final stage to the former makes the
  requested platform inert.
- *Why it matters:* It is invisible today only because each job runs on a native runner of its own
  architecture (`constants.php:33-50` maps arm64 to `ubuntu-24.04-arm`), so the two values coincide.
  But `docker/setup-qemu-action@v3` is present in the layer jobs
  (`.github/workflows/docker-image-php-fpm.yml:264,329,394,458,522,587`), so cross-building is one
  configuration change away — and the failure mode is silent: the job would push a host-architecture
  image under the other architecture's digest, and the `merge` job would assemble a manifest claiming
  two architectures that are really one. `--platform` on a build stage is meant for genuine
  cross-compiling builder stages, not for the stage that becomes the image.
- *Suggested fix:* Remove `--platform=$BUILDPLATFORM` from line 8 and let the build command's
  `platforms:` decide. Note this is the only image in the family that does it — the layered
  Dockerfiles (`php-fpm/node/Dockerfile:15`, `php-fpm/magento2/Dockerfile:31`,
  `php-fpm/magento2/xdebug3/Dockerfile:3`) already inherit correctly.

<a id="m5"></a>
**M5. The node layer swallows its own verification failure** — `php-fpm/node/Dockerfile:35`

- *What:* The step ends `&& /usr/local/bin/node -v || echo "Node failed to run"`. The `||` catches the
  whole `&&` chain, so if `ldd` reports missing libraries or the copied `node` binary cannot execute,
  the step prints a message and exits 0.
- *Why it matters:* The step exists precisely to catch a broken node — the layer assembles it by
  copying binaries and libraries out of a `node:` image into a `php:` image, which is exactly the
  arrangement where a glibc or `libstdc++` mismatch produces a binary that will not run. As written,
  that outcome ships as a green build, and the failure surfaces later inside a developer's container
  as a broken theme build. This is the same class of gap already recorded as H2 in
  `FEATURE-REQUESTS.md` ("images are built and published without ever being run"), except here the
  check is present and then discarded.
- *Suggested fix:* Drop the `|| echo …` so a non-running node fails the build. If the diagnostic
  output is worth keeping on failure, use an explicit `if ! /usr/local/bin/node -v; then … ; exit 1; fi`.

<a id="m6"></a>
**M6. Two of the three matrix generators never emit `continue_on_error`** — `.github/workflows/php-matrix/php-generator.php`, `.github/workflows/php-matrix/node-generator.php`

- *What:* Every build job declares `continue-on-error: ${{ matrix.continue_on_error || false }}`, but
  only `full-generator.php` sets that key. `php-generator.php` and `node-generator.php` computed
  `experimental` and emitted it under a key nothing reads, so the expression always fell through to
  `false`.
- *Why it matters:* The stated policy is that EOL and experimental combinations are tolerated so a
  flaky one cannot fail the run. That policy silently did not apply to the base PHP matrix or the
  `php-node` matrix — which is exactly where it mattered most, since those two layers gate everything
  else ([H5](#h5)). It also means the obvious remedy for [H2](#h2) — marking 8.5 experimental — would
  have appeared to do nothing.
- *Suggested fix:* Applied in the working tree — both generators now compute `end_of_life` the same
  way `full-generator.php` does (EOL on either the PHP or the Node side) and emit
  `'continue_on_error' => $endOfLife || $experimental`. Verified by running the generators: the base
  matrix now marks 4 of 14 entries continue-on-error (PHP 7.3 and 7.4, both arches) and the node
  matrix 128 of 168, matching `full-generator.php`.

<a id="m7"></a>
**M7. Each layer consumes a tag a sibling job publishes, with no ordering between them** — `.github/workflows/docker-image-php-fpm.yml:311,376,440,504,569,620`

- *What:* A layer job takes its parent by tag — `xdebug` builds `FROM ghcr.io/…/php-fpm:8.4-node19`,
  `magento2-xdebug` from `ghcr.io/…/php-fpm-magento2:8.4-node19`. Those tags are created by the
  `merge-*` jobs, which assemble the multi-arch manifest from the per-arch digests. But the layer jobs
  depend on the previous *build* job, not on its merge: `xdebug` has `needs: [php-node, full-matrix]`
  while `merge` has `needs: [php-fpm-build, php-node]`. They are independent siblings, so nothing
  orders them — `xdebug` may start the moment `php-node` finishes, while `merge` is still assembling
  the tag it is about to read.
- *Why it matters:* Each layer therefore reads whatever version of the parent tag happens to be in the
  registry when it starts, which in a steady state is the *previous* run's. The practical consequences
  — that a change to the base image takes several daily runs to reach the bottom of the chain, and
  that a genuinely new PHP/Node combination fails at the second layer because its parent tag does not
  exist yet — follow from the dependency graph, but are *unverified* against a real run here; the job
  timings needed to confirm the ordering empirically were not retrieved. The graph itself is plain
  from the YAML.
- *Suggested fix:* Point each layer at the merge job of the layer above it (`needs: [merge, …]`,
  `needs: [merge-magento2, …]`), keeping the `!cancelled()` guard from [H5](#h5). The chain then
  builds strictly top-down within one run. Alternatively, pass the parent by digest rather than by
  tag, which removes the dependency on a manifest existing at all.

### Low

<a id="l1"></a>
**L1. Stale no-node tags in the registry misrepresent architecture coverage** — `.github/workflows/php-matrix/full-generator.php`

- *What:* Every tag the current generators produce carries a `-node<N>` suffix
  (`.github/workflows/docker-image-php-fpm.yml:299,364,428,492,557,621` build the digest filenames
  from `${php_version}-node${node_version}`). Tags without that suffix — for example
  `php-fpm-magento2-debug:8.4` — are leftovers from an earlier tagging scheme and are never refreshed.
- *Why it matters:* Those stale tags are multi-arch, while the current `-node` tags for the same PHP
  version may not be, so inspecting the registry gives a misleading picture of what is available. This
  cost real time during the investigation that produced this document.
- *Suggested fix:* Delete the orphaned no-suffix tags from the package registry, or document them as
  historical in `README.md`.

<a id="l2"></a>
**L2. Duplicate `COPY` of `/opt` in the node layer** — `php-fpm/node/Dockerfile:25,49`

- *What:* `COPY --from=node /opt /opt` appears twice, once mid-file and once as the last instruction
  under a `# Copy Yarn` comment.
- *Why it matters:* An extra layer copying identical content; harmless but confusing, and it makes the
  yarn symlinks at lines 45-46 look as if they precede the copy that provides them (they do not — line
  25 already did).
- *Suggested fix:* Remove line 49 and its comment.

<a id="l3"></a>
**L3. Layer build jobs omit the architecture from their display name** — `.github/workflows/docker-image-php-fpm.yml`

- *What:* Only the base build includes the arch in its name. The six layer jobs do not, so each
  produces two identically-named jobs per PHP/Node pair.

  | Line | Job name |
  |---|---|
  | 249 | `PHP-FPM ${{ matrix.php_version }} + Node ${{ matrix.node_version }}` |
  | 317 | `PHP-FPM ${{ matrix.php_version }} + XDebug - Node ${{ matrix.node_version }}` |
  | 382 | `Magento 1 PHP-FPM ${{ matrix.php_version }} - Node ${{ matrix.node_version }}` |
  | 446 | `Magento 2 PHP-FPM ${{ matrix.php_version }} - Node ${{ matrix.node_version }}` |
  | 510 | `Magento 2 PHP-FPM ${{ matrix.php_version }} + XDebug - Node ${{ matrix.node_version }}` |
  | 575 | `Wordpress PHP-FPM ${{ matrix.php_version }} - Node ${{ matrix.node_version }}` |

- *Why it matters:* A single-architecture failure is the exact condition the `EXPECTED_ARCHES=2` guard
  in the merge jobs exists to handle, and it is the condition that leaves a tag published for one
  architecture only. Finding which of two same-named jobs failed means opening both.
- *Suggested fix:* Append `(${{ matrix.arch.cache_arch }})` to each, matching line 190.

<a id="l4"></a>
**L4. Dockerfile lint warnings on every base build** — `php-fpm/Dockerfile:8,12-17`

- *What:* BuildKit reports seven warnings: six `LegacyKeyValueFormat` (`ENV key value` instead of
  `ENV key=value`, lines 12-17) and one `InvalidDefaultArgInFrom` for line 8, whose `ARG` defaults
  make the base image name invalid when no build args are supplied.
- *Why it matters:* Cosmetic, but the `InvalidDefaultArgInFrom` one means `docker build` on this
  Dockerfile with no `--build-arg` fails rather than producing a sensible default, which makes local
  reproduction of a CI failure harder than it needs to be.
- *Suggested fix:* Convert the `ENV` lines to `key=value` form, and give `PHP_VERSION` a default at
  `php-fpm/Dockerfile:2` matching the newest supported version.

## Checked, and found correct

Recorded so the next reader does not re-investigate them.

| Considered | Finding |
|---|---|
| Is the missing arm64 manifest caused by the consuming CLI building a wrong tag? | **No.** The `epartment/rolldev` CLI derives the debug image tag from the same PHP version and Node suffix as the non-debug one, so it requests a tag that is simply absent from the registry. The defect is entirely on the publishing side ([H1](#h1)). |
| Do the external build-stage images support arm64? | **Yes, all of them.** Verified with `docker manifest inspect`: `99designs/phantomjs:2.1.1` (amd64 + arm64), `composer:1`, `composer:2`, `composer:2.2` and `golang:alpine` (all multi-arch). The phantomjs copy at `php-fpm/node/Dockerfile:38` was the strongest suspect for an arm64-only failure and is not one. |
| Does the `merge` job group digests correctly when base and node artefacts share one directory? | **Yes.** Base digests are named `<sha>-<php>` and node digests `<sha>-<php>-node<n>` (`.github/workflows/docker-image-php-fpm.yml:236,299`), both downloaded under `pattern: digests-*` (line 648). `cut -d- -f 2-` recovers the full version because the sha is dash-free, and the `*-"${version}"` glob does not cross between the two forms. |
| Is the `EXPECTED_ARCHES=2` skip logic sound? | **Yes.** A version with fewer than two arch digests is skipped with a warning and keeps its previous image rather than being republished as a single-arch manifest, and the loop continues to the remaining versions. This is what prevented the outage from *removing* existing tags — the images went stale rather than disappearing. |
| Do the other pinned PHP base tags exist upstream? | **Yes**, except 8.5 ([H2](#h2)). `php:7.3-fpm-bullseye`, `php:8.3-fpm-bullseye` and `php:8.4-fpm-bullseye` all resolve, as do `node:19-bullseye` and `node:22-bullseye`. |
| Was the `mailpit` sendmail builder stage or the `install-php-extensions` download the cause? | **No.** Both complete successfully in a local reproduction of the failing build; the base image reaches step 42 of 45 before failing at [H1](#h1). |
