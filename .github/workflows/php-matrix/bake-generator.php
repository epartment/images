<?php

declare(strict_types=1);

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

const DEFAULT_REGISTRY = 'ghcr.io/epartment/roll';
const DEFAULT_PHP_SOURCE_IMAGE = 'php';
const UNAVAILABLE = '-';
const FIXED_UPSTREAM_IMAGES = ['composer:1', 'composer:2.2', 'composer:2', 'golang:alpine', '99designs/phantomjs:2.1.1'];
const VARIANT_IMAGES = ['php-fpm', 'php-fpm-debug', 'php-fpm-magento1', 'php-fpm-magento2', 'php-fpm-magento2-debug', 'php-fpm-wordpress'];

function env_string(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function php_upstream_ref(string $phpVersion): string
{
    return env_string('PHP_SOURCE_IMAGE', DEFAULT_PHP_SOURCE_IMAGE) . ':' . $phpVersion . '-fpm-' . php_os_release($phpVersion);
}

function node_upstream_ref(string $nodeVersion): string
{
    return 'node:' . $nodeVersion . '-' . node_os_release($nodeVersion);
}

function registry_image(string $image, string $tag): string
{
    return env_string('REGISTRY', DEFAULT_REGISTRY) . '/' . $image . ':' . $tag;
}

function tools_ref(string $phpVersion, array $arch): string
{
    return registry_image('php-fpm', $phpVersion . '-tools-arch-' . $arch['cache_suffix']);
}

/** The published base tag and the per-arch tools tags whose labels decide whether the base is unchanged. */
function base_published_refs(string $phpVersion): array
{
    $refs = [registry_image('php-fpm', $phpVersion)];
    foreach (ARCHES as $arch) {
        $refs[] = tools_ref($phpVersion, $arch);
    }

    return $refs;
}

/** Every image tag of one PHP x Node combination whose fingerprint label is compared. */
function node_published_refs(string $phpVersion, string $nodeVersion): array
{
    return array_map(
        static fn(string $image): string => registry_image($image, $phpVersion . '-node' . $nodeVersion),
        VARIANT_IMAGES
    );
}

/** @return list<array{string, string}> [kind, ref] pairs the plan needs from the registry. */
function registry_refs(array $phpVersions, array $nodeVersions): array
{
    $refs = [];
    foreach (FIXED_UPSTREAM_IMAGES as $ref) {
        $refs[] = ['upstream', $ref];
    }
    foreach ($phpVersions as $phpVersion) {
        $refs[] = ['upstream', php_upstream_ref($phpVersion)];
        foreach (base_published_refs($phpVersion) as $ref) {
            $refs[] = ['published', $ref];
        }
        foreach ($nodeVersions as $nodeVersion) {
            foreach (node_published_refs($phpVersion, $nodeVersion) as $ref) {
                $refs[] = ['published', $ref];
            }
        }
    }
    foreach ($nodeVersions as $nodeVersion) {
        $refs[] = ['upstream', node_upstream_ref($nodeVersion)];
    }

    $lines = array_unique(array_map(static fn(array $pair): string => $pair[0] . "\t" . $pair[1], $refs));
    sort($lines, SORT_STRING);

    return $lines;
}

/**
 * Hex sha256 of the newline-joined inputs. An input equal to UNAVAILABLE (a digest that could not
 * be read) makes the result "unknown-<run id>", which never equals a published label, so the image
 * is built.
 */
function fingerprint(array $inputs, string $runId): string
{
    if (in_array(UNAVAILABLE, $inputs, true)) {
        return 'unknown-' . $runId;
    }

    return hash('sha256', implode("\n", $inputs));
}

/** Inputs shared by every fingerprint: source tree, build week and the fixed upstream images. */
function common_inputs(array $state, string $treeHash, string $buildWeek): array
{
    $inputs = [$treeHash, $buildWeek];
    foreach (FIXED_UPSTREAM_IMAGES as $ref) {
        $inputs[] = $state[$ref] ?? UNAVAILABLE;
    }

    return $inputs;
}

function base_inputs(array $state, string $phpVersion, array $common): array
{
    return array_merge($common, [$state[php_upstream_ref($phpVersion)] ?? UNAVAILABLE, xdebug_type($phpVersion)]);
}

function node_inputs(array $state, array $baseInputs, string $nodeVersion): array
{
    return array_merge($baseInputs, [$state[node_upstream_ref($nodeVersion)] ?? UNAVAILABLE]);
}

/** Whether every given published ref carries the fingerprint as its label value. */
function all_labelled(array $state, array $refs, string $fingerprint): bool
{
    foreach ($refs as $ref) {
        if (($state[$ref] ?? UNAVAILABLE) !== $fingerprint) {
            return false;
        }
    }

    return true;
}

/** @return array<string, string> ref => value, from tab-separated "<kind>\t<ref>\t<value>" lines. */
function parse_state(string $input): array
{
    $state = [];
    foreach (preg_split('/\R/', $input, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        $parts = explode("\t", $line);
        if (count($parts) >= 3) {
            $state[$parts[1]] = $parts[2];
        }
    }

    return $state;
}

/** Node versions that need building, split into EOL and supported groups, each in chunks of NODE_CHUNK_SIZE. */
function node_chunks(array $nodesToBuild): array
{
    $chunks = [];
    foreach ([true, false] as $eol) {
        $group = array_values(array_filter(
            $nodesToBuild,
            static fn(array $node): bool => node_is_eol($node['v']) === $eol
        ));
        foreach (array_chunk($group, NODE_CHUNK_SIZE) as $chunk) {
            $chunks[] = ['eol' => $eol, 'nodes' => $chunk];
        }
    }

    return $chunks;
}

function chunk_label(array $nodes): string
{
    return 'node' . implode('-', array_column($nodes, 'v'));
}

/** @return array{base: list<array>, chunks: list<array>, expected: array<string, list<string>>, summary: list<string>} */
function build_plan(array $state, array $phpVersions, array $nodeVersions): array
{
    $force = env_string('FORCE') === '1';
    $runId = env_string('RUN_ID', '0');
    $common = common_inputs($state, env_string('TREE_HASH'), env_string('BUILD_WEEK'));

    $base = [];
    $chunks = [];
    $expected = array_fill_keys(VARIANT_IMAGES, []);
    $summary = [];

    foreach ($phpVersions as $phpVersion) {
        $baseInputs = base_inputs($state, $phpVersion, $common);
        $baseFingerprint = fingerprint($baseInputs, $runId);
        $baseBuilt = $force || !all_labelled($state, base_published_refs($phpVersion), $baseFingerprint);

        $nodesToBuild = [];
        foreach ($nodeVersions as $nodeVersion) {
            $nodeFingerprint = fingerprint(node_inputs($state, $baseInputs, $nodeVersion), $runId);
            if ($force || !all_labelled($state, node_published_refs($phpVersion, $nodeVersion), $nodeFingerprint)) {
                $nodesToBuild[] = [
                    'v' => $nodeVersion,
                    'os' => node_os_release($nodeVersion),
                    'gyp_python' => node_gyp_python($phpVersion, $nodeVersion),
                    'fingerprint' => $nodeFingerprint,
                ];
            }
        }

        $phpFields = [
            'php_version' => $phpVersion,
            'php_os_release' => php_os_release($phpVersion),
            'xdebug_type' => xdebug_type($phpVersion),
        ];
        $chunksToBuild = node_chunks($nodesToBuild);
        foreach (ARCHES as $arch) {
            $suffix = $arch['cache_suffix'];
            if ($baseBuilt) {
                $base[] = $phpFields + [
                    'fingerprint' => $baseFingerprint,
                    'continue_on_error' => php_continue_on_error($phpVersion),
                    'arch' => $arch,
                ];
                $expected['php-fpm'][] = $phpVersion . ' ' . $suffix;
            }
            foreach ($chunksToBuild as $chunk) {
                $chunks[] = $phpFields + [
                    'base_fingerprint' => $baseFingerprint,
                    'base_built' => $baseBuilt,
                    'chunk' => chunk_label($chunk['nodes']),
                    'nodes' => json_encode($chunk['nodes'], JSON_UNESCAPED_SLASHES),
                    'continue_on_error' => php_continue_on_error($phpVersion) || $chunk['eol'],
                    'arch' => $arch,
                ];
            }
            foreach ($nodesToBuild as $node) {
                foreach (VARIANT_IMAGES as $image) {
                    $expected[$image][] = $phpVersion . '-node' . $node['v'] . ' ' . $suffix;
                }
            }
        }

        $summary[] = sprintf(
            'PHP %s: base %s, %d of %d node versions built (%d skipped)',
            $phpVersion,
            $baseBuilt ? 'built' : 'skipped',
            count($nodesToBuild),
            count($nodeVersions),
            count($nodeVersions) - count($nodesToBuild)
        );
    }

    return ['base' => $base, 'chunks' => $chunks, 'expected' => array_filter($expected), 'summary' => $summary];
}

function json_list(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function run_refs(): int
{
    foreach (registry_refs(php_versions(), node_versions()) as $line) {
        echo $line . "\n";
    }

    return 0;
}

function run_matrix(): int
{
    if (env_string('FORCE') !== '1' && (env_string('TREE_HASH') === '' || env_string('BUILD_WEEK') === '')) {
        fwrite(STDERR, "TREE_HASH and BUILD_WEEK must be set: without them nothing would ever count as changed.\n");

        return 2;
    }

    $plan = build_plan(parse_state((string) stream_get_contents(STDIN)), php_versions(), node_versions());

    echo 'base=' . json_list($plan['base']) . "\n";
    echo 'chunks=' . json_list($plan['chunks']) . "\n";
    echo 'expected=' . json_encode((object) $plan['expected'], JSON_UNESCAPED_SLASHES) . "\n";
    echo 'images=' . json_list(array_keys($plan['expected'])) . "\n";
    echo 'has_base=' . ($plan['base'] === [] ? 'false' : 'true') . "\n";
    echo 'has_chunks=' . ($plan['chunks'] === [] ? 'false' : 'true') . "\n";
    fwrite(STDERR, implode("\n", $plan['summary']) . "\n");

    return 0;
}

function main(array $argv): int
{
    $mode = $argv[1] ?? '';
    if ($mode === 'refs') {
        return run_refs();
    }
    if ($mode === 'matrix') {
        return run_matrix();
    }
    fwrite(STDERR, "Usage: bake-generator.php refs|matrix\n");

    return 2;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}
