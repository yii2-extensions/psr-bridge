<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\log;

final class EmailTarget extends \yii\log\EmailTarget
{
    use CollectTrait;
}
