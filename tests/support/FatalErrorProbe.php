<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support {
    /**
     * Supplies a fatal record while keeping the isolated PHPUnit process alive.
     */
    final class FatalErrorProbe
    {
        /**
         * @var array{type: int, message: string, file: string, line: int}|null
         */
        public static array|null $error = null;
        /**
         * @var list<callable(): void>
         */
        public static array $exitCallbacks = [];
    }
}

namespace yii\base {
    use yii2\extensions\psrbridge\tests\support\FatalErrorProbe;

    /**
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    function error_get_last(): array|null
    {
        return FatalErrorProbe::$error;
    }

    /**
     * @param callable(): void $callback
     */
    function register_shutdown_function(callable $callback): void
    {
        FatalErrorProbe::$exitCallbacks[] = $callback;
    }
}
