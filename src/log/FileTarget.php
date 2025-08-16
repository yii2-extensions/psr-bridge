<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\log;

final class FileTarget extends \yii\log\FileTarget
{
    use CollectTrait;
}
