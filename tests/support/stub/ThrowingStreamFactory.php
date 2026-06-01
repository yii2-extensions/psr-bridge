<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Psr\Http\Message\{StreamFactoryInterface, StreamInterface};
use RuntimeException;

/**
 * Stub stream factory that throws {@see RuntimeException} on every creation method.
 *
 * Used to drive failure paths in {@see \yii2\extensions\psrbridge\adapter\ResponseAdapter} where the PSR-17 stream
 * factory cannot create the response body stream.
 */
final class ThrowingStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        throw new RuntimeException('Stream creation failed.');
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new RuntimeException('Stream creation failed.');
    }

    /**
     * @param resource $resource Stream resource.
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        throw new RuntimeException('Stream creation failed.');
    }
}
