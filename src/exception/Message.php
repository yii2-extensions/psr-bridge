<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\exception;

use function sprintf;

/**
 * Represents error message templates for attribute exceptions.
 *
 * Use {@see Message::getMessage()} to format the template with `sprintf()` arguments.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum Message: string
{
    /**
     * Error when buffer length is invalid.
     *
     * Format: "Buffer length for '%s' must be greater than zero; received '%d'."
     */
    case BUFFER_LENGTH_INVALID = "Buffer length for '%s' must be greater than zero; received '%d'.";

    /**
     * Error when the cookie validation key is not configured for a specific class.
     *
     * Format: "'%s::cookieValidationKey' must be configured with a secret key."
     */
    case COOKIE_VALIDATION_KEY_NOT_CONFIGURED = "'%s::cookieValidationKey' must be configured with a secret key.";

    /**
     * Error when the cookie validation key is missing.
     *
     * Format: "Cookie validation key must be provided."
     */
    case COOKIE_VALIDATION_KEY_REQUIRED = 'Cookie validation key must be provided.';

    /**
     * Error when 'error' is not an integer in file specification.
     *
     * Format: "'error' must be an integer in file specification."
     */
    case ERROR_MUST_BE_INTEGER = "'error' must be an 'integer' in file specification.";

    /**
     * Error when the request body can’t be parsed.
     *
     * Format: "Unable to parse request body; '%s'"
     */
    case FAIL_PARSING_REQUEST_BODY = "Unable to parse request body; '%s'.";

    /**
     * Error when a stream cannot be created from a temporary file.
     *
     * Format: "Failed to create stream from temporary file '%s'."
     */
    case FAILED_CREATE_STREAM_FROM_TMP_FILE = "Failed to create stream from temporary file '%s'.";

    /**
     * Error when the fallback request parser is invalid.
     *
     * Format: "Fallback request parser is invalid. It must implement the '%s'."
     */
    case INVALID_FALLBACK_REQUEST_PARSER = "Fallback request parser is invalid. It must implement the '%s'.";

    /**
     * Error when an optional array is invalid in multiple file specification.
     *
     * Format: "Invalid optional '%s' array in multiple file specification for '%s'. Expected 'array' or 'null'."
     */
    case INVALID_OPTIONAL_ARRAY_IN_MULTI_SPEC = "Invalid optional '%s' array in multiple file specification for '%s'. "
    . "Expected 'array' or 'null'.";

    /**
     * Error when a specific request parser is invalid.
     *
     * Format: "The '%s' request parser is invalid. It must implement the '%s'."
     */
    case INVALID_REQUEST_PARSER = "The '%s' request parser is invalid. It must implement the '%s'.";

    /**
     * Error when the maximum nesting depth for file uploads is exceeded.
     *
     * Format: "Maximum nesting depth exceeded for file uploads (limit: '%d')."
     */
    case MAXIMUM_NESTING_DEPTH_EXCEEDED = "Maximum nesting depth exceeded for file uploads (limit: '%d').";

    /**
     * Error when the array structure for errors does not match the expected format.
     *
     * Format: "Mismatched array structure for 'errors' at key '%s'."
     */
    case MISMATCHED_ARRAY_STRUCTURE_ERRORS = "Mismatched array structure for 'errors' at key '%s'.";

    /**
     * Error when the array structure for sizes does not match the expected format.
     *
     * Format: "Mismatched array structure for 'sizes' at key '%s'."
     */
    case MISMATCHED_ARRAY_STRUCTURE_SIZES = "Mismatched array structure for 'sizes' at key '%s'.";

    /**
     * Error when a required array is missing or invalid in multiple file specification.
     *
     * Format: "Missing or invalid '%s' array in multiple file specification for '%s'."
     */
    case MISSING_OR_INVALID_ARRAY_IN_MULTI_SPEC = "Missing or invalid '%s' array in multiple file specification for "
    . "'%s'.";

    /**
     * Error when a required key is missing in file specification.
     *
     * Format: "Missing required key '%s' in file specification for '%s'."
     */
    case MISSING_REQUIRED_KEY_IN_FILE_SPEC = "Missing required key '%s' in file specification for '%s'.";

    /**
     * Error when 'name' is not a string or null in file specification.
     *
     * Format: "'name' must be a string or null in file specification."
     */
    case NAME_MUST_BE_STRING_OR_NULL = "'name' must be a 'string' or 'null' in file specification.";

    /**
     * Error when the page is not found.
     *
     * Format: "Page not found."
     */
    case PAGE_NOT_FOUND = 'Page not found.';

    /**
     * Error when the PSR-7 request adapter is not set.
     *
     * Format: "PSR-7 request adapter is not set."
     */
    case PSR7_REQUEST_ADAPTER_NOT_SET = 'PSR-7 request adapter is not set.';

    /**
     * Error when the response stream is not in the expected format.
     *
     * Format: "Response stream must be an 'array' with exactly '3' elements: ['handle', 'begin', 'end']."
     */
    case RESPONSE_STREAM_FORMAT_INVALID = "Response stream must be an 'array' with exactly '3' elements: "
    . "['handle', 'begin', 'end'].";

    /**
     * Error when the response stream handle is invalid.
     *
     * Format: "Stream handle must be a valid resource."
     */
    case RESPONSE_STREAM_HANDLE_INVALID = "Stream handle must be a valid 'resource'.";

    /**
     * Error when the response stream range values are invalid.
     *
     * Format: "Response stream range values must be valid: ('begin' >= '0' and 'end' >= 'begin').
     * Received: (begin='%d', end='%d')."
     */
    case RESPONSE_STREAM_RANGE_INVALID = 'Response stream range values must be valid: '
    . "('begin' >= '0' and 'end' >= 'begin'). Received: (begin='%d', end='%d').";

    /**
     * Error when unable to read from the response stream.
     *
     * Format: "Failed to read from response stream."
     */
    case RESPONSE_STREAM_READ_ERROR = 'Failed to read from response stream.';

    /**
     * Error when 'size' is not an integer in file specification.
     *
     * Format: "'size' must be an 'integer' in file specification."
     */
    case SIZE_MUST_BE_INTEGER = "'size' must be an 'integer' in file specification.";

    /**
     * Error when 'tmp_name' is not a string in file specification.
     *
     * Format: "'tmp_name' must be a 'string' in file specification."
     */
    case TMP_NAME_MUST_BE_STRING = "'tmp_name' must be a 'string' in file specification.";

    /**
     * Error when 'type' is not a string or null in file specification.
     *
     * Format: "'type' must be a 'string' or 'null' in file specification."
     */
    case TYPE_MUST_BE_STRING_OR_NULL = "'type' must be a 'string' or 'null' in file specification.";

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
     * Usage example:
     * ```php
     * throw new BadRequestException(Message::BUFFER_LENGTH_INVALID->getMessage('buffer', 0));
     * ```
     *
     * @param int|string ...$argument Values to insert into the message template.
     *
     * @return string Formatted error message with interpolated arguments.
     */
    public function getMessage(int|string ...$argument): string
    {
        return sprintf($this->value, ...$argument);
    }
}
