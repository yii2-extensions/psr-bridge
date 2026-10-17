<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\exception;

use function sprintf;

/**
 * Defines bridge exception message templates.
 *
 * Use {@see Message::getMessage()} to format the template with `sprintf()` arguments.
 */
enum Message: string
{
    /**
     * Error when buffer length is invalid.
     */
    case BUFFER_LENGTH_INVALID = "Buffer length for '%s' must be greater than zero; received '%d'.";

    /**
     * Error when the cookie validation key is not configured for a specific class.
     */
    case COOKIE_VALIDATION_KEY_NOT_CONFIGURED = "'%s::cookieValidationKey' must be configured with a secret key.";

    /**
     * Error when the cookie validation key is missing.
     */
    case COOKIE_VALIDATION_KEY_REQUIRED = 'Cookie validation key must be provided.';

    /**
     * Error when 'error' is not an integer in file specification.
     */
    case ERROR_MUST_BE_INTEGER = "'error' must be an 'integer' in file specification.";

    /**
     * Error when the request body can’t be parsed.
     */
    case FAIL_PARSING_REQUEST_BODY = "Unable to parse request body; '%s'.";

    /**
     * Error when a stream cannot be created from a temporary file.
     */
    case FAILED_CREATE_STREAM_FROM_TMP_FILE = "Failed to create stream from temporary file '%s'.";

    /**
     * Error when the fallback request parser is invalid.
     */
    case INVALID_FALLBACK_REQUEST_PARSER = "Fallback request parser is invalid. It must implement the '%s'.";

    /**
     * Error when an optional array is invalid in multiple file specification.
     */
    case INVALID_OPTIONAL_ARRAY_IN_MULTI_SPEC = "Invalid optional '%s' array in multiple file specification for '%s'. "
        . "Expected 'array' or 'null'.";

    /**
     * Error when a specific request parser is invalid.
     */
    case INVALID_REQUEST_PARSER = "The '%s' request parser is invalid. It must implement the '%s'.";

    /**
     * Error when the maximum nesting depth for file uploads is exceeded.
     */
    case MAXIMUM_NESTING_DEPTH_EXCEEDED = "Maximum nesting depth exceeded for file uploads (limit: '%d').";

    /**
     * Error when the array structure for errors does not match the expected format.
     */
    case MISMATCHED_ARRAY_STRUCTURE_ERRORS = "Mismatched array structure for 'errors' at key '%s'.";

    /**
     * Error when the array structure for sizes does not match the expected format.
     */
    case MISMATCHED_ARRAY_STRUCTURE_SIZES = "Mismatched array structure for 'sizes' at key '%s'.";

    /**
     * Error when a required array is missing or invalid in multiple file specification.
     */
    case MISSING_OR_INVALID_ARRAY_IN_MULTI_SPEC = "Missing or invalid '%s' array in multiple file specification for "
        . "'%s'.";

    /**
     * Error when a required key is missing in file specification.
     */
    case MISSING_REQUIRED_KEY_IN_FILE_SPEC = "Missing required key '%s' in file specification for '%s'.";

    /**
     * Error when 'name' is not a string or null in file specification.
     */
    case NAME_MUST_BE_STRING_OR_NULL = "'name' must be a 'string' or 'null' in file specification.";

    /**
     * Error when the page is not found.
     */
    case PAGE_NOT_FOUND = 'Page not found.';

    /**
     * Error when the PSR-7 request adapter is not set.
     */
    case PSR7_REQUEST_ADAPTER_NOT_SET = 'PSR-7 request adapter is not set.';

    /**
     * Error when the byte range has a negative start or ends before its start.
     */
    case RANGE_STREAM_INVALID = 'Invalid stream range.';

    /**
     * Error when writing to a read-only range stream.
     */
    case RANGE_STREAM_NOT_WRITABLE = 'Range streams are not writable.';

    /**
     * Error when a requested read length is negative.
     */
    case RANGE_STREAM_READ_LENGTH_INVALID = 'Cannot read a negative length from a stream.';

    /**
     * Error when the seek mode is unsupported.
     */
    case RANGE_STREAM_SEEK_MODE_INVALID = 'Invalid seek mode.';

    /**
     * Error when a seek target precedes the range.
     */
    case RANGE_STREAM_SEEK_POSITION_INVALID = 'Cannot seek before the beginning of the stream range.';

    /**
     * Error when an operation needs a stream that has been closed or detached.
     */
    case RANGE_STREAM_UNAVAILABLE = 'No stream available.';

    /**
     * Error when the response stream is not in the expected format.
     */
    case RESPONSE_STREAM_FORMAT_INVALID = "Response stream must be an 'array' with exactly '3' elements: "
        . "['handle', 'begin', 'end'].";

    /**
     * Error when the response stream handle is invalid.
     */
    case RESPONSE_STREAM_HANDLE_INVALID = "Stream handle must be a valid 'resource'.";

    /**
     * Error when the response stream range values are invalid.
     */
    case RESPONSE_STREAM_RANGE_INVALID = 'Response stream range values must be valid: '
        . "('begin' >= '0' and 'end' >= 'begin'). Received: (begin='%d', end='%d').";

    /**
     * Error when the response byte range length cannot be represented as an integer.
     */
    case RESPONSE_STREAM_RANGE_OVERFLOW = 'Response stream range length exceeds the maximum integer size.';

    /**
     * Error when unable to read from the response stream.
     */
    case RESPONSE_STREAM_READ_ERROR = 'Failed to read from response stream.';

    /**
     * Error when 'size' is not an integer in file specification.
     */
    case SIZE_MUST_BE_INTEGER = "'size' must be an 'integer' in file specification.";

    /**
     * Error when 'tmp_name' is not a string in file specification.
     */
    case TMP_NAME_MUST_BE_STRING = "'tmp_name' must be a 'string' in file specification.";

    /**
     * Error when 'type' is not a string or null in file specification.
     */
    case TYPE_MUST_BE_STRING_OR_NULL = "'type' must be a 'string' or 'null' in file specification.";

    /**
     * Error when output has already been emitted.
     */
    case UNABLE_TO_EMIT_OUTPUT_HAS_BEEN_EMITTED = 'Unable to emit response; output has been emitted previously.';

    /**
     * Error when headers have already been sent.
     */
    case UNABLE_TO_EMIT_RESPONSE_HEADERS_ALREADY_SENT = 'Unable to emit response; headers already sent.';

    /**
     * Returns the formatted message string for the error case.
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
