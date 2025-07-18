<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter;

/**
 * Enum for standardized units used in HTTP 'Content-Range' headers as defined in RFC 7233.
 *
 * Represents the set of valid units for the 'Content-Range' HTTP header providing type-safe, self-documenting handling
 * for partial content responses and range requests in web applications and HTTP servers.
 *
 * The enum is used by HTTP response emitters, range request handlers, and middleware to ensure correct and consistent
 * usage of the 'Content-Range' header, improving protocol compliance and code readability.
 *
 * Key features.
 * - Extensible for future HTTP unit types.
 * - RFC 7233-compliant unit definition for 'Content-Range' headers.
 * - Type-safe handling for HTTP range responses and emitters.
 * - Utility for response builders and middleware.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7233#section-4.2
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum ContentRangeUnit: string
{
    /**
     * Bytes unit used in `Content-Range` headers.
     */
    case BYTES = 'bytes';
}
