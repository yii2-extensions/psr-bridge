<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use RuntimeException;
use yii\base\BootstrapInterface;

/**
 * Bootstrap stub that always throws to exercise session finalization on bootstrap failure.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ThrowingBootstrap implements BootstrapInterface
{
    /**
     * @param \yii\base\Application $app Application currently bootstrapping.
     *
     * @throws RuntimeException Always, to simulate a failing bootstrap component.
     */
    public function bootstrap($app): never
    {
        throw new RuntimeException('Bootstrap failure.');
    }
}
