<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\scenario;

use Closure;
use RuntimeException;
use yii\web\Controller;

/**
 * Runs real worker requests in a fresh PHP process, without PHPUnit's output and session state.
 */
final class ProbeController extends Controller
{
    /**
     * @var Closure(): mixed|null
     */
    public Closure|null $callback = null;

    public function actionIndex(): mixed
    {
        if ($this->callback === null) {
            throw new RuntimeException('Missing scenario callback.');
        }

        return ($this->callback)();
    }
}
