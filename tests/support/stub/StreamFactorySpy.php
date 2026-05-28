<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Psr\Http\Message\{StreamFactoryInterface, StreamInterface};

/**
 * Spy stream factory for verifying which PSR-17 stream creation method is used.
 */
final class StreamFactorySpy implements StreamFactoryInterface
{
    public bool $createdFromResource = false;

    public bool $createdFromString = false;

    public function __construct(private readonly StreamFactoryInterface $streamFactory) {}

    public function createStream(string $content = ''): StreamInterface
    {
        $this->createdFromString = true;

        return $this->streamFactory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->streamFactory->createStreamFromFile($filename, $mode);
    }

    /**
     * @param resource $resource Stream resource.
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        $this->createdFromResource = true;

        return $this->streamFactory->createStreamFromResource($resource);
    }
}
