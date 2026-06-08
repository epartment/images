<?php

// =============================================================================
// Version configuration for the php-fpm image matrix.
//
// PHP and Node versions are normally DISCOVERED automatically from the upstream
// registry by the workflow (see docker-image-php-fpm.yml — the "discover" job
// lists the available `php` / `node` tags with crane and passes them to the
// generators via the DISCOVERED_PHP_VERSIONS / DISCOVERED_NODE_VERSIONS env
// vars). This file is the OVERRIDE / CONTROL layer applied on top of discovery:
//
//   *_MIN_VERSION   Floor — discovery (and the fallback list) ignore anything
//                   older than this.
//   *_DENY_VERSIONS Never build these, even if upstream still publishes them.
//   *_PIN_VERSIONS  Always build these AND use them as the static fallback when
//                   discovery is unavailable (running the generators locally, or
//                   the registry being unreachable in CI).
//   *_OS_RELEASE    Pin the Debian base (bullseye/bookworm/…) for a version.
//                   This map is authoritative; a version not listed defaults to
//                   bullseye.
//   EOL / EXPERIMENTAL / xdebug   Per-version build flags.
//
// HOW TO ADD OR CHANGE A VERSION (see README.md → "Adding or changing a version")
//   * Add a version:    usually nothing — it is picked up automatically once it
//                       appears upstream above the floor. To guarantee it builds
//                       regardless of discovery, add it to the relevant *_PIN.
//   * Stop building one: add it to the relevant *_DENY (or raise the floor).
//   * Change its Debian base: set it in *_OS_RELEASE.
//   * Mark it EOL / experimental: add it to the matching list below so a flaky
//                       build of it can't fail the run (continue-on-error).
// =============================================================================

const ARCHES = [
    [
        'name' => 'x86',
        'runs_on' => 'ubuntu-24.04',
        'docker_platform' => 'linux/amd64',
        'platform_pair' => 'linux-amd64',
        'cache_suffix' => 'x86',
        'cache_arch' => 'amd64'
    ],
    [
        'name' => 'arm64',
        'runs_on' => 'ubuntu-24.04-arm',
        'docker_platform' => 'linux/arm64',
        'platform_pair' => 'linux-arm64',
        'cache_suffix' => 'arm64',
        'cache_arch' => 'arm64'
    ],
];

// ---- PHP --------------------------------------------------------------------
const PHP_MIN_VERSION = '7.3';
const PHP_DENY_VERSIONS = [];
// Built unconditionally + fallback list when discovery is unavailable.
const PHP_PIN_VERSIONS = ['7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];

// ---- Node -------------------------------------------------------------------
const NODE_MIN_VERSION = '10';
const NODE_DENY_VERSIONS = [];
const NODE_PIN_VERSIONS = ['10', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22'];

// ---- Debian base pinning (authoritative; overrides discovery) ---------------
const PHP_VERSIONS_OS_RELEASE = [
    '7.3' => 'bullseye',
    '7.4' => 'bullseye',
    '8.0' => 'bullseye',
    '8.1' => 'bullseye',
    '8.2' => 'bullseye',
    '8.3' => 'bullseye',
    '8.4' => 'bullseye',
];
const NODE_VERSIONS_OS_RELEASE = [
    '10' => 'stretch',
    '12' => 'bullseye',
    '13' => 'buster',
    '14' => 'bullseye',
    '15' => 'buster',
    '16' => 'bullseye',
    '17' => 'bullseye',
    '18' => 'bullseye',
    '19' => 'bullseye',
    '20' => 'bullseye',
    '21' => 'bullseye',
    '22' => 'bullseye',
];

// ---- Build flags ------------------------------------------------------------
const EXPERIMENTAL_PHP_VERSIONS = [];
const EOL_PHP_VERSIONS = ['7.3', '7.4'];
const EOL_NODE_VERSIONS = ['10', '12', '13', '14', '15', '16', '17', '19'];
const NOT_STABLE_XDEBUG_PHP_VERSIONS = ['7.3', '7.4'];

/**
 * Resolve the list of versions to build for one component (PHP or Node).
 *
 * Reads the discovered list from $envVar (comma / whitespace / newline
 * separated). The pinned versions are always merged in, so when discovery is
 * unavailable (the generators are run locally, or the registry could not be
 * reached) the pinned list is what gets built. The deny-list and the version
 * floor are then applied, and the result is de-duplicated and version-sorted
 * ascending.
 */
function resolve_versions(string $envVar, array $pin, array $deny, string $min): array
{
    $raw = getenv($envVar);
    $discovered = ($raw !== false && trim($raw) !== '')
        ? preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY)
        : [];

    $versions = array_merge($pin, $discovered);

    $versions = array_filter($versions, static function ($v) use ($deny, $min) {
        if (in_array($v, $deny, true)) {
            return false;
        }
        return version_compare($v, $min, '>=');
    });

    $versions = array_values(array_unique($versions));
    usort($versions, 'version_compare');

    return $versions;
}

/** The PHP versions to build (discovery ∪ pin, minus deny, above the floor). */
function php_versions(): array
{
    return resolve_versions('DISCOVERED_PHP_VERSIONS', PHP_PIN_VERSIONS, PHP_DENY_VERSIONS, PHP_MIN_VERSION);
}

/** The Node versions to build (discovery ∪ pin, minus deny, above the floor). */
function node_versions(): array
{
    return resolve_versions('DISCOVERED_NODE_VERSIONS', NODE_PIN_VERSIONS, NODE_DENY_VERSIONS, NODE_MIN_VERSION);
}

/** Highest version in a version-sorted list (used for the `latest` flag). */
function latest_version(array $versions): string
{
    return empty($versions) ? '' : end($versions);
}

/** Debian base for a PHP version: pinned map wins, else bullseye. */
function php_os_release(string $phpVersion): string
{
    return PHP_VERSIONS_OS_RELEASE[$phpVersion] ?? 'bullseye';
}

/** Debian base for a Node version: pinned map wins, else bullseye. */
function node_os_release(string $nodeVersion): string
{
    return NODE_VERSIONS_OS_RELEASE[$nodeVersion] ?? 'bullseye';
}
