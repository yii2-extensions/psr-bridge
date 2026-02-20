<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

/**
 * UrlManager stub that counts {@see parseRequest()} calls for testing purposes.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class UrlManagerSpy extends \yii\web\UrlManager
{
    /**
     * Number of times {@see parseRequest()} was called.
     */
    public static int $parseRequestCalls = 0;

    /**
     * Overrides the parent method to count calls without performing actual parsing.
     *
     * @phpstan-return mixed[]|false
     */
    public function parseRequest($request)
    {
        self::$parseRequestCalls++;

        return false;
    }

    /**
     * Resets the invocation counter.
     */
    public static function reset(): void
    {
        self::$parseRequestCalls = 0;
    }
}
