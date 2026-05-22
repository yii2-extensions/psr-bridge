<?php

declare(strict_types=1);

$stubs = require __DIR__ . '/../../vendor/xepozz/internal-mocker/src/stubs.php';

if (is_array($stubs) === false) {
    return [];
}

$stubs['fopen'] = [
    'signatureArguments' => 'string $filename, string $mode, bool $use_include_path = false, $context = null',
    'arguments' => '$filename, $mode, $use_include_path, $context',
];

return $stubs;
