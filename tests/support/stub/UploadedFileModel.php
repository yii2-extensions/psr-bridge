<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use yii\base\Model;

final class UploadedFileModel extends Model
{
    public string $file = '';

    public function formName(): string
    {
        return 'UploadedFileModel';
    }
}
