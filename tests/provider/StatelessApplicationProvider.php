<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

use yii2\extensions\psrbridge\http\StatelessApplication;

final class StatelessApplicationProvider
{
    /**
     * @phpstan-return array<string, array{string}>
     */
    public static function eventDataProvider(): array
    {
        return [
            'after request' => [StatelessApplication::EVENT_AFTER_REQUEST],
            'before request' => [StatelessApplication::EVENT_BEFORE_REQUEST],
        ];
    }
}
