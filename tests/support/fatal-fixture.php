<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\scenario;

/**
 * Declared twice by the native fatal-error regression to exercise the shutdown path.
 */
function workerFatalProbe(): void {}
