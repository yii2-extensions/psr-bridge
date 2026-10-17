<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\{Group, TestWith};
use Psr\Http\Message\{ResponseInterface, StreamFactoryInterface, StreamInterface};
use Yii;
use yii\base\{InvalidConfigException, Security};
use yii\web\RangeNotSatisfiableHttpException;
use yii2\extensions\psrbridge\adapter\ResponseAdapter;
use yii2\extensions\psrbridge\emitter\SapiEmitter;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};
use yii2\extensions\psrbridge\tests\support\stub\MockerFunctions;

use function fclose;
use function fopen;
use function is_resource;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function str_repeat;
use function strlen;
use function substr;

use const PHP_INT_MAX;

/**
 * Regression tests for Yii download preparation, PSR-7 conversion, and SAPI emission.
 */
#[Group('adapter')]
#[Group('emitter')]
final class RangeResponseTest extends TestCase
{
    public function testAcceptMaximumRepresentableEndOffset(): void
    {
        $handle = fopen($this->createTempFileWithContent('0123456789abcdef'), 'rb');

        self::assertIsResource(
            $handle,
            'Temporary stream must be open.',
        );

        $response = new Response(['charset' => 'UTF-8']);
        $response->stream = [$handle, '0001', (string) PHP_INT_MAX];
        $psr = $this->adapt($response);

        try {
            self::assertSame(
                PHP_INT_MAX,
                $psr->getBody()->getSize(),
                'Body size must preserve the maximum end offset.',
            );
            self::assertSame(
                '123456789abcdef',
                $psr->getBody()->getContents(),
                'Body must start at the requested offset.',
            );
        } finally {
            $psr->getBody()->close();
        }
    }

    public function testEmitFileRangeInBoundedChunks(): void
    {
        ApplicationFactory::web();

        Yii::$app->request->headers->set('Range', 'bytes=17-32600');

        $contents = str_repeat('0123', 8192);
        $handle = fopen($this->createTempFileWithContent($contents), 'rb');

        self::assertIsResource(
            $handle,
            'Temporary stream must be open.',
        );

        $native = HelperFactory::createStreamFactory()->createStreamFromResource($handle);

        $stream = $this->createMock(StreamInterface::class);

        $stream
            ->method('isReadable')
            ->willReturn(true);
        $stream
            ->method('isSeekable')
            ->willReturn(true);
        $stream
            ->method('seek')
            ->willReturnCallback(
                static function (int $offset, int $whence) use ($native): void {
                    $native->seek($offset, $whence);
                }
            );
        $stream
            ->method('tell')->willReturnCallback($native->tell(...));
        $stream
            ->method('eof')
            ->willReturnCallback($native->eof(...));
        $stream
            ->expects(self::never())->method('getContents');
        $stream
            ->expects(self::never())->method('__toString');

        $reads = 0;

        $stream
            ->method('read')
            ->willReturnCallback(
                static function (int $length) use ($native, &$reads): string {
                    self::assertLessThanOrEqual(1024, $length, 'Each read must respect the emitter buffer.');
                    $reads++;

                    return $native->read($length);
                }
            );

        $factory = $this->createMock(StreamFactoryInterface::class);

        $factory
            ->expects(self::once())
            ->method('createStreamFromResource')
            ->with($handle)
            ->willReturn($stream);

        $response = new Response(['charset' => 'UTF-8']);

        $response->sendStreamAsFile($handle, 'fixture.bin');

        $psr = $this->adapt($response, $factory);

        try {
            self::assertSame(
                substr($contents, 17, 32584),
                $this->emit($psr, true, 1024),
            );
            self::assertSame(
                '32584',
                $psr->getHeaderLine('Content-Length'),
            );
            self::assertGreaterThan(
                1,
                $reads,
                'A large range must be emitted with multiple reads.',
            );
        } finally {
            $native->close();
        }
    }

    #[TestWith(['GET', null, '0123456789abcdef', 200, ''])]
    #[TestWith(['GET', 'bytes=3-8', '345678', 206, 'bytes 3-8/16'])]
    #[TestWith(['GET', 'bytes=3-', '3456789abcdef', 206, 'bytes 3-15/16'])]
    #[TestWith(['GET', 'bytes=-6', 'abcdef', 206, 'bytes 10-15/16'])]
    #[TestWith(['GET', 'bytes=0-15', '0123456789abcdef', 200, ''])]
    #[TestWith(['GET', 'bytes=003-008', '345678', 206, 'bytes 003-008/16'])]
    #[TestWith(['GET', 'bytes=000-008', '012345678', 206, 'bytes 000-008/16'])]
    #[TestWith(['GET', 'bytes=3-999999999999999999999999', '3456789abcdef', 206, 'bytes 3-15/16'])]
    #[TestWith(['HEAD', null, '0123456789abcdef', 200, ''])]
    #[TestWith(['HEAD', 'bytes=3-8', '345678', 206, 'bytes 3-8/16'])]
    public function testEmitPreparedDownload(
        string $method,
        string|null $range,
        string $expected,
        int $status,
        string $contentRange,
    ): void {
        ApplicationFactory::web();

        $_SERVER['REQUEST_METHOD'] = $method;

        if ($range !== null) {
            Yii::$app->request->headers->set('Range', $range);
        }

        foreach (['content', 'stream', 'file'] as $source) {
            MockerFunctions::reset();

            $response = new Response(['charset' => 'UTF-8']);

            if ($source === 'content') {
                $response->sendContentAsFile('0123456789abcdef', 'fixture.txt');
            } else {
                $path = $this->createTempFileWithContent('0123456789abcdef');

                if ($source === 'file') {
                    $response->sendFile($path, 'fixture.txt');
                } else {
                    $handle = fopen($path, 'rb');

                    self::assertIsResource(
                        $handle,
                        'Fixture stream must be readable.'
                    );

                    $response->sendStreamAsFile($handle, 'fixture.txt');
                }
            }

            $psr = $this->adapt($response);

            try {
                self::assertSame(
                    $status,
                    $psr->getStatusCode(),
                    'Download status must survive conversion.',
                );
                self::assertSame(
                    $contentRange,
                    $psr->getHeaderLine('Content-Range'),
                    'Range header must be preserved.',
                );
                self::assertSame(
                    (string) strlen($expected),
                    $psr->getHeaderLine('Content-Length'),
                    'Content length must match the selected bytes.',
                );
                self::assertSame(
                    $expected,
                    $psr->getBody()->getContents(),
                    'PSR body must contain only selected bytes.',
                );
                self::assertSame(
                    $method === 'HEAD' ? '' : $expected,
                    $this->emit($psr, $method !== 'HEAD'),
                    'Emission must preserve the prepared body without applying the range again.',
                );
                self::assertSame(
                    $status,
                    MockerFunctions::http_response_code(),
                    'Emitted status must match the prepared response.',
                );
            } finally {
                $psr->getBody()->close();
            }
        }
    }

    public function testEmitPreparedNonSeekablePartialBody(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $stream
            ->method('isReadable')
            ->willReturn(true);
        $stream
            ->method('isSeekable')
            ->willReturn(false);
        $stream
            ->method('eof')
            ->willReturn(false, false, false, true);
        $stream
            ->expects(self::exactly(3))
            ->method('read')->with(2)
            ->willReturn('34', '56', '78');
        $stream
            ->expects(self::never())
            ->method('seek');
        $stream
            ->expects(self::never())
            ->method('rewind');
        $stream
            ->expects(self::never())
            ->method('getContents');
        $stream
            ->expects(self::never())
            ->method('__toString');

        $response = HelperFactory::createResponse(206, ['Content-Range' => 'bytes 3-8/16'], $stream);

        self::assertSame(
            '345678',
            $this->emit($response),
            'Emission must preserve the non-seekable partial body.',
        );
    }

    #[TestWith([false, 8])]
    #[TestWith([3.0, 8])]
    #[TestWith([3, true])]
    #[TestWith(['3.0', 8])]
    #[TestWith(['3e0', 8])]
    #[TestWith([' 3', 8])]
    #[TestWith(['+3', 8])]
    #[TestWith(['-1', 8])]
    #[TestWith(['', 8])]
    #[TestWith(['3x', 8])]
    #[TestWith([[3], 8])]
    #[TestWith(['18446744073709551616', 8])]
    #[TestWith([3, '18446744073709551616'])]
    #[TestWith([null, 8])]
    public function testThrowExceptionForInvalidOffset(mixed $begin, mixed $end): void
    {
        $handle = fopen($this->createTempFileWithContent('0123456789abcdef'), 'rb');

        self::assertIsResource(
            $handle,
            'Fixture stream must be readable.',
        );

        $response = new Response(['charset' => 'UTF-8']);

        $response->stream = [$handle, $begin, $end];

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::RESPONSE_STREAM_FORMAT_INVALID->getMessage(),
        );

        try {
            $this->adapt($response);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function testThrowExceptionForRangeLengthOverflow(): void
    {
        $handle = fopen($this->createTempFileWithContent('fixture'), 'rb');

        self::assertIsResource(
            $handle,
            'Fixture stream must be readable.',
        );

        $response = new Response(['charset' => 'UTF-8']);

        $response->stream = [$handle, '0', (string) PHP_INT_MAX];

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(
            Message::RESPONSE_STREAM_RANGE_OVERFLOW->getMessage(),
        );

        try {
            $this->adapt($response);
        } finally {
            fclose($handle);
        }
    }

    #[TestWith(['bytes=16-'])]
    #[TestWith(['bytes=8-3'])]
    #[TestWith(['bytes=999999999999999999999999-'])]
    #[TestWith(['bytes=3.0-8'])]
    #[TestWith(['bytes=3e0-8'])]
    public function testThrowExceptionForUnsatisfiableHttpRange(string $range): void
    {
        ApplicationFactory::web();

        Yii::$app->request->headers->set('Range', $range);

        $response = new Response(['charset' => 'UTF-8']);

        $this->expectException(RangeNotSatisfiableHttpException::class);

        $response->sendContentAsFile('0123456789abcdef', 'fixture.txt');
    }

    private function adapt(Response $response, StreamFactoryInterface|null $factory = null): ResponseInterface
    {
        return (new ResponseAdapter(
            $response,
            HelperFactory::createResponseFactory(),
            $factory ?? HelperFactory::createStreamFactory(),
            new Security(),
        ))->toPsr7();
    }

    private function emit(ResponseInterface $response, bool $body = true, int $buffer = 2): string
    {
        ob_start();

        try {
            (new SapiEmitter($buffer))->emit($response, $body);
            $output = ob_get_contents();

            self::assertIsString(
                $output,
                'Emitter output must be a string.',
            );

            return $output;
        } finally {
            ob_end_clean();
        }
    }
}
