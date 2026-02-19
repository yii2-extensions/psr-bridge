<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\exception;

use yii\base\Exception;

/**
 * Thrown when code attempts to emit headers after headers are already sent.
 *
 * Usage example:
 * ```php
 * throw new \yii2\extensions\psrbridge\exception\HeadersAlreadySentException(
 *     \yii2\extensions\psrbridge\exception\Message::UNABLE_TO_EMIT_RESPONSE_HEADERS_ALREADY_SENT->getMessage(),
 * );
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class HeadersAlreadySentException extends Exception {}
