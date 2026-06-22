<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(__DIR__ . '/vendor/php-forge/coding-standard/src/rector-81.php');

    $rectorConfig->importNames(true, false);

    $rectorConfig->skip(
        [
            ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
                __DIR__ . '/tests/support/MockerExtension.php',
            ],
            ClassPropertyAssignToConstructorPromotionRector::class => [
                __DIR__ . '/src/adapter/RangeStream.php',
            ],
            NullToStrictStringFuncCallArgRector::class,
        ],
    );

    $rectorConfig->paths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ],
    );
};
