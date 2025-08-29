<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\support\stub\{ComplexUploadedFileModel, UploadedFileModel};
use yii2\extensions\psrbridge\tests\TestCase;

use function file_put_contents;
use function stream_get_meta_data;

use const UPLOAD_ERR_CANT_WRITE;
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

    public function testConvertPsr7FileWithNullSizeShouldDefaultToZero(): void
    {
        $tmpFile = $this->createTmpFile();

        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $uploadedFileWithNullSize = FactoryHelper::createUploadedFileFactory()->createUploadedFile(
            FactoryHelper::createStream($tmpPath),
            null,
            UPLOAD_ERR_CANT_WRITE,
            'null-size-file.txt',
            'text/plain',
        );

        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/post')
                    ->withUploadedFiles(['null-size-file' => $uploadedFileWithNullSize]),
            ),
        );

        $uploadedFile = UploadedFile::getInstanceByName('null-size-file');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            "Should return an instance of UploadedFile with 'null' size.",
        );
        self::assertSame(
            0,
            $uploadedFile->size,
            "Should default to exactly '0' when PSR-7 'getSize()' method returns 'null' in error condition.",
        );
    }

    public function testGetInstanceWithModelAndArrayAttributeReturnsUploadedFile(): void
    {
        $_FILES = [
            'UploadedFileModel[files][0]' => [
                'name' => 'array-test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phparray',
                'error' => UPLOAD_ERR_OK,
                'size' => 150,
            ],
        ];

        $uploadedFile = UploadedFile::getInstance(new UploadedFileModel(), 'files[0]');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile when using array-indexed attribute.',
        );
        self::assertSame(
            'array-test.txt',
            $uploadedFile->name,
            'Should preserve name from $_FILES when using array-indexed attribute.',
        );
        self::assertSame(
            150,
            $uploadedFile->size,
            'Should preserve size from $_FILES when using array-indexed attribute.',
        );
    }

    public function testGetInstanceWithModelAndAttributeHandlesComplexModelName(): void
    {
        $_FILES = [
            'Complex_Model-Name[file_attribute]' => [
                'name' => 'complex-name-test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpcomplex',
                'error' => UPLOAD_ERR_OK,
                'size' => 250,
            ],
        ];

        $uploadedFile = UploadedFile::getInstance(new ComplexUploadedFileModel(), 'file_attribute');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile when using complex model name.',
        );
        self::assertSame(
            'complex-name-test.txt',
            $uploadedFile->name,
            'Should preserve name from $_FILES when using complex model name.',
        );
        self::assertSame(
            250,
            $uploadedFile->size,
            'Should preserve size from $_FILES when using complex model name.',
        );
    }

    public function testGetInstanceWithModelAndAttributeHandlesErrorFiles(): void
    {
        $tmpFile = $this->createTmpFile();

        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $uploadedFileWithError = FactoryHelper::createUploadedFile(
            'error-model-test.txt',
            'text/plain',
            FactoryHelper::createStream($tmpPath),
            UPLOAD_ERR_CANT_WRITE,
            50,
        );

        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                    ->withUploadedFiles(['UploadedFileModel[file]' => $uploadedFileWithError]),
            ),
        );

        $uploadedFile = UploadedFile::getInstance(new UploadedFileModel(), 'file');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile even when there is an upload error using model and attribute.',
        );
        self::assertSame(
            'error-model-test.txt',
            $uploadedFile->name,
            'Should preserve the original file name even when there is an upload error using model and attribute.',
        );
        self::assertSame(
            '',
            $uploadedFile->tempName,
            'Should have an empty tempName when there is an upload error using model and attribute.',
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $uploadedFile->error,
            'Should preserve the upload error code from PSR-7 UploadedFile when using model and attribute.',
        );
        self::assertSame(
            50,
            $uploadedFile->size,
            'Should preserve the original file size even when there is an upload error using model and attribute.',
        );
    }

    public function testGetInstanceWithModelAndAttributeReturnsNullWhenNoFileUploaded(): void
    {
        $_FILES = [];

        $uploadedFile = UploadedFile::getInstance(new UploadedFileModel(), 'file');

        self::assertNull(
            $uploadedFile,
            'Should return null when no file was uploaded for the specified model attribute.',
        );
    }

    public function testGetInstanceWithModelAndAttributeReturnsUploadedFileForLegacyFiles(): void
    {
        $_FILES = [
            'UploadedFileModel[file]' => [
                'name' => 'model-test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpmodel',
                'error' => UPLOAD_ERR_OK,
                'size' => 200,
            ],
        ];

        $uploadedFile = UploadedFile::getInstance(new UploadedFileModel(), 'file');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile when using model and attribute.',
        );
        self::assertSame(
            'model-test.txt',
            $uploadedFile->name,
            'Should preserve name from $_FILES when using model and attribute.',
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->type,
            'Should preserve type from $_FILES when using model and attribute.',
        );
        self::assertSame(
            '/tmp/phpmodel',
            $uploadedFile->tempName,
            'Should preserve tmp_name from $_FILES when using model and attribute.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->error,
            'Should preserve error from $_FILES when using model and attribute.',
        );
        self::assertSame(
            200,
            $uploadedFile->size,
            'Should preserve size from $_FILES when using model and attribute.',
        );
    }

    public function testGetInstanceWithModelAndAttributeReturnsUploadedFileForPsr7Files(): void
    {
        $tmpFile = $this->createTmpFile();

        $tmpPath = stream_get_meta_data($tmpFile)['uri'];
        file_put_contents($tmpPath, 'PSR-7 model test content');

        $uploadedFile = FactoryHelper::createUploadedFile(
            'psr7-model-test.txt',
            'text/plain',
            FactoryHelper::createStream($tmpPath),
            UPLOAD_ERR_OK,
            24,
        );

        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                    ->withUploadedFiles(['UploadedFileModel[file]' => $uploadedFile]),
            ),
        );

        $retrievedFile = UploadedFile::getInstance(new UploadedFileModel(), 'file');

        self::assertInstanceOf(
            UploadedFile::class,
            $retrievedFile,
            'Should return an instance of UploadedFile when using PSR-7 with model and attribute.',
        );
        self::assertSame(
            'psr7-model-test.txt',
            $retrievedFile->name,
            'Should preserve name from PSR-7 UploadedFile when using model and attribute.',
        );
        self::assertSame(
            'text/plain',
            $retrievedFile->type,
            'Should preserve type from PSR-7 UploadedFile when using model and attribute.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $retrievedFile->error,
            'Should preserve error from PSR-7 UploadedFile when using model and attribute.',
        );
        self::assertSame(
            24,
            $retrievedFile->size,
            'Should preserve size from PSR-7 UploadedFile when using model and attribute.',
        );
    }

    public function testGetInstanceWithModelAndTabularAttributeReturnsUploadedFile(): void
    {
        $_FILES = [
            'UploadedFileModel[1][file]' => [
                'name' => 'tabular-test.txt',
                'type' => 'application/json',
                'tmp_name' => '/tmp/phptabular',
                'error' => UPLOAD_ERR_OK,
                'size' => 300,
            ],
        ];

        $uploadedFile = UploadedFile::getInstance(new UploadedFileModel(), '[1]file');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile when using tabular-style attribute.',
        );
        self::assertSame(
            'tabular-test.txt',
            $uploadedFile->name,
            'Should preserve name from $_FILES when using tabular-style attribute.',
        );
        self::assertSame(
            'application/json',
            $uploadedFile->type,
            'Should preserve type from $_FILES when using tabular-style attribute.',
        );
        self::assertSame(
            300,
            $uploadedFile->size,
            'Should preserve size from $_FILES when using tabular-style attribute.',
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

    public function testLoadFilesRecursiveInternalWithArrayNamesAndMissingIndices(): void
    {
        $_FILES = [
            'documents' => [
                'name' => ['doc1.pdf', 'doc2.docx', 'doc3.txt'],
                'type' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'tmp_name' => ['/tmp/php1', '/tmp/php2', '/tmp/php3'],
                'size' => [1024, 2048],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_CANT_WRITE],
            ],
        ];

        $uploadFiles = UploadedFile::getInstancesByName('documents');

        self::assertCount(
            3,
            $uploadFiles,
            'Should process all files in the array structure.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFiles[0] ?? null,
            'Should return an instance of UploadedFile for first file.',
        );
        self::assertSame(
            'doc1.pdf',
            $uploadFiles[0]->name,
            "Should preserve 'name' from array at index '0'.",
        );
        self::assertSame(
            'application/pdf',
            $uploadFiles[0]->type,
            "Should preserve 'type' from array at index '0'.",
        );
        self::assertSame(
            '/tmp/php1',
            $uploadFiles[0]->tempName,
            "Should preserve 'tmp_name' from array at index '0'.",
        );
        self::assertSame(
            1024,
            $uploadFiles[0]->size,
            "Should preserve 'size' from array at index '0'.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadFiles[0]->error,
            "Should preserve 'error' from array at index '0'.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFiles[1] ?? null,
            'Should return an instance of UploadedFile for second file.',
        );
        self::assertSame(
            'doc2.docx',
            $uploadFiles[1]->name,
            "Should preserve 'name' from array at index '1'.",
        );
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $uploadFiles[1]->type,
            "Should preserve 'type' from array at index '1'.",
        );
        self::assertSame(
            '/tmp/php2',
            $uploadFiles[1]->tempName,
            "Should preserve 'tmp_name' from array at index '1'.",
        );
        self::assertSame(
            2048,
            $uploadFiles[1]->size,
            "Should preserve 'size' from array at index '1'.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadFiles[1]->error,
            "Should preserve 'error' from array at index '1'.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFiles[2] ?? null,
            'Should return an instance of UploadedFile for third file.',
        );
        self::assertSame(
            'doc3.txt',
            $uploadFiles[2]->name,
            "Should preserve 'name' from array at index '2'.",
        );
        self::assertSame(
            '',
            $uploadFiles[2]->type,
            "Should default to empty string when 'types[2]' is missing.",
        );
        self::assertSame(
            '/tmp/php3',
            $uploadFiles[2]->tempName,
            "Should preserve 'tmp_name' from array at index '2'.",
        );
        self::assertSame(
            0,
            $uploadFiles[2]->size,
            "Should default to 0 when 'sizes[2]' is missing.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $uploadFiles[2]->error,
            "Should preserve 'error' from array at index '2'.",
        );

        $document1 = UploadedFile::getInstanceByName('documents[0]');

        self::assertInstanceOf(
            UploadedFile::class,
            $document1,
            'Should return instance when accessing by array index notation.',
        );
        self::assertSame(
            'doc1.pdf',
            $document1->name,
            "Should preserve 'name' when accessing via 'documents[0]'.",
        );

        $document3 = UploadedFile::getInstanceByName('documents[2]');

        self::assertInstanceOf(
            UploadedFile::class,
            $document3,
            'Should return instance for file with missing indices.',
        );
        self::assertSame(
            '',
            $document3->type,
            'Should have default empty type when accessing file with missing type index.',
        );
        self::assertSame(
            0,
            $document3->size,
            "Should have default size of '0' when accessing file with missing size index.",
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

        self::assertSame(
            0,
            $stillOpenAfterReset,
            "All resources should be closed after 'reset()' method.",
        );
    }

    public function testReturnUploadedFileInstanceWhenLegacyFilesSizeIsArray(): void
    {
        $_FILES = [
            'file' => [
                'name' => 'file1.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phptest1',
                'error' => UPLOAD_ERR_OK,
                'size' => [0],
            ],
        ];

        $uploadFiles = UploadedFile::getInstancesByName('file');

        self::assertCount(
            1,
            $uploadFiles,
            'Should process the malformed array structure.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $uploadFiles[0] ?? null,
            'Should return an instance of UploadedFile when a single file is uploaded.',
        );
        self::assertSame(
            'file1.txt',
            $uploadFiles[0]->name,
            'Should preserve \'name\' from $_FILES.',
        );
        self::assertSame(
            'text/plain',
            $uploadFiles[0]->type,
            'Should preserve \'type\' from $_FILES.',
        );
        self::assertSame(
            '/tmp/phptest1',
            $uploadFiles[0]->tempName,
            'Should preserve \'tmp_name\' from $_FILES.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadFiles[0]->error,
            'Should preserve \'error\' from $_FILES.',
        );
        self::assertSame(
            0,
            $uploadFiles[0]->size,
            "Should default to exactly '0' when legacy file 'size' is an array.",
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
