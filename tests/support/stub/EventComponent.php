<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

final class EventComponent extends \yii\base\Component
{
    public function triggerTestEvent(): void
    {
        $this->trigger('test.internal.event');
    }
}
