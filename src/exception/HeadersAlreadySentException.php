<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\exception;

use yii\base\Exception;

/**
 * Thrown when code attempts to emit headers after headers are already sent.
 */
final class HeadersAlreadySentException extends Exception {}
