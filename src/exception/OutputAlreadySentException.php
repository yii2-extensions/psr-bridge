<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\exception;

use yii\base\Exception;

/**
 * Thrown when code attempts to emit a response after output is already sent.
 *
 * Usage example:
 * ```php
 * throw new \yii2\extensions\psrbridge\exception\OutputAlreadySentException(
 *     \yii2\extensions\psrbridge\exception\Message::UNABLE_TO_EMIT_OUTPUT_HAS_BEEN_EMITTED->getMessage(),
 * );
 * ```
 */
final class OutputAlreadySentException extends Exception {}
