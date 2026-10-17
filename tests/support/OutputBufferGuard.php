<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support;

use LogicException;

/**
 * Bounds native buffer cleanup in test processes without changing production cleanup.
 */
final class OutputBufferGuard
{
    private const MAX_ATTEMPTS = 16;
    private static int $attempts = 0;

    public static function endClean(): bool
    {
        if (\ob_get_level() === 0 || ++self::$attempts > self::MAX_ATTEMPTS) {
            throw new LogicException('Output-buffer cleanup exceeded the test fixture budget.');
        }
        return \ob_end_clean();
    }

    public static function reset(): void
    {
        self::$attempts = 0;
    }
}
