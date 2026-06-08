<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

$matrix = [];

$phpVersions = php_versions();
$nodeVersions = node_versions();
$phpLatest = latest_version($phpVersions);
$nodeLatest = latest_version($nodeVersions);

foreach ($phpVersions as $phpVersion) {
    $xdebugType = !in_array($phpVersion, NOT_STABLE_XDEBUG_PHP_VERSIONS) ? 'xdebug-stable' : 'xdebug';
    $phpOsRelease = php_os_release($phpVersion);
    $experimental = in_array($phpVersion, EXPERIMENTAL_PHP_VERSIONS);
    $phpEndOfLife = in_array($phpVersion, EOL_PHP_VERSIONS);
    foreach (ARCHES as $arch) {
        foreach ($nodeVersions as $nodeVersion) {
            $nodeOsRelease = node_os_release($nodeVersion);
            // A combination is end-of-life if EITHER its PHP or its Node version
            // is EOL. Previously the PHP flag was overwritten by the Node flag,
            // so an EOL PHP paired with a supported Node (e.g. 7.3 + node21) was
            // wrongly marked non-EOL → continue_on_error=false → one flaky build
            // could fail the whole matrix and block publishing.
            $endOfLife = $phpEndOfLife || in_array($nodeVersion, EOL_NODE_VERSIONS);
            $matrix[] = [
                'php_version' => $phpVersion,
                'php_os_release' => $phpOsRelease,
                'node_version' => $nodeVersion,
                'node_os_release' => $nodeOsRelease,
                'xdebug_type' => $xdebugType,
                'experimental' => $experimental,
                'end_of_life' => $endOfLife,
                'continue_on_error' => $endOfLife || $experimental,
                'latest' => $phpVersion === $phpLatest && $nodeVersion === $nodeLatest,
                'arch' => $arch,
            ];
        }
    }
}

echo 'matrix=' . json_encode(['include' => $matrix]);
