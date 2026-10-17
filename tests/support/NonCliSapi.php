<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

/**
 * Selects the alternate SAPI through normal constant lookup in an isolated test process.
 */
final class NonCliSapi
{
    public static function configure(string $namespace): void
    {
        define($namespace . '\\PHP_SAPI', 'fpm-fcgi');
    }
}
