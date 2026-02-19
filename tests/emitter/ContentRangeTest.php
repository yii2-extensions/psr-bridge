<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\emitter;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii2\extensions\psrbridge\emitter\{ContentRange, ContentRangeUnit};

/**
 * Test suite for {@see ContentRange} class functionality and behavior.
 *
 * Test coverage.
 * - Format validation (invalid headers, units, and ranges).
 * - Header parsing (standard, asterisk, and whitespace variations).
 * - Range validation (equal, invalid, and numeric ranges).
 * - String conversion (to header format).
 * - Unit handling (ContentRangeUnit enum integration).
 *
 * @see ContentRangeUnit for enum of valid content range units.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('emitter')]
final class ContentRangeTest extends TestCase
{
    public function testFromHeaderWithAsteriskLength(): void
    {
        $range = ContentRange::fromHeader('bytes 0-100/*');

        self::assertInstanceOf(
            ContentRange::class,
            $range,
            "'ContentRange::fromHeader()' should return an instance for valid asterisk length.",
        );
        self::assertSame(
            ContentRangeUnit::BYTES,
            $range->unit,
            "ContentRange unit should be BYTES when parsing 'bytes 0-100/*'.",
        );
        self::assertSame(
            0,
            $range->first,
            "ContentRange first should be 0 for 'bytes 0-100/*'.",
        );
        self::assertSame(
            100,
            $range->last,
            "ContentRange last should be 100 for 'bytes 0-100/*'.",
        );
        self::assertSame(
            '*',
            $range->length,
            'ContentRange length should be "*" for unknown resource length.',
        );
    }

    public function testFromHeaderWithEqualRange(): void
    {
        $range = ContentRange::fromHeader('bytes 5-5/10');

        self::assertNotNull(
            $range,
            "'ContentRange::fromHeader()' should return a range for equal first and last.",
        );
        self::assertSame(
            5,
            $range->first,
            "ContentRange first should be 5 for 'bytes 5-5/10'.",
        );
        self::assertSame(
            5,
            $range->last,
            "ContentRange last should be 5 for 'bytes 5-5/10'.",
        );
        self::assertSame(
            10,
            $range->length,
            "ContentRange length should be 10 for 'bytes 5-5/10'.",
        );
    }

    public function testFromHeaderWithInvalidFormat(): void
    {
        self::assertNull(
            ContentRange::fromHeader('invalid'),
            "'ContentRange::fromHeader()' should return null for completely invalid format.",
        );
        self::assertNull(
            ContentRange::fromHeader('bytes-0-100/500'),
            "'ContentRange::fromHeader()' should return null for missing space in header.",
        );
        self::assertNull(
            ContentRange::fromHeader('bytes 0-abc/500'),
            "'ContentRange::fromHeader()' should return null for non-numeric range values.",
        );
    }

    public function testFromHeaderWithInvalidRange(): void
    {
        self::assertNull(
            ContentRange::fromHeader('bytes 100-0/500'),
            "'ContentRange::fromHeader()' should return null when first > last.",
        );
    }

    public function testFromHeaderWithInvalidUnit(): void
    {
        self::assertNull(
            ContentRange::fromHeader('invalid 0-100/500'),
            "'ContentRange::fromHeader()' should return null for unsupported unit.",
        );
    }

    public function testFromHeaderWithNumericLength(): void
    {
        $range = ContentRange::fromHeader('bytes 0-100/500');

        self::assertInstanceOf(
            ContentRange::class,
            $range,
            "'ContentRange::fromHeader()' should return an instance for valid numeric length.",
        );
        self::assertSame(
            ContentRangeUnit::BYTES,
            $range->unit,
            'ContentRange unit should be BYTES for numeric length.',
        );
        self::assertSame(
            0,
            $range->first,
            "ContentRange first should be 0 for 'bytes 0-100/500'.",
        );
        self::assertSame(
            100,
            $range->last,
            "ContentRange last should be 100 for 'bytes 0-100/500'.",
        );
        self::assertSame(
            500,
            $range->length,
            "ContentRange length should be 500 for 'bytes 0-100/500'.",
        );
    }

    public function testFromHeaderWithWhitespaceVariations(): void
    {
        $range = ContentRange::fromHeader('bytes  0-100/500');

        self::assertNotNull(
            $range,
            "'ContentRange::fromHeader()' should handle extra whitespace in header.",
        );
        self::assertSame(
            0,
            $range->first,
            'ContentRange first should be 0 even with extra whitespace.',
        );
        self::assertSame(
            100,
            $range->last,
            'ContentRange last should be 100 even with extra whitespace.',
        );
    }

    public function testToString(): void
    {
        $range = new ContentRange(ContentRangeUnit::BYTES, 0, 100, 500);

        self::assertSame(
            'bytes 0-100/500',
            (string) $range,
            "'ContentRange::__toString()' should return the correct header format.",
        );
    }
}
