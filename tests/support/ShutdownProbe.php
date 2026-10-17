<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support {
    /**
     * Records native registrations without replacing their shutdown execution.
     */
    final class ShutdownProbe
    {
        /**
         * @var list<callable(): void>
         */
        public static array $callbacks = [];
    }
}

namespace yii2\extensions\psrbridge\http {
    use yii2\extensions\psrbridge\tests\support\ShutdownProbe;

    /**
     * @param callable(): void $callback
     */
    function register_shutdown_function(callable $callback): void
    {
        ShutdownProbe::$callbacks[] = $callback;
        \register_shutdown_function($callback);
    }
}
