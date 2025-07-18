<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter;

use function preg_match;

/**
 * 'Content-Range' header value representation and parser for HTTP partial content responses.
 *
 * Provides parsing, validation, and string conversion for the HTTP 'Content-Range' header, which is used to indicate
 * the byte range of a partial response ('HTTP 206').
 *
 * Supports the standard format.
 * - Content-Range: '<unit> <first>-<last>/<length>'
 *
 * It is designed for use in HTTP emitters, middleware, and response handlers that need to process or generate partial
 * content responses.
 *
 * Key features:
 * - Integration with {@see ContentRangeUnit} for unit safety.
 * - Strict parsing and validation of Content-Range header values.
 * - String conversion for header emission.
 * - Support for unknown resource length ('*').
 * - Type-safe access to unit, range, and length properties.
 *
 * @see ContentRangeUnit for enum of valid content range units.
 * @link https://tools.ietf.org/html/rfc7233#section-4.2 RFC 7233, Section 4.2
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ContentRange
{
    /**
     * Creates a new instance of the {@see ContentRange} class.
     *
     * @param ContentRangeUnit $unit Unit of measurement (for example, {@see ContentRangeUnit::BYTES}).
     * @param int $first First byte position in the range.
     * @param int $last Last byte position in the range.
     * @param int|string $length Total length of the resource ('*' for unknown length).
     */
    public function __construct(
        public ContentRangeUnit $unit,
        public int $first,
        public int $last,
        public int|string $length,
    ) {
    }

    /**
     * Convert the 'Content-Range' to its string representation.
     *
     * @return string String representation in format '<unit> <first>-<last>/<length>'.
     */
    public function __toString(): string
    {
        return "{$this->unit->value} {$this->first}-{$this->last}/{$this->length}";
    }

    /**
     * Creates a new {@see ContentRange} instance from a 'Content-Range' header string.
     *
     * Parses a 'Content-Range' header value in the format.
     * '<unit> <first>-<last>/<length>' (for example, "bytes 0-1233/1234" or "bytes 42-1233/*").
     *
     * This method validates the header structure, ensures the unit is supported, and checks that the first byte is not
     * greater than the last byte.
     *
     * @param string $header 'Content-Range' header value to parse.
     *
     * @return self|null ContentRange instance if parsing succeeds, `null` if the header is invalid or unsupported or
     * the range is inconsistent.
     *
     * Usage example:
     * ```php
     * $range = ContentRange::fromHeader('bytes 0-499/1234');
     *
     * if ($range !== null) {
     *     // your code here
     * }
     * ```
     */
    public static function fromHeader(string $header): self|null
    {
        $headerParts = preg_match(
            '/(?P<unit>\w+)\s+(?P<first>\d+)-(?P<last>\d+)\/(?P<length>\d+|\*)/',
            $header,
            $matches,
        );

        if ($headerParts !== false && $headerParts > 0) {
            $first = (int) $matches['first'];
            $last = (int) $matches['last'];

            if ($first > $last) {
                return null;
            }

            if ($matches['unit'] !== ContentRangeUnit::BYTES->value) {
                return null;
            }

            $length = $matches['length'] === '*' ? '*' : (int) $matches['length'];

            return new self(ContentRangeUnit::from($matches['unit']), $first, $last, $length);
        }

        return null;
    }
}
