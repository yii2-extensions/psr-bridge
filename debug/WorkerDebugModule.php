<?php

declare(strict_types=1);

namespace yii2\extensions\debug;

use yii\debug\Module;

use function microtime;

final class WorkerDebugModule extends Module
{
    /**
     * Request start time captured at module initialization
     */
    private float|null $requestStartTime = null;

    public function init(): void
    {
        parent::init();

        $this->requestStartTime = microtime(true);
    }

    /**
     * @phpstan-return array<array-key, mixed>
     */
    protected function corePanels(): array
    {
        $corePanels = parent::corePanels();

        $corePanels['profiling'] = ['class' => WorkerProfilingPanel::class];
        $corePanels['timeline'] = ['class' => WorkerTimelinePanel::class];

        return $corePanels;
    }

    public function getRequestStartTime(): float
    {
        return $this->requestStartTime ?? microtime(true);
    }
}
