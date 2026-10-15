<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use yii2\extensions\psrbridge\exception\Message;

use function is_array;
use function max;
use function min;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Read-only PSR-7 stream limited to a byte range of an underlying stream.
 *
 * Wraps any `StreamInterface` and exposes only bytes in the inclusive range `[$begin, $end]`, enforcing the upper
 * bound so consumers cannot read past `$end` even when calling `StreamInterface::getContents()` or repeated
 * `StreamInterface::read()` calls. Reads proxy to the underlying stream chunk by chunk without buffering the range
 * into memory or temporary storage.
 *
 * This is a bounded view, not a transparent decorator for the wrapped stream. The wrapped stream resource and its
 * original URI can represent bytes outside the declared range, so they are not exposed through {@see detach()} or
 * `getMetadata('uri')`.
 */
final class RangeStream implements StreamInterface
{
    /**
     * Default chunk size, in bytes, used by {@see getContents()} when reading the underlying stream.
     */
    private const READ_BUFFER_LENGTH = 8192;

    /**
     * Total number of bytes exposed by the range, derived from `$end - $begin + 1`.
     */
    private readonly int $length;

    /**
     * Wrapped underlying stream, or `null` after {@see close()} or {@see detach()}.
     */
    private StreamInterface|null $stream;

    /**
     * Creates a new instance of the {@see RangeStream} class.
     *
     * Rewinds the underlying stream to `$begin` only when it reports as seekable; non-seekable streams must already be
     * positioned at `$begin` by the caller.
     *
     * @param StreamInterface $stream Underlying stream to expose a bounded view of.
     * @param int $begin Inclusive byte offset where the range starts (`0`-based, absolute in the underlying stream).
     * @param int $end Inclusive byte offset where the range ends (absolute in the underlying stream).
     *
     * @throws RuntimeException when the range is invalid or initial positioning fails.
     */
    public function __construct(StreamInterface $stream, private readonly int $begin, int $end)
    {
        if ($begin < 0 || $end < $begin) {
            throw new RuntimeException(
                Message::RANGE_STREAM_INVALID->getMessage(),
            );
        }

        $this->length = $end - $begin + 1;
        $this->stream = $stream;

        if ($stream->isSeekable()) {
            $this->rewind();
        }
    }

    /**
     * Returns range contents as a `string`, rewinding seekable streams and returning an empty `string` on failure.
     *
     * On non-seekable streams, only the remaining range bytes can be returned.
     *
     * @return string Bytes within the range, or empty `string` when any error occurs.
     */
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Closes the underlying stream and releases the internal reference.
     */
    public function close(): void
    {
        $this->stream?->close();

        $this->stream = null;
    }

    /**
     * Closes the wrapped stream and releases the internal reference without exposing the underlying resource.
     *
     * The wrapped resource is not detached because it can contain bytes outside this bounded view. Returning that
     * resource would allow consumers to bypass the range limit. Consumers that need the body contents must read through
     * {@see read()} or {@see getContents()}.
     *
     * @return resource|null Always `null`; no detachable resource can safely represent the bounded view.
     */
    public function detach()
    {
        $this->stream?->close();

        $this->stream = null;

        return null;
    }

    /**
     * Indicates whether the read cursor has reached the end of the range or the underlying stream.
     *
     * @return bool `true` when no more bytes can be read within the range; `false` otherwise.
     */
    public function eof(): bool
    {
        if ($this->stream === null) {
            return true;
        }

        return $this->stream->eof() || $this->tell() >= $this->length;
    }

    /**
     * Reads every remaining byte within the range and returns them as a single `string`.
     *
     * Iterates {@see read()} in chunks of `READ_BUFFER_LENGTH` bytes until the range or the underlying stream
     * reaches EOF; no intermediate copy of the full range is held by the underlying stream.
     *
     * @return string Concatenation of every chunk read until the end of the range or underlying stream EOF.
     */
    public function getContents(): string
    {
        $contents = '';

        while ($this->eof() === false) {
            $chunk = $this->read(self::READ_BUFFER_LENGTH);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * Returns metadata associated with this bounded stream view.
     *
     * Resource locator metadata such as `uri` is omitted because the underlying URI identifies the unbounded wrapped
     * stream, not this range-limited view.
     *
     * @param string|null $key Specific metadata key, or `null` to return the full metadata array.
     *
     * @return mixed Metadata value for `$key`, the full metadata array when `$key` is `null`, or `null` when no stream
     * is available and `$key` is provided.
     */
    public function getMetadata(string|null $key = null): mixed
    {
        if ($this->stream === null) {
            return $key === null ? [] : null;
        }

        $metadata = $this->stream->getMetadata();

        if (is_array($metadata) === false) {
            return $key === null ? [] : null;
        }

        unset($metadata['uri']);

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * Returns the size of the range, not the size of the underlying stream.
     *
     * @return int|null Length of the range in bytes, or `null` after the underlying stream has been detached or closed.
     */
    public function getSize(): int|null
    {
        if ($this->stream === null) {
            return null;
        }

        return $this->length;
    }

    /**
     * Indicates whether the underlying stream reports as readable.
     *
     * @return bool `true` when the underlying stream is readable; `false` otherwise or when no stream is available.
     */
    public function isReadable(): bool
    {
        return $this->stream?->isReadable() ?? false;
    }

    /**
     * Indicates whether seeking within the range is supported.
     *
     * @return bool `true` when the underlying stream is seekable; `false` otherwise or when no stream is available.
     */
    public function isSeekable(): bool
    {
        return $this->stream?->isSeekable() ?? false;
    }

    /**
     * Indicates that the stream is read-only.
     *
     * @return bool Always `false`; range streams reject all writes.
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Reads up to `$length` bytes from the current position, capped at the bytes remaining in the range.
     *
     * @param int $length Maximum number of bytes to read.
     *
     * @throws RuntimeException when the length is negative, the stream is closed, or an underlying operation fails.
     *
     * @return string Bytes read, or empty `string` at the end of the range or when `$length` is `0`.
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException(
                Message::RANGE_STREAM_READ_LENGTH_INVALID->getMessage(),
            );
        }

        if ($length === 0 || $this->eof()) {
            return '';
        }

        return $this->stream()->read(min($length, $this->length - $this->tell()));
    }

    /**
     * Rewinds the read cursor to the start of the range.
     *
     * @throws RuntimeException when the stream is closed or seeking fails.
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Seeks to an offset relative to the range, clamped to `[0, $length]`.
     *
     * @param int $offset Byte offset to seek to, interpreted according to `$whence`.
     * @param int $whence One of `SEEK_SET` (absolute), `SEEK_CUR` (relative to current), `SEEK_END` (relative to end).
     *
     * @throws RuntimeException when the target/mode is invalid, the stream is closed, or seeking fails.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->tell() + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new RuntimeException(
                Message::RANGE_STREAM_SEEK_MODE_INVALID->getMessage(),
            ),
        };

        if ($target < 0) {
            throw new RuntimeException(
                Message::RANGE_STREAM_SEEK_POSITION_INVALID->getMessage(),
            );
        }

        $this->stream()->seek($this->begin + min($target, $this->length));
    }

    /**
     * Returns the current read position relative to the start of the range.
     *
     * @throws RuntimeException when the stream is closed or its position is unavailable.
     *
     * @return int Position in bytes within `[0, $length]`.
     */
    public function tell(): int
    {
        return max(0, min($this->stream()->tell() - $this->begin, $this->length));
    }

    /**
     * Rejects every write attempt because the stream is read-only.
     *
     * @param string $string Bytes the caller attempted to write (ignored).
     *
     * @throws RuntimeException always.
     *
     * @return int Never returns; included to satisfy the `StreamInterface` contract.
     */
    public function write(string $string): int
    {
        throw new RuntimeException(Message::RANGE_STREAM_NOT_WRITABLE->getMessage());
    }

    /**
     * Returns the wrapped stream, or throws when it has been closed or detached.
     *
     * @throws RuntimeException when no stream is available.
     *
     * @return StreamInterface wrapped stream guaranteed to be non-`null`.
     */
    private function stream(): StreamInterface
    {
        if ($this->stream === null) {
            throw new RuntimeException(
                Message::RANGE_STREAM_UNAVAILABLE->getMessage(),
            );
        }

        return $this->stream;
    }
}
