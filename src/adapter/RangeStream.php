<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\adapter;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function max;
use function min;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * Read-only PSR-7 stream limited to a byte range of an underlying stream.
 *
 * Wraps any {@see StreamInterface} and exposes only bytes in the inclusive range `[$begin, $end]`, enforcing the upper
 * bound so consumers cannot read past `$end` even when calling {@see StreamInterface::getContents()} or repeated
 * {@see StreamInterface::read()} calls. Reads proxy to the underlying stream chunk by chunk without buffering the range
 * into memory or temporary storage.
 *
 * Usage example:
 * ```php
 * $handle = fopen('/path/to/file.bin', 'rb');
 * fseek($handle, $begin);
 * $body = $streamFactory->createStreamFromResource($handle);
 * $rangeStream = new \yii2\extensions\psrbridge\adapter\RangeStream($body, $begin, $end);
 * ```
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
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
     * @throws RuntimeException if `$begin` is negative or `$end` is less than `$begin`.
     */
    public function __construct(StreamInterface $stream, private readonly int $begin, int $end)
    {
        if ($begin < 0 || $end < $begin) {
            throw new RuntimeException('Invalid stream range.');
        }

        $this->length = $end - $begin + 1;
        $this->stream = $stream;

        if ($stream->isSeekable()) {
            $this->rewind();
        }
    }

    /**
     * Rewinds and reads the entire range as a string, returning an empty `string` on failure.
     *
     * Usage example:
     * ```php
     * echo (string) $rangeStream;
     * ```
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
     *
     * Usage example:
     * ```php
     * $rangeStream->close();
     * ```
     */
    public function close(): void
    {
        $this->stream?->close();
        $this->stream = null;
    }

    /**
     * Detaches the underlying resource from the wrapped stream and releases the internal reference.
     *
     * Usage example:
     * ```php
     * $resource = $rangeStream->detach();
     * ```
     *
     * @return resource|null Detached resource handle, or `null` when no stream is available.
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
     * Usage example:
     * ```php
     * while (!$rangeStream->eof()) {
     *     // read from the stream until the end of the range or underlying stream is reached.
     * }
     * ```
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
     * Iterates {@see read()} in chunks of {@see READ_BUFFER_LENGTH} bytes until the range or the underlying stream
     * reaches EOF; no intermediate copy of the full range is held by the underlying stream.
     *
     * Usage example:
     * ```php
     * $contents = $rangeStream->getContents();
     * ```
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
     * Returns metadata associated with the underlying stream.
     *
     * Usage example:
     * ```php
     * $meta = $rangeStream->getMetadata('uri');
     * ```
     *
     * @param string|null $key Specific metadata key, or `null` to return the full metadata array.
     *
     * @return mixed Metadata value for `$key`, the full array when `$key` is `null`, or `null` when no stream is
     * available and `$key` is provided.
     */
    public function getMetadata(string|null $key = null)
    {
        if ($this->stream === null) {
            return $key === null ? [] : null;
        }

        $metadata = $this->stream->getMetadata();
        unset($metadata['uri']);

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }

    /**
     * Returns the size of the range, not the size of the underlying stream.
     *
     * Usage example:
     * ```php
     * $size = $rangeStream->getSize();
     * ```
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
     * Usage example:
     * ```php
     * if ($rangeStream->isReadable()) {
     *     // underlying stream is readable, so we can read from the range stream.
     * }
     * ```
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
     * Usage example:
     * ```php
     * if ($rangeStream->isSeekable()) {
     *     // underlying stream is seekable, so we can seek within the range stream.
     * }
     * ```
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
     * Usage example:
     * ```php
     * if (!$rangeStream->isWritable()) {
     *     // range stream is not writable, so we cannot write to it.
     * }
     * ```
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
     * Usage example:
     * ```php
     * $data = $rangeStream->read(1024); // read up to '1024' bytes from the range stream.
     * ```
     *
     * @param int $length Maximum number of bytes to read.
     *
     * @throws RuntimeException if `$length` is negative.
     *
     * @return string Bytes read, or empty `string` at the end of the range or when `$length` is `0`.
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Cannot read a negative length from a stream.');
        }

        if ($length === 0 || $this->eof()) {
            return '';
        }

        return $this->stream()->read(min($length, $this->length - $this->tell()));
    }

    /**
     * Rewinds the read cursor to the start of the range.
     *
     * Usage example:
     * ```php
     * $rangeStream->rewind(); // move the read cursor back to the start of the range stream.
     * ```
     *
     * @throws RuntimeException if the underlying stream is not seekable.
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Seeks to an offset relative to the range, clamped to `[0, $length]`.
     *
     * Usage example:
     * ```php
     * $rangeStream->seek(100); // move the read cursor to byte offset '100'.
     * $rangeStream->seek(-50, SEEK_CUR); // move the read cursor back by '50' bytes from the current position.
     * $rangeStream->seek(-10, SEEK_END); // move the read cursor to '10' bytes before the end of the range.
     * ```
     *
     * @param int $offset Byte offset to seek to, interpreted according to `$whence`.
     * @param int $whence One of `SEEK_SET` (absolute), `SEEK_CUR` (relative to current), `SEEK_END` (relative to end).
     *
     * @throws RuntimeException if `$whence` is invalid or the resulting target is negative.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->tell() + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new RuntimeException('Invalid seek mode.'),
        };

        if ($target < 0) {
            throw new RuntimeException('Cannot seek before the beginning of the stream range.');
        }

        $this->stream()->seek($this->begin + min($target, $this->length));
    }

    /**
     * Returns the current read position relative to the start of the range.
     *
     * Usage example:
     * ```php
     * $position = $rangeStream->tell(); // get the current read position within the range stream.
     * ```
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
     * Usage example:
     * ```php
     * try {
     *     $rangeStream->write('data'); // attempt to write to the range stream.
     * } catch (RuntimeException $e) {
     *     // handle the error when trying to write to a read-only stream.
     * }
     * ```
     *
     * @param string $string Bytes the caller attempted to write (ignored).
     *
     * @throws RuntimeException Always.
     *
     * @return int Never returns; included to satisfy the {@see StreamInterface} contract.
     */
    public function write(string $string): int
    {
        throw new RuntimeException('Range streams are not writable.');
    }

    /**
     * Returns the wrapped stream, or throws when it has been closed or detached.
     *
     * @throws RuntimeException when no stream is available.
     *
     * @return StreamInterface Wrapped stream guaranteed to be non-`null`.
     */
    private function stream(): StreamInterface
    {
        if ($this->stream === null) {
            throw new RuntimeException('No stream available.');
        }

        return $this->stream;
    }
}
