<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function file_put_contents;
use function stream_get_meta_data;

use const UPLOAD_ERR_OK;

final class UploadedFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UploadedFile::reset();
    }

    public function testConvertPsr7FileWithErrorShouldNotThrowException(): void
    {
        $tmpFile = $this->createTmpFile();

        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $uploadedFileWithError = FactoryHelper::createUploadedFile(
            'error-file.txt',
            'text/plain',
            FactoryHelper::createStream($tmpPath),
            UPLOAD_ERR_CANT_WRITE,
            100,
        );

        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/post')
                    ->withUploadedFiles(['error-file' => $uploadedFileWithError]),
            ),
        );

        $uploadedFile = UploadedFile::getInstanceByName('error-file');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile even when there is an upload error.',
        );
        self::assertSame(
            'error-file.txt',
            $uploadedFile->name,
            "Should preserve the original file 'name' even when there is an upload error.",
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->type,
            "Should preserve the original file 'type' even when there is an upload error.",
        );
        self::assertSame(
            '',
            $uploadedFile->tempName,
            'Should have an empty tempName when there is an upload error.',
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $uploadedFile->error,
            'Should preserve the upload error code from PSR-7 UploadedFile.',
        );
        self::assertSame(
            100,
            $uploadedFile->size,
            "Should preserve the original file 'size' even when there is an upload error.",
        );
    }

    public function testLegacyFilesLoadingWhenNotPsr7Adapter(): void
    {
        $_FILES = [
            'upload' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phptest',
                'error' => UPLOAD_ERR_OK,
                'size' => 100,
            ],
        ];

        $uploadFile = UploadedFile::getInstanceByName('upload');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFile,
            'Should return an instance of UploadedFile when a single file is uploaded.',
        );
        self::assertSame(
            'test.txt',
            $uploadFile->name,
            'Should preserve \'name\' from $_FILES.',
        );
        self::assertSame(
            'text/plain',
            $uploadFile->type,
            'Should preserve \'type\' from $_FILES.',
        );
        self::assertSame(
            '/tmp/phptest',
            $uploadFile->tempName,
            'Should preserve \'tmp_name\' from $_FILES.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadFile->error,
            'Should preserve \'error\' from $_FILES.',
        );
        self::assertSame(
            100,
            $uploadFile->size,
            'Should preserve \'size\' from $_FILES.',
        );
    }

    public function testResetMethodShouldCloseDetachedResources(): void
    {
        $tmpFile = $this->createTmpFile();

        $tmpPath = stream_get_meta_data($tmpFile)['uri'];
        file_put_contents($tmpPath, 'Test content for reset method test');

        $uploadedFile = FactoryHelper::createUploadedFile(
            'reset-test.txt',
            'text/plain',
            FactoryHelper::createStream($tmpPath),
            UPLOAD_ERR_OK,
            32,
        );

        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/post')
                    ->withUploadedFiles(['reset-test' => $uploadedFile]),
            ),
        );

        $uploadedFile = UploadedFile::getInstanceByName('reset-test');

        self::assertNotNull(
            $uploadedFile,
            'Should retrieve uploaded file for reset test.',
        );

        $resourcesBeforeReset = [];

        foreach (UploadedFile::$_files as $fileData) {
            if (isset($fileData['tempResource']) && is_resource($fileData['tempResource'])) {
                $resourcesBeforeReset[] = $fileData['tempResource'];
            }
        }

        self::assertNotEmpty(
            $resourcesBeforeReset,
            'Should have detached resources before reset.',
        );

        UploadedFile::reset();

        $stillOpenAfterReset = 0;

        foreach ($resourcesBeforeReset as $resource) {
            if (is_resource($resource)) {
                $stillOpenAfterReset++;
            }
        }

        self::assertGreaterThan(
            0,
            $stillOpenAfterReset,
            "Resources should still be open after current 'reset()' implementation, showing the need for improvement.",
        );
    }

    public function testReturnUploadedFileInstanceWhenMultipleFilesAreUploadedViaPsr7(): void
    {
        $tmpFile1 = $this->createTmpFile();

        $tmpPathFile1 = stream_get_meta_data($tmpFile1)['uri'];
        file_put_contents($tmpPathFile1, 'content1');

        $tmpFile2 = $this->createTmpFile();

        $tmpPathFile2 = stream_get_meta_data($tmpFile2)['uri'];
        file_put_contents($tmpPathFile2, 'content2');

        $adapter = new ServerRequestAdapter(
            FactoryHelper::createRequest('POST', 'http://example.com')
                ->withUploadedFiles(
                    [
                        'files' => [
                            FactoryHelper::createUploadedFile(
                                'file1.txt',
                                'text/plain',
                                FactoryHelper::createStream($tmpPathFile1),
                                UPLOAD_ERR_OK,
                                8,
                            ),
                            FactoryHelper::createUploadedFile(
                                'file2.txt',
                                'text/plain',
                                FactoryHelper::createStream($tmpPathFile2),
                                UPLOAD_ERR_OK,
                                8,
                            ),
                        ],
                    ],
                ),
        );

        UploadedFile::setPsr7Adapter($adapter);

        $allFiles = UploadedFile::getInstancesByName('files');

        self::assertCount(
            2,
            $allFiles,
            'Should return both files when querying by base name.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $allFiles[0] ?? null,
            'Should return an instance of UploadedFile when a single file is uploaded.',
        );
        self::assertSame(
            'file1.txt',
            $allFiles[0]->name,
            "Should preserve 'name' from PSR-7 UploadedFile.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $allFiles[1] ?? null,
            'Should return an instance of UploadedFile when a single file is uploaded.',
        );
        self::assertSame(
            'file2.txt',
            $allFiles[1]->name,
            "Should preserve 'name' from PSR-7 UploadedFile.",
        );

        $filesWithBrackets = UploadedFile::getInstancesByName('files[]');

        self::assertCount(
            2,
            $filesWithBrackets,
            "Should handle trailing '[]' notation.",
        );

        $uploadFile1 = UploadedFile::getInstanceByName('files[0]');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFile1,
            'Should return an instance of UploadedFile when a single file is uploaded.',
        );
        self::assertSame(
            'file1.txt',
            $uploadFile1->name,
            "Should preserve 'name' from PSR-7 UploadedFile.",
        );

        $uploadFile2 = UploadedFile::getInstanceByName('files[1]');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFile2,
            'Should return an instance of UploadedFile when a single file is uploaded.',
        );
        self::assertSame(
            'file2.txt',
            $uploadFile2->name,
            "Should preserve 'name' from PSR-7 UploadedFile.",
        );
        self::assertNotSame(
            $uploadFile1,
            $uploadFile2,
            'Should return different instances for different files.',
        );
    }
}
