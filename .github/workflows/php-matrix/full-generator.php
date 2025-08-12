<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

$matrix = [];

foreach (PHP_VERSIONS as $phpVersion) {
    $xdebugType = !in_array($phpVersion, NOT_STABLE_XDEBUG_PHP_VERSIONS) ? 'xdebug-stable' : 'xdebug';
    $phpOsRelease = array_key_exists($phpVersion, PHP_VERSIONS_OS_RELEASE) ? PHP_VERSIONS_OS_RELEASE[$phpVersion] : 'bullseye';
    $experimental = in_array($phpVersion, EXPERIMENTAL_PHP_VERSIONS);
    $endOfLife = in_array($phpVersion, EOL_PHP_VERSIONS);
    foreach (ARCHES as $arch) {
        $matrix[] = [
            'php_version' => $phpVersion,
            'php_os_release' => $phpOsRelease,
            'node_version' => 'x',
            'node_os_release' => 'x',
            'xdebug_type' => $xdebugType,
            'experimental' => $experimental,
            'end_of_life' => $endOfLife,
            'continue_on_error' => $endOfLife || $experimental,
            'latest' => $phpVersion === PHP_LATEST,
            'arch' => $arch,
        ];
        foreach (NODE_VERSIONS as $nodeVersion) {
            $nodeOsRelease = array_key_exists($nodeVersion, NODE_VERSIONS_OS_RELEASE) ? NODE_VERSIONS_OS_RELEASE[$nodeVersion] : 'bullseye';
            $endOfLife = in_array($nodeVersion, EOL_NODE_VERSIONS);
            $matrix[] = [
                'php_version' => $phpVersion,
                'php_os_release' => $phpOsRelease,
                'node_version' => $nodeVersion,
                'node_os_release' => $nodeOsRelease,
                'xdebug_type' => $xdebugType,
                'experimental' => $experimental,
                'end_of_life' => $endOfLife,
                'continue_on_error' => $endOfLife || $experimental,
                'latest' => $phpVersion === PHP_LATEST && $nodeVersion === NODE_LATEST,
                'arch' => $arch,
            ];
        }
    }
}

echo 'matrix=' . json_encode(['include' => $matrix]);