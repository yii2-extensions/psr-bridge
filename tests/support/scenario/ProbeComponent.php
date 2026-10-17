<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\scenario;

use yii\base\Component;

/**
 * Exposes a persistent component event for lifecycle checks.
 */
final class ProbeComponent extends Component
{
    public function fire(): void
    {
        $this->trigger('probe');
    }
}
