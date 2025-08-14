<?php

declare(strict_types=1);

namespace yii2\extensions\debug;

use Yii;
use yii\debug\panels\TimelinePanel;

use function memory_get_peak_usage;
use function microtime;

final class WorkerTimelinePanel extends TimelinePanel
{
    /**
     * @phpstan-return array<array-key, mixed>
     */
    public function save(): array
    {
        return [
            'start' => Yii::$app->request->getRequestStartTime(),
            'end' => microtime(true),
            'memory' => memory_get_peak_usage(),
        ];
    }
}
