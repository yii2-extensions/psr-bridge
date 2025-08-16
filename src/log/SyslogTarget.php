<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\log;

final class SyslogTarget extends \yii\log\SyslogTarget
{
    use CollectTrait;
}
