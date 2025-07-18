<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter\exception;

/**
 * Represents standardized error messages for HTTP exceptions.
 *
 * This enum defines formatted error messages for various error conditions that may occur during HTTP request
 * processing, response generation, and exception handling operations.
 *
 * It provides a consistent and standardized way to present error messages across the HTTP system.
 *
 * Each case represents a specific type of error, with a message template that can be populated with dynamic values
 * using the {@see Message::getMessage()} method.
 *
 * This centralized approach improves the consistency of error messages and simplifies potential internationalization.
 *
 * Key features.
 * - Centralization of an error text for easier maintenance.
 * - Consistent error handling across the HTTP system.
 * - Integration with specific exception classes.
 * - Message formatting with dynamic parameters.
 * - Standardized error messages for common cases.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum Message: string
{
    /**
     * Error when buffer length is invalid.
     *
     * Format: "Buffer length for `%s` must be greater than zero; received `%d`."
     */
    case BUFFER_LENGTH_INVALID = 'Buffer length for `%s` must be greater than zero; received `%d`.';

    /**
     * Error when the request body can’t be parsed.
     *
     * Format: "Unable to parse request body; %s"
     */
    case FAIL_PARSING_REQUEST_BODY = 'Unable to parse request body; %s';

    /**
     * Error when output has already been emitted.
     *
     * Format: "Unable to emit response; output has been emitted previously."
     */
    case UNABLE_TO_EMIT_OUTPUT_HAS_BEEN_EMITTED = 'Unable to emit response; output has been emitted previously.';

    /**
     * Error when headers have already been sent.
     *
     * Format: "Unable to emit response; headers already sent."
     */
    case UNABLE_TO_EMIT_RESPONSE_HEADERS_ALREADY_SENT = 'Unable to emit response; headers already sent.';

    /**
     * Returns the formatted message string for the error case.
     *
     * Retrieves the raw message string associated with this error case without parameter interpolation.
     *
     * Exception classes use this method to create standardized error messages.
     *
     * @param int|string ...$argument The dynamic arguments to insert into the message.
     *
     * @return string The formatted error message.
     *
     * Usage example:
     * ```php
     * throw new BadRequestException(Message::BUFFER_LENGTH_INVALID->getMessage('buffer', 0));
     * ```
     */
    public function getMessage(int|string ...$argument): string
    {
        return sprintf($this->value, ...$argument);
    }
}
