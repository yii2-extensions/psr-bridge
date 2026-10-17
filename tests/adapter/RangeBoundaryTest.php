<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use HttpSoft\Message\{ResponseFactory, Stream, StreamFactory};
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use yii\base\{InvalidConfigException, Security};
use yii2\extensions\psrbridge\adapter\{RangeStream, ResponseAdapter};
use yii2\extensions\psrbridge\http\Response;
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Verifies empty, large-offset and repeated pipe reads without buffering large data.
 */
final class RangeBoundaryTest extends TestCase
{
    public function testNonseekableWindowRejectsNonzeroStart(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'echo "abc";'], [1 => ['pipe', 'w']], $pipes);

        self::assertIsResource(
            $process,
            'Process must be open.',
        );

        $pipe = $pipes[1] ?? throw new RuntimeException('Missing pipe.');
        $response = new Response(['charset' => 'UTF-8', 'stream' => [$pipe, 1, 2]]);

        try {
            $this->expectException(InvalidConfigException::class);

            (new ResponseAdapter($response, new ResponseFactory(), new StreamFactory(), new Security()))->toPsr7();
        } finally {
            if (is_resource($pipe)) {
                fclose($pipe);
            }

            proc_close($process);
        }
    }

    public function testRepeatedPipeReadsAdvanceTheRelativePosition(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'echo "abcdefEXTRA";'], [1 => ['pipe', 'w']], $pipes);

        self::assertIsResource(
            $process,
            'Process must be open.',
        );

        $range = new RangeStream(new Stream($pipes[1] ?? throw new \RuntimeException('Missing pipe.')), 0, 5);
        try {
            self::assertFalse(
                $range->isSeekable(),
                'Pipe-backed stream must remain non-seekable.',
            );
            self::assertSame(
                'ab',
                $range->read(2),
                'First chunk must match the requested range.',
            );
            self::assertSame(
                2,
                $range->tell(),
                'Position must track bytes read.',
            );
            self::assertSame(
                'cd',
                $range->read(2),
                'Second chunk must follow the first.',
            );
            self::assertSame(
                4,
                $range->tell(),
                'Position must advance after the second read.',
            );
            self::assertSame(
                'ef',
                $range->read(8),
                'Read must stop at the range boundary.',
            );
            self::assertSame(
                6,
                $range->tell(),
                'Position must stop at the range boundary.',
            );
            self::assertTrue(
                $range->eof(),
                'Range must report EOF at its boundary.',
            );
            self::assertSame(
                '',
                $range->read(1),
                'Reads after the range boundary must be empty.',
            );
        } finally {
            $range->close();

            proc_close($process);
        }
    }

    #[TestWith([0, -1, 0])]
    #[TestWith([0, 0, 1])]
    #[TestWith([1, PHP_INT_MAX - 1, PHP_INT_MAX - 1])]
    public function testValidWindowSizeDoesNotDependOnTheSumOfOffsets(int $begin, int $end, int $expected): void
    {
        $handle = fopen('php://temp', 'w+');

        self::assertIsResource(
            $handle,
            'Temporary stream must be open.',
        );

        fwrite($handle, 'ab');

        $response = new Response(['charset' => 'UTF-8', 'stream' => [$handle, $begin, $end]]);
        $body = (new ResponseAdapter($response, new ResponseFactory(), new StreamFactory(), new Security()))
            ->toPsr7()
            ->getBody();

        self::assertSame(
            $expected,
            $body->getSize(),
            'Window size must match the expected boundary.',
        );

        $body->close();
    }
}
