<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'constants.php');

$matrix = [];

$arches = [
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

foreach (PHP_VERSIONS as $phpVersion) {
    $experimental = in_array($phpVersion, EXPERIMENTAL_PHP_VERSIONS);
    $phpOsRelease = array_key_exists($phpVersion, PHP_VERSIONS_OS_RELEASE) ? PHP_VERSIONS_OS_RELEASE[$phpVersion] : 'bullseye';

    foreach ($arches as $arch) {
        $matrix[] = [
            'php_version' => $phpVersion,
            'php_os_release' => $phpOsRelease,
            'experimental' => $experimental,
            'arch' => $arch,
        ];
    }
}

echo 'matrix=' . json_encode(['include' => $matrix]);