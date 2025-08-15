<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\logger;

use Yii;

use function array_merge;
use function count;

final class SyslogTarget extends \yii\log\SyslogTarget
{
    /**
     * @phpstan-param array<array-key, mixed> $messages
     */
    public function collect($messages, $final): void
    {
        $this->messages = array_merge(
            $this->messages,
            self::filterMessages($messages, $this->getLevels(), $this->categories, $this->except),
        );

        $count = count($this->messages);

        if ($count > 0 && ($final || $this->exportInterval > 0 && $count >= $this->exportInterval)) {
            if (($context = $this->getContextMessage()) !== '') {
                $this->messages[] = [
                    $context,
                    Logger::LEVEL_INFO,
                    'application',
                    Yii::$app->request->getRequestStartTime(),
                    [],
                    0,
                ];
            }

            // set exportInterval to 0 to avoid triggering export again while exporting
            $oldExportInterval = $this->exportInterval;

            $this->exportInterval = 0;

            $this->export();

            $this->exportInterval = $oldExportInterval;
            $this->messages = [];
        }
    }
}
