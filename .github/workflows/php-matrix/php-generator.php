<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

$matrix = [];

foreach (php_versions() as $phpVersion) {
    $experimental = in_array($phpVersion, EXPERIMENTAL_PHP_VERSIONS);
    $endOfLife = in_array($phpVersion, EOL_PHP_VERSIONS);
    $phpOsRelease = php_os_release($phpVersion);

    foreach (ARCHES as $arch) {
        $matrix[] = [
            'php_version' => $phpVersion,
            'php_os_release' => $phpOsRelease,
            'experimental' => $experimental,
            'end_of_life' => $endOfLife,
            // The workflow reads matrix.continue_on_error; without this key an EOL or
            // experimental base version is still a hard failure, unlike every other layer.
            'continue_on_error' => $endOfLife || $experimental,
            'arch' => $arch,
        ];
    }
}

echo 'matrix=' . json_encode(['include' => $matrix]);
