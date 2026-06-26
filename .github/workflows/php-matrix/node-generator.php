<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

$matrix = [];

foreach (php_versions() as $phpVersion) {
    $experimental = in_array($phpVersion, EXPERIMENTAL_PHP_VERSIONS);
    $phpOsRelease = php_os_release($phpVersion);
    foreach (node_versions() as $nodeVersion) {
        $nodeOsRelease = node_os_release($nodeVersion);
        foreach (ARCHES as $arch) {
            $matrix[] = [
                'php_version' => $phpVersion,
                'php_os_release' => $phpOsRelease,
                'node_version' => $nodeVersion,
                'node_os_release' => $nodeOsRelease,
                'experimental' => $experimental,
                'arch' => $arch,
            ];
        }
    }
}

echo 'matrix=' . json_encode(['include' => $matrix]);
