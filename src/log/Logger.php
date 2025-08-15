<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\log;

use Yii;

final class Logger extends \yii\log\Logger
{
    public function getElapsedTime()
    {
        return microtime(true) -  Yii::$app->request->getRequestStartTime();
    }
}
