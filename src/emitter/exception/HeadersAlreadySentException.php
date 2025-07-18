<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter\exception;

use yii\base\Exception;

/**
 * Exception thrown when attempting to modify HTTP headers after they've already been sent to the client.
 *
 * Represents errors that occur when header operations are performed after the HTTP response headers have been sent,
 * violating the protocol and preventing further header modifications.
 *
 * Handles errors such as.
 * - Attempting to set or modify headers after output has started.
 * - Changing content-type or status code too late in the response lifecycle.
 * - Cookie operations after headers have been sent.
 * - Late redirect attempts or header-based operations.
 * - Output buffer flushes that finalize headers prematurely.
 *
 * Key features.
 * - Integrates with HTTP response and output management logic.
 * - Maintains context for file and line where headers were sent.
 * - Provides detailed error messages for debugging header state issues.
 * - Standardized error messages via the Message enum class for consistent error reporting.
 * - Supports exception chaining for detailed error context.
 * - Tracks header operation and output state.
 *
 * Usage example:
 * ```php
 * // Throwing with a custom message
 * throw new HeadersAlreadySentException('Cannot modify headers after output started');
 *
 * // Using the Message enum for standardized error
 * throw new HeadersAlreadySentException(Message::HEADERS_ALREADY_SENT->getMessage('file.php', 23));
 *
 * // With error code and previous exception
 * throw new HeadersAlreadySentException('Headers already sent', 500, $previous);
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class HeadersAlreadySentException extends Exception
{
}
