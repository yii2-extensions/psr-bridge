<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter\exception;

use yii\base\Exception;

/**
 * Exception thrown when attempting to modify output after it has already been sent to the client.
 *
 * Represents errors that occur when output operations are performed after the response content has been sent,
 * preventing further modifications to headers, cookies, or session data.
 *
 * Handles errors such as.
 * - Buffer manipulation after a flush.
 * - Cookie operations after output.
 * - Header modifications after output.
 * - Late redirect attempts.
 * - Session operations after output.
 *
 * Key features.
 * - Integrates with output and response management logic.
 * - Maintains context for buffer and output state.
 * - Provides detailed error messages for debugging output state issues.
 * - Standardized error messages via the Message enum class for consistent error reporting.
 * - Supports exception chaining for detailed error context.
 * - Tracks output operation and start location.
 *
 * Usage example:
 * ```php
 * // Throwing with a custom message
 * throw new OutputAlreadySentException('Cannot modify headers');
 *
 * // Using the Message enum for standardized error
 * throw new OutputAlreadySentException(Message::OUTPUT_ALREADY_SENT->getMessage('/views/layout.php', 23));
 *
 * // With error code and previous exception
 * throw new OutputAlreadySentException('Output already sent', 500, $previous);
 * ```
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class OutputAlreadySentException extends Exception
{}
