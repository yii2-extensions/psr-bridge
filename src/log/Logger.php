<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\log;

use Yii;

final class Logger extends \yii\log\Logger
{
    public function getElapsedTime(): float
    {
        $statelessAppStartTime = Yii::$app->request->getHeaders()->get('statelessAppStartTime') ?? YII_BEGIN_TIME;

        return microtime(true) -  (float) $statelessAppStartTime;
    }
}
