<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use yii\base\Model;
use yii2\extensions\psrbridge\http\UploadedFile;

final class ComplexUploadedFileModel extends Model
{
    /**
     * @phpstan-var UploadedFile|UploadedFile[]|null
     */
    public UploadedFile|array|null $file_attribute = null;

    public function formName(): string
    {
        return 'Complex_Model-Name';
    }
}
