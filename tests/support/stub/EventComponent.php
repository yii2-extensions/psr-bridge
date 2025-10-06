<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

/**
 * Component for testing event triggering in Yii2 applications.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class EventComponent extends \yii\base\Component
{
    /**
     * Event name for internal testing.
     */
    public const EVENT_TEST_INTERNAL = 'test.internal.event';

    public function triggerTestEvent(): void
    {
        $this->trigger(self::EVENT_TEST_INTERNAL);
    }
}
