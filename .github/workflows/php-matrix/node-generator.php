<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

$matrix = [];

foreach (php_versions() as $phpVersion) {
    $experimental = in_array($phpVersion, EXPERIMENTAL_PHP_VERSIONS);
    $phpEndOfLife = in_array($phpVersion, EOL_PHP_VERSIONS);
    $phpOsRelease = php_os_release($phpVersion);
    foreach (node_versions() as $nodeVersion) {
        $nodeOsRelease = node_os_release($nodeVersion);
        // Matches full-generator.php: EOL on EITHER side marks the combination EOL.
        $endOfLife = $phpEndOfLife || in_array($nodeVersion, EOL_NODE_VERSIONS);
        foreach (ARCHES as $arch) {
            $matrix[] = [
                'php_version' => $phpVersion,
                'php_os_release' => $phpOsRelease,
                'node_version' => $nodeVersion,
                'node_os_release' => $nodeOsRelease,
                'experimental' => $experimental,
                'end_of_life' => $endOfLife,
                // The workflow reads matrix.continue_on_error; without this key an EOL or
                // experimental combination is a hard failure, unlike the layers above it.
                'continue_on_error' => $endOfLife || $experimental,
                'arch' => $arch,
            ];
        }
    }
}

echo 'matrix=' . json_encode(['include' => $matrix]);
