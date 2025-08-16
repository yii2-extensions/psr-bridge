<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\log;

final class DbTarget extends \yii\log\DbTarget
{
    use CollectTrait;
}
