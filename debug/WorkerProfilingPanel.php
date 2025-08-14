<?php

declare(strict_types=1);

namespace yii2\extensions\debug;

use Yii;
use yii\debug\panels\ProfilingPanel;
use yii\log\Logger;

final class WorkerProfilingPanel extends ProfilingPanel
{
    /**
     * @phpstan-return array<array-key, mixed>
     */
    public function save(): array
    {
        $messages = $this->getLogMessages(Logger::LEVEL_PROFILE);

        return [
            'memory' => memory_get_peak_usage(),
            'time' => microtime(true) - Yii::$app->request->getRequestStartTime(),
            'messages' => $messages,
        ];
    }
}
