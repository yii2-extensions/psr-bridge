<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\exception;

use yii\base\Exception;

/**
 * Thrown when code attempts to emit a response after output is already sent.
 */
final class OutputAlreadySentException extends Exception {}
