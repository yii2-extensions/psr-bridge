<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use yii\base\BootstrapInterface;
use yii\web\Session;

/**
 * Bootstrap stub that records the session state observed during the bootstrap stage.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class SessionBootstrap implements BootstrapInterface
{
    /**
     * Whether the session was active when bootstrap ran.
     */
    public static bool $active = false;
    /**
     * Session 'ID' observed when bootstrap ran.
     */
    public static string $id = '';

    /**
     * @param \yii\base\Application $app Application currently bootstrapping.
     */
    public function bootstrap($app): void
    {
        $session = $app->get('session');

        if ($session instanceof Session) {
            self::$active = $session->getIsActive();
            self::$id = $session->getId();
        }
    }
}
