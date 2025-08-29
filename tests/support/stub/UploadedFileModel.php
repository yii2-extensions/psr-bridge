<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use yii\base\Model;
use yii2\extensions\psrbridge\http\UploadedFile;

final class UploadedFileModel extends Model
{
    /**
     * @phpstan-var UploadedFile|UploadedFile[]|null
     */
    public UploadedFile|array|null $file = null;

    public function formName(): string
    {
        return 'UploadedFileModel';
    }
}
