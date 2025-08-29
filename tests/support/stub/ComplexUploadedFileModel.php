<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use yii\base\Model;

final class ComplexUploadedFileModel extends Model
{
    public string $file_attribute = '';

    public function formName(): string
    {
        return 'Complex_Model-Name';
    }
}
