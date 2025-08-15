<?php

declare(strict_types=1);

namespace yii2\extensions\debug;

use Yii;
use yii\debug\Module;
use yii\helpers\Url;
use yii2\extensions\psrbridge\http\Response;

use function microtime;
use function number_format;

final class WorkerDebugModule extends Module
{
    public function init(): void
    {
        parent::init();

        $this->viewPath = '@yii/debug/views';
    }

    public function setDebugHeaders($event): void
    {
        if ($this->checkAccess() === false) {
            return;
        }

        if (is_string($this->logTarget) || is_array($this->logTarget)) {
            return;
        }

        $url = Url::toRoute(
            [
                '/' . $this->getUniqueId() . '/default/view',
                'tag' => $this->logTarget->tag,
            ],
        );

        if ($event->sender instanceof Response) {
            $requestStartTime = Yii::$app->request->getRequestStartTime();

            $event->sender->getHeaders()
                ->set('X-Debug-Tag', $this->logTarget->tag)
                ->set('X-Debug-Duration', number_format((microtime(true) - $requestStartTime) * 1000 + 1))
                ->set('X-Debug-Link', $url);
        }
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
}
