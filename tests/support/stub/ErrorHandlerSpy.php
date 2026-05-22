<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use yii2\extensions\psrbridge\http\ErrorHandler;

/**
 * Error handler stub that counts {@see unregister()} calls for testing purposes.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ErrorHandlerSpy extends ErrorHandler
{
    /**
     * Number of times {@see unregister()} was called.
     */
    public static int $unregisterCalls = 0;

    /**
     * Resets the invocation counter.
     */
    public static function reset(): void
    {
        self::$unregisterCalls = 0;
    }

    public function unregister(): void
    {
        self::$unregisterCalls++;

        parent::unregister();
    }
}
