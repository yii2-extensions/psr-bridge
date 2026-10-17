<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use HttpSoft\Message\{ServerRequest, StreamFactory, UploadedFileFactory};
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunTestsInSeparateProcesses, TestWith};
use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\creator\UploadedFileCreator;
use yii2\extensions\psrbridge\http\{Request, UploadedFile};
use yii2\extensions\psrbridge\tests\support\TestCase;

/**
 * Checks failed-upload metadata and native/PSR resource conversion boundaries.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UploadBoundaryTest extends TestCase
{
    public function testFailedUploadRetainsClientMetadataWithoutAccessingItsStream(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);

        $file
            ->method('getError')
            ->willReturn(UPLOAD_ERR_CANT_WRITE);
        $file
            ->method('getClientFilename')
            ->willReturn('failed.txt');
        $file
            ->method('getClientMediaType')
            ->willReturn('application/test');
        $file
            ->method('getSize')
            ->willReturn(null);
        $file
            ->expects(self::never())
            ->method('getStream');

        $request = new Request();

        $request->setPsr7Request(new ServerRequest(uploadedFiles: ['upload' => $file]));

        $converted = $request->getUploadedFiles()['upload'] ?? null;

        self::assertInstanceOf(
            \yii\web\UploadedFile::class,
            $converted,
            'Failed upload must remain a Yii uploaded file.',
        );
        self::assertSame(
            'failed.txt',
            $converted->name,
            'Client filename must be preserved.',
        );
        self::assertSame(
            'application/test',
            $converted->type,
            'Client media type must be preserved.',
        );
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testLegacyUploadsPreserveFullPathsAndAvailableResources(bool $multiple): void
    {
        $resource = fopen('php://temp', 'w+');

        self::assertIsResource(
            $resource,
            'Temporary upload resource must be open.',
        );

        fwrite($resource, 'payload');
        rewind($resource);

        $fallback = $this->createTempFileWithContent('fallback');

        $_FILES = [
            'upload' => $multiple
            ? [
                'name' => [
                    'a' => 'first.txt',
                    'b' => 'second.txt',
                ],
                'tmp_name' => [
                    'a' => '',
                    'b' => $fallback,
                ],
                'type' => [
                    'a' => 'text/plain',
                    'b' => 'text/plain',
                ],
                'size' => [
                    'a' => 7,
                    'b' => 8,
                ],
                'error' => [
                    'a' => UPLOAD_ERR_OK,
                    'b' => UPLOAD_ERR_OK,
                ],
                'full_path' => [
                    'a' => 'folder/first.txt',
                    'b' => 'folder/second.txt',
                ],
                'tmp_resource' => [
                    'a' => $resource,
                ],
            ]
            : [
                'name' => 'first.txt',
                'tmp_name' => '',
                'type' => 'text/plain',
                'size' => 7,
                'error' => UPLOAD_ERR_OK,
                'full_path' => 'folder/first.txt',
                'tmp_resource' => $resource,
            ],
        ];

        $converted = UploadedFile::getInstanceByName($multiple ? 'upload[a]' : 'upload');

        self::assertInstanceOf(
            UploadedFile::class,
            $converted,
            'Native upload must convert to a Yii uploaded file.',
        );
        self::assertSame(
            'folder/first.txt',
            $converted->fullPath,
            'First upload full path must be preserved.',
        );

        $destination = "{$fallback}.saved";

        try {
            self::assertTrue(
                $converted->saveAs($destination, false),
                'Upload resource must save successfully.',
            );
            self::assertSame(
                'payload',
                file_get_contents($destination),
                'Saved file must contain the upload payload.',
            );

            if ($multiple) {
                $second = UploadedFile::getInstanceByName('upload[b]');

                self::assertInstanceOf(
                    UploadedFile::class,
                    $second,
                    'Second native upload must convert to a Yii uploaded file.',
                );
                self::assertSame(
                    'folder/second.txt',
                    $second->fullPath,
                    'Second upload full path must be preserved.',
                );
            }
        } finally {
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }
    public function testNestedNativeMimeMetadataReachesThePsrFile(): void
    {
        $path = $this->createTempFileWithContent('value');

        $files = [
            'upload' => [
                'tmp_name' => ['group' => ['leaf' => $path]],
                'size' => ['group' => ['leaf' => 5]],
                'error' => ['group' => ['leaf' => UPLOAD_ERR_OK]],
                'name' => ['group' => ['leaf' => 'leaf.txt']],
                'type' => ['group' => ['leaf' => 'text/plain']],
            ],
        ];

        $tree = (new UploadedFileCreator(new UploadedFileFactory(), new StreamFactory()))->createFromGlobals($files);

        $top = $tree['upload'] ?? null;

        self::assertIsArray(
            $top,
            'Top-level upload entry must remain an array.',
        );

        $group = $top['group'] ?? null;

        self::assertIsArray(
            $group,
            'Nested upload group must remain an array.',
        );

        $file = $group['leaf'] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $file,
            'Nested native upload must convert to a PSR uploaded file.',
        );
        self::assertSame(
            'text/plain',
            $file->getClientMediaType(),
            'Nested upload media type must be preserved.',
        );
    }

    public function testUnexpectedNestedPsrEntriesAreIgnored(): void
    {
        $file = self::createStub(UploadedFileInterface::class);

        $file
            ->method('getError')
            ->willReturn(UPLOAD_ERR_CANT_WRITE);

        $psr = self::createStub(ServerRequestInterface::class);

        $psr
            ->method('getParsedBody')
            ->willReturn([]);
        $psr
            ->method('getUploadedFiles')
            ->willReturn(['upload' => ['bad' => 'unexpected', 'good' => $file]]);

        UploadedFile::setPsr7Adapter(new ServerRequestAdapter($psr));

        self::assertInstanceOf(
            UploadedFile::class,
            UploadedFile::getInstanceByName('upload[good]'),
            'Valid nested PSR upload must be returned.',
        );
        self::assertNull(
            UploadedFile::getInstanceByName('upload[bad]'),
            'Unexpected nested entry must be ignored.',
        );
    }

    #[TestWith([UPLOAD_ERR_OK])]
    #[TestWith([UPLOAD_ERR_CANT_WRITE])]
    public function testUnknownPsrFileSizeIsNormalizedToZero(int $error): void
    {
        $file = $this->createMock(UploadedFileInterface::class);

        $file
            ->method('getError')
            ->willReturn($error);
        $file
            ->method('getSize')
            ->willReturn(null);
        $file
            ->method('getStream')
            ->willReturn((new StreamFactory())->createStream('value'));

        UploadedFile::setPsr7Adapter(new ServerRequestAdapter(new ServerRequest(uploadedFiles: ['upload' => $file])));

        $converted = UploadedFile::getInstanceByName('upload');

        self::assertInstanceOf(
            UploadedFile::class,
            $converted,
            'PSR upload must convert to a Yii uploaded file.',
        );
        self::assertSame(
            0,
            $converted->size,
            'Unknown upload size must normalize to `0`.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        UploadedFile::reset();
    }

    protected function tearDown(): void
    {
        UploadedFile::reset();
        parent::tearDown();
    }
}
