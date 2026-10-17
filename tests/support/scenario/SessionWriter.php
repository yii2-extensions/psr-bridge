<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\scenario;

use RuntimeException;
use yii\base\BootstrapInterface;

/**
 * Writes a session value during eager bootstrap.
 */
final class SessionWriter implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        $count = $app->session->get('bootstrap-count', 0);

        if (!is_int($count)) {
            throw new RuntimeException('Invalid fixture counter.');
        }

        $app->session->set('bootstrap-count', $count + 1);
    }
}
