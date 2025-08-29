<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

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
