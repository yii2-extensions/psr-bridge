<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use yii2\extensions\psrbridge\adapter\RangeStream;
use yii2\extensions\psrbridge\tests\support\{HelperFactory, TestCase};

use function fopen;
use function fseek;
use function fwrite;
use function is_resource;
use function str_repeat;

use const SEEK_CUR;
use const SEEK_END;

/**
 * Unit tests for the {@see RangeStream} PSR-7 range-bounded stream wrapper.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('adapter')]
final class RangeStreamTest extends TestCase
{
    public function testCloseClosesUnderlyingResource(): void
    {
        $resource = fopen('php://memory', 'r+');

        self::assertIsResource(
            $resource,
            'Setup: memory resource must be valid.',
        );

        fwrite($resource, 'test');
        fseek($resource, 0);

        $stream = HelperFactory::createStreamFactory()->createStreamFromResource($resource);
        $rangeStream = new RangeStream($stream, 0, 3);

        $rangeStream->close();

        self::assertFalse(
            is_resource($resource),
            "Underlying resource must be closed by 'close()'.",
        );
    }

    public function testCloseIsIdempotent(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();
        $rangeStream->close();

        self::assertNull(
            $rangeStream->getSize(),
            'Repeated close must remain a no-op without errors.',
        );
    }

    public function testCloseSetsUnderlyingStreamToNull(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertNull(
            $rangeStream->getSize(),
            "Size must be 'null' after close.",
        );
    }

    public function testConstructorSeeksUnderlyingStreamToBegin(): void
    {
        $stream = $this->stream('0123456789');

        // move underlying away from `$begin` to verify the constructor repositions it
        $stream->seek(7);

        new RangeStream($stream, 2, 5);

        self::assertSame(
            2,
            $stream->tell(),
            "Underlying stream must be repositioned to '2'.",
        );
    }

    public function testDetachReturnsNullAfterClose(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertNull(
            $rangeStream->detach(),
            "Detach after close must return 'null'.",
        );
    }

    public function testDetachReturnsUnderlyingResource(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $resource = $rangeStream->detach();

        self::assertIsResource(
            $resource,
            'Detach must return the underlying resource.',
        );
        self::assertNull(
            $rangeStream->getSize(),
            "Size must be 'null' after detach.",
        );
    }

    public function testEofIsTrueAfterClose(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertTrue(
            $rangeStream->eof(),
            'EOF: closed stream.',
        );
    }

    public function testEofIsTrueWhenAllRangeBytesRead(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        $rangeStream->read(4);

        self::assertTrue(
            $rangeStream->eof(),
            'EOF: range exhausted.',
        );
    }

    public function testEofIsTrueWhenRangeExhaustedBeforeUnderlyingEof(): void
    {
        // underlying has bytes past `$end` so the underlying stream stays away from EOF.
        $stream = $this->stream('0123456789');

        $rangeStream = new RangeStream($stream, 2, 5);

        $rangeStream->read(4);

        self::assertFalse(
            $stream->eof(),
            "Underlying must still have bytes past '\$end'.",
        );
        self::assertTrue(
            $rangeStream->eof(),
            'Range EOF must trigger independently of underlying EOF.',
        );
    }

    /**
     * @throws Exception if the mock object cannot be created.
     */
    public function testGetContentsBreaksWhenUnderlyingReturnsEmpty(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $stream->method('isSeekable')->willReturn(true);
        $stream->method('eof')->willReturn(false);
        $stream->method('tell')->willReturn(0);
        $stream->method('read')->willReturn('');

        $rangeStream = new RangeStream($stream, 0, 100);

        self::assertSame(
            '',
            $rangeStream->getContents(),
            'Break must short-circuit when underlying read yields empty.',
        );
    }

    public function testGetContentsConcatenatesMultipleChunks(): void
    {
        // `> READ_BUFFER_LENGTH` (8192) to force multiple chunk reads.
        $content = str_repeat('A', 20000);

        $rangeStream = new RangeStream($this->stream($content), 0, 19999);

        self::assertSame(
            $content,
            $rangeStream->getContents(),
            'All chunks must be concatenated.',
        );
    }

    public function testGetMetadataReturnsArrayWhenStreamIsOpen(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        self::assertIsArray(
            $rangeStream->getMetadata(),
            "Open stream metadata must be an 'array'.",
        );
    }

    public function testGetMetadataReturnsEmptyArrayWhenClosed(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertSame(
            [],
            $rangeStream->getMetadata(),
            'Metadata must be empty after close.',
        );
    }

    public function testGetMetadataReturnsNullForKeyWhenClosed(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertNull(
            $rangeStream->getMetadata('uri'),
            'Metadata key must yield `null` after close.',
        );
    }

    public function testGetSizeReturnsRangeLength(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        self::assertSame(
            4,
            $rangeStream->getSize(),
            'Size must be `$end - $begin + 1`.',
        );
    }

    public function testIsReadableReturnsFalseAfterClose(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertFalse(
            $rangeStream->isReadable(),
            'Closed stream must report not readable.',
        );
    }

    public function testIsReadableReturnsTrueForOpenReadableStream(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        self::assertTrue(
            $rangeStream->isReadable(),
            'Open readable stream must report readable.',
        );
    }

    public function testIsSeekableReturnsFalseAfterClose(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        self::assertFalse(
            $rangeStream->isSeekable(),
            'Closed stream must report not seekable.',
        );
    }

    public function testIsWritableReturnsFalse(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        self::assertFalse(
            $rangeStream->isWritable(),
            'Range streams must be read-only.',
        );
    }

    public function testReadCapsAtRemainingBytes(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        self::assertSame(
            '2345',
            $rangeStream->read(100),
            'Read must cap at remaining range bytes.',
        );
    }

    public function testReadFromMidRangeReturnsOnlyRemainingBytes(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        $rangeStream->seek(1);

        self::assertSame(
            '345',
            $rangeStream->read(100),
            'Read from mid-range must cap at remaining bytes.',
        );
    }

    public function testReadReturnsEmptyForZeroLength(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        self::assertSame(
            '',
            $rangeStream->read(0),
            "Zero-length read must short-circuit to empty 'string'.",
        );
    }

    public function testReadReturnsEmptyWhenAtEndOfRange(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        $rangeStream->read(4);

        self::assertSame(
            '',
            $rangeStream->read(10),
            "Read past range EOF must yield empty 'string'.",
        );
    }

    public function testSeekUpdatesUnderlyingStreamPosition(): void
    {
        $stream = $this->stream('0123456789');

        $rangeStream = new RangeStream($stream, 2, 5);

        $rangeStream->seek(2);

        self::assertSame(
            4,
            $stream->tell(),
            "Underlying position must equal '\$begin + \$offset'.",
        );
    }

    public function testSeekWithSeekCurAdvancesRelativeToCurrentPosition(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        $rangeStream->seek(1);
        $rangeStream->seek(2, SEEK_CUR);

        self::assertSame(
            3,
            $rangeStream->tell(),
            "'SEEK_CUR' must add the offset to the current position.",
        );
    }

    public function testSeekWithSeekEndPositionsRelativeToRangeEnd(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        $rangeStream->seek(-1, SEEK_END);

        self::assertSame(
            3,
            $rangeStream->tell(),
            "'SEEK_END' must position relative to 'length'.",
        );
    }

    public function testTellReturnsLengthWhenUnderlyingIsPastEnd(): void
    {
        $stream = $this->stream('0123456789');

        $rangeStream = new RangeStream($stream, 2, 5);

        $stream->seek(8);

        self::assertSame(
            4,
            $rangeStream->tell(),
            "Position must clamp to 'length' past '\$end'.",
        );
    }

    public function testTellReturnsZeroWhenUnderlyingIsBelowBegin(): void
    {
        $stream = $this->stream('0123456789');
        $rangeStream = new RangeStream($stream, 2, 5);

        $stream->seek(0);

        self::assertSame(
            0,
            $rangeStream->tell(),
            "Position must clamp to '0' below '\$begin'.",
        );
    }

    public function testThrowRuntimeExceptionForInvalidSeekMode(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Invalid seek mode.',
        );

        $rangeStream->seek(0, 99);
    }

    public function testThrowRuntimeExceptionForNegativeReadLength(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot read a negative length from a stream.',
        );

        $rangeStream->read(-1);
    }

    public function testThrowRuntimeExceptionWhenAccessingClosedStream(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $rangeStream->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'No stream available.',
        );

        $rangeStream->tell();
    }

    public function testThrowRuntimeExceptionWhenBeginIsNegative(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Invalid stream range.',
        );

        new RangeStream($this->stream('test'), -1, 5);
    }

    public function testThrowRuntimeExceptionWhenEndIsLessThanBegin(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Invalid stream range.',
        );

        new RangeStream($this->stream('test'), 5, 3);
    }

    public function testThrowRuntimeExceptionWhenSeekTargetIsNegative(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Cannot seek before the beginning of the stream range.',
        );

        $rangeStream->seek(-5);
    }

    public function testThrowRuntimeExceptionWhenWritingToRangeStream(): void
    {
        $rangeStream = new RangeStream($this->stream('test'), 0, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Range streams are not writable.',
        );

        $rangeStream->write('x');
    }

    /**
     * @throws Exception if the mock object cannot be created.
     */
    public function testToStringReturnsEmptyStringWhenUnderlyingThrows(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $stream->method('isSeekable')->willReturn(true);
        $stream->method('eof')->willReturn(false);
        $stream->method('tell')->willReturn(0);
        $stream->method('read')->willThrowException(new RuntimeException('Read failed.'));

        $rangeStream = new RangeStream($stream, 0, 3);

        self::assertSame(
            '',
            (string) $rangeStream,
            "String cast must yield empty 'string' when the underlying stream throws.",
        );
    }

    public function testToStringRewindsBeforeReadingFullRange(): void
    {
        $rangeStream = new RangeStream($this->stream('0123456789'), 2, 5);

        // advance the cursor before casting to `string` to verify rewind happens inside `__toString()`.
        $rangeStream->read(2);

        self::assertSame(
            '2345',
            (string) $rangeStream,
            'String cast must rewind and yield the full range.',
        );
    }

    private function stream(string $content): StreamInterface
    {
        return HelperFactory::createStreamFactory()->createStream($content);
    }
}
