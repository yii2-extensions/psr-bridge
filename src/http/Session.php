<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use WeakReference;
use Yii;

/**
 * Keeps native session shutdown and flash aging bounded to the worker request lifecycle.
 */
class Session extends \yii\web\Session
{
    /**
     * @var bool Indicates whether flash counters have been updated for the current request.
     */
    private bool $flashCountersUpdated = false;
    /**
     * @var bool Indicates whether the shutdown function has been registered.
     */
    private static bool $shutdownRegistered = false;
    /**
     * @var WeakReference<self> Current session without retaining previous request instances.
     */
    private static WeakReference $shutdownSession;

    /**
     * Replaces Yii's per-instance shutdown callback with one weak process dispatcher.
     */
    // @phpstan-ignore missingType.return (Keep Yii's untyped extension point compatible with existing subclasses.)
    public function init()
    {
        self::$shutdownSession = WeakReference::create($this);

        if (!self::$shutdownRegistered) {
            register_shutdown_function(
                static function (): void {
                    self::$shutdownSession->get()?->close();
                }
            );

            self::$shutdownRegistered = true;
        }

        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
    }

    /**
     * Ages flash values once even when bootstrap and the action reopen the session.
     */
    // @phpstan-ignore missingType.return (Keep Yii's untyped extension point compatible with existing subclasses.)
    protected function updateFlashCounters()
    {
        if (!$this->flashCountersUpdated) {
            $this->flashCountersUpdated = true;
            parent::updateFlashCounters();
        }
    }
}
