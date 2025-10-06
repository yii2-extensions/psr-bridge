<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\support\stub\{ComplexUploadedFileModel, UploadedFileModel};
use yii2\extensions\psrbridge\tests\TestCase;

use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_OK;

/**
 * Test suite for {@see UploadedFile} class functionality and behavior.
 *
 * Verifies correct conversion, retrieval, and handling of uploaded files using PSR-7 adapters and legacy PHP globals
 * in the Yii2 PSR bridge.
 *
 * Test coverage.
 * - Confirms conversion of PSR-7 files with error and null size handling.
 * - Covers edge cases for missing files, error files, and resource management on reset.
 * - Ensures correct behavior for mixed error/success files, tabular and array attributes, and legacy file loading.
 * - Validates retrieval of single and multiple uploaded files via model attributes and complex structures.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class UploadedFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UploadedFile::reset();
    }

    public function testConvertPsr7FileWithErrorShouldNotThrowException(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/post')
                ->withUploadedFiles(
                    [
                        'error-file' => FactoryHelper::createUploadedFile(
                            'error-file.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_CANT_WRITE,
                            100,
                        ),
                    ],
                ),
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
            "Should have an empty 'tempName' when there is an upload error.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $uploadedFile->error,
            "Should preserve the upload 'error' code from PSR-7 UploadedFile.",
        );
        self::assertSame(
            100,
            $uploadedFile->size,
            "Should preserve the original file 'size' even when there is an upload error.",
        );
    }

    public function testConvertPsr7FileWithNullSizeShouldDefaultToZero(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/post')
                ->withUploadedFiles(
                    [
                        'null-size-file' => FactoryHelper::createUploadedFileFactory()->createUploadedFile(
                            FactoryHelper::createStream($this->createTmpFile()),
                            null,
                            UPLOAD_ERR_CANT_WRITE,
                            'null-size-file.txt',
                            'text/plain',
                        ),
                    ],
                ),
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

    public function testGetInstancesWithComplexUploadedFileModelAndPsr7AdapterForMultipleFiles(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'Complex_Model-Name[file_attribute]' => [
                            FactoryHelper::createUploadedFile(
                                'psr7-complex-array-1.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_OK,
                                38,
                            ),
                            FactoryHelper::createUploadedFile(
                                'psr7-complex-array-2.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_OK,
                                38,
                            ),
                        ],
                    ],
                ),
            ),
        );

        $files = UploadedFile::getInstances(new ComplexUploadedFileModel(), 'file_attribute');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files when using PSR-7 with ComplexUploadedFileModel for multiple files.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for first PSR-7 file with ComplexUploadedFileModel.',
        );
        self::assertSame(
            'psr7-complex-array-1.txt',
            $files[0]->name,
            "Should preserve 'name' from first PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            'text/plain',
            $files[0]->type,
            "Should preserve 'type' from first PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[0]->error,
            "Should preserve 'error' from first PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            38,
            $files[0]->size,
            "Should preserve 'size' from first PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertNotEmpty(
            $files[0]->tempName,
            "Should have a valid 'tempName' from first PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for second PSR-7 file with ComplexUploadedFileModel.',
        );
        self::assertSame(
            'psr7-complex-array-2.txt',
            $files[1]->name,
            "Should preserve 'name' from second PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            'text/plain',
            $files[1]->type,
            "Should preserve 'type' from second PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[1]->error,
            "Should preserve 'error' from second PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            38,
            $files[1]->size,
            "Should preserve 'size' from second PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertNotEmpty(
            $files[1]->tempName,
            "Should have a valid 'tempName' from second PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
    }

    public function testGetInstancesWithComplexUploadedFileModelAndPsr7AdapterWithMixedErrorFiles(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'Complex_Model-Name[file_attribute]' => [
                            FactoryHelper::createUploadedFile(
                                'psr7-complex-success-file.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_OK,
                                36,
                            ),
                            FactoryHelper::createUploadedFile(
                                'psr7-complex-error-file.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_CANT_WRITE,
                                120,
                            ),
                        ],
                    ],
                ),
            ),
        );

        $files = UploadedFile::getInstances(new ComplexUploadedFileModel(), 'file_attribute');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files when mixing successful and error files using PSR-7 with ComplexUploadedFileModel.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for successful file in mixed scenario using PSR-7 with ComplexUploadedFileModel.',
        );
        self::assertSame(
            'psr7-complex-success-file.txt',
            $files[0]->name,
            "Should preserve 'name' for successful file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertNotEmpty(
            $files[0]->tempName,
            "Should have a 'tempName' for successful file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[0]->error,
            "Should have no 'error' for successful file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            36,
            $files[0]->size,
            "Should preserve 'size' for successful file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for error file in mixed scenario using PSR-7 with ComplexUploadedFileModel.',
        );
        self::assertSame(
            'psr7-complex-error-file.txt',
            $files[1]->name,
            "Should preserve 'name' for error file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            '',
            $files[1]->tempName,
            "Should have empty 'tempName' for error file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $files[1]->error,
            "Should preserve 'error' code for error file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            120,
            $files[1]->size,
            "Should preserve 'size' for error file in mixed scenario using PSR-7 with ComplexUploadedFileModel.",
        );
    }

    public function testGetInstancesWithModelAndArrayAttributeReturnsArrayForArrayIndexedUpload(): void
    {
        $_FILES = [
            'UploadedFileModel[files][0]' => [
                'name' => 'array-indexed-1.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phparray1',
                'error' => UPLOAD_ERR_OK,
                'size' => 180,
            ],
            'UploadedFileModel[files][1]' => [
                'name' => 'array-indexed-2.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phparray2',
                'error' => UPLOAD_ERR_OK,
                'size' => 220,
            ],
        ];

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'files');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files for array-indexed attribute upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for first file in array-indexed upload.',
        );
        self::assertSame(
            'array-indexed-1.txt',
            $files[0]->name,
            'Should preserve \'name\' from $_FILES for first file in array-indexed upload.',
        );
        self::assertSame(
            180,
            $files[0]->size,
            'Should preserve \'size\' from $_FILES for first file in array-indexed upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for second file in array-indexed upload.',
        );
        self::assertSame(
            'array-indexed-2.txt',
            $files[1]->name,
            'Should preserve \'name\' from $_FILES for second file in array-indexed upload.',
        );
        self::assertSame(
            220,
            $files[1]->size,
            'Should preserve \'size\' from $_FILES for second file in array-indexed upload.',
        );
    }

    public function testGetInstancesWithModelAndAttributeHandlesComplexModelNameArray(): void
    {
        $_FILES = [
            'Complex_Model-Name[file_attribute][0]' => [
                'name' => 'complex-array-1.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpcomplex1',
                'error' => UPLOAD_ERR_OK,
                'size' => 300,
            ],
            'Complex_Model-Name[file_attribute][1]' => [
                'name' => 'complex-array-2.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpcomplex2',
                'error' => UPLOAD_ERR_OK,
                'size' => 350,
            ],
        ];

        $files = UploadedFile::getInstances(new ComplexUploadedFileModel(), 'file_attribute');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files for complex model name with array upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for first file in complex model array upload.',
        );
        self::assertSame(
            'complex-array-1.txt',
            $files[0]->name,
            'Should preserve \'name\' from $_FILES for first file in complex model array upload.',
        );
        self::assertSame(
            300,
            $files[0]->size,
            'Should preserve \'size\' from $_FILES for first file in complex model array upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for second file in complex model array upload.',
        );
        self::assertSame(
            'complex-array-2.txt',
            $files[1]->name,
            'Should preserve \'name\' from $_FILES for second file in complex model array upload.',
        );
        self::assertSame(
            350,
            $files[1]->size,
            'Should preserve \'size\' from $_FILES for second file in complex model array upload.',
        );
    }

    public function testGetInstancesWithModelAndAttributeHandlesErrorFilesArray(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'UploadedFileModel[file]' => [
                            FactoryHelper::createUploadedFile(
                                'error-array-1.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_CANT_WRITE,
                                75,
                            ),
                            FactoryHelper::createUploadedFile(
                                'error-array-2.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_CANT_WRITE,
                                85,
                            ),
                        ],
                    ],
                ),
            ),
        );

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'file');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files even when there are upload errors.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for first file even when there is an upload error.',
        );
        self::assertSame(
            'error-array-1.txt',
            $files[0]->name,
            "Should preserve the original file 'name' for first file even when there is an upload error.",
        );
        self::assertSame(
            '',
            $files[0]->tempName,
            "Should have an empty 'tempName' for first file when there is an upload error.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $files[0]->error,
            "Should preserve the upload 'error' code for first file from PSR-7 UploadedFile.",
        );
        self::assertSame(
            75,
            $files[0]->size,
            "Should preserve the original file 'size' for first file even when there is an upload error.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for second file even when there is an upload error.',
        );
        self::assertSame(
            'error-array-2.txt',
            $files[1]->name,
            "Should preserve the original file 'name' for second file even when there is an upload error.",
        );
        self::assertSame(
            '',
            $files[1]->tempName,
            "Should have an empty 'tempName' for second file when there is an upload error.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $files[1]->error,
            "Should preserve the upload 'error' code for second file from PSR-7 UploadedFile.",
        );
        self::assertSame(
            85,
            $files[1]->size,
            "Should preserve the original file 'size' for second file even when there is an upload error.",
        );
    }

    public function testGetInstancesWithModelAndAttributeHandlesMixedSuccessAndErrorFiles(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'UploadedFileModel[file]' => [
                            FactoryHelper::createUploadedFile(
                                'success-file.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_OK,
                                20,
                            ),
                            FactoryHelper::createUploadedFile(
                                'error-file.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_CANT_WRITE,
                                100,
                            ),
                        ],
                    ],
                ),
            ),
        );

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'file');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files when mixing successful and error files.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for successful file in mixed scenario.',
        );
        self::assertSame(
            'success-file.txt',
            $files[0]->name,
            "Should preserve 'name' for successful file in mixed scenario.",
        );
        self::assertNotEmpty(
            $files[0]->tempName,
            "Should have a 'tempName' for successful file in mixed scenario.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[0]->error,
            "Should have no 'error' for successful file in mixed scenario.",
        );
        self::assertSame(
            20,
            $files[0]->size,
            "Should preserve 'size' for successful file in mixed scenario.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for error file in mixed scenario.',
        );
        self::assertSame(
            'error-file.txt',
            $files[1]->name,
            "Should preserve 'name' for error file in mixed scenario.",
        );
        self::assertSame(
            '',
            $files[1]->tempName,
            "Should have empty 'tempName' for error file in mixed scenario.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $files[1]->error,
            "Should preserve 'error' code for error file in mixed scenario.",
        );
        self::assertSame(
            100,
            $files[1]->size,
            "Should preserve 'size' for error file in mixed scenario.",
        );
    }

    public function testGetInstancesWithModelAndAttributeReturnsArrayForMultipleFilesUpload(): void
    {
        $_FILES = [
            'UploadedFileModel[file][0]' => [
                'name' => 'multi-file-1.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpmulti1',
                'error' => UPLOAD_ERR_OK,
                'size' => 256,
            ],
            'UploadedFileModel[file][1]' => [
                'name' => 'multi-file-2.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '/tmp/phpmulti2',
                'error' => UPLOAD_ERR_OK,
                'size' => 512,
            ],
            'UploadedFileModel[file][2]' => [
                'name' => 'multi-file-3.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/phpmulti3',
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ],
        ];

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'file');

        self::assertCount(
            3,
            $files,
            'Should return an array with three files for multiple files upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for first file in multiple upload.',
        );
        self::assertSame(
            'multi-file-1.txt',
            $files[0]->name,
            'Should preserve \'name\' from $_FILES for first file in multiple upload.',
        );
        self::assertSame(
            'text/plain',
            $files[0]->type,
            'Should preserve \'type\' from $_FILES for first file in multiple upload.',
        );
        self::assertSame(
            256,
            $files[0]->size,
            'Should preserve \'size\' from $_FILES for first file in multiple upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for second file in multiple upload.',
        );
        self::assertSame(
            'multi-file-2.pdf',
            $files[1]->name,
            'Should preserve \'name\' from $_FILES for second file in multiple upload.',
        );
        self::assertSame(
            'application/pdf',
            $files[1]->type,
            'Should preserve \'type\' from $_FILES for second file in multiple upload.',
        );
        self::assertSame(
            512,
            $files[1]->size,
            'Should preserve \'size\' from $_FILES for second file in multiple upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[2] ?? null,
            'Should return an instance of UploadedFile for third file in multiple upload.',
        );
        self::assertSame(
            'multi-file-3.jpg',
            $files[2]->name,
            'Should preserve \'name\' from $_FILES for third file in multiple upload.',
        );
        self::assertSame(
            'image/jpeg',
            $files[2]->type,
            'Should preserve \'type\' from $_FILES for third file in multiple upload.',
        );
        self::assertSame(
            1024,
            $files[2]->size,
            'Should preserve \'size\' from $_FILES for third file in multiple upload.',
        );
    }

    public function testGetInstancesWithModelAndAttributeReturnsArrayForPsr7MultipleFiles(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'UploadedFileModel[file]' => [
                            FactoryHelper::createUploadedFile(
                                'psr7-array-1.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_OK,
                                28,
                            ),
                            FactoryHelper::createUploadedFile(
                                'psr7-array-2.txt',
                                'text/plain',
                                FactoryHelper::createStream($this->createTmpFile()),
                                UPLOAD_ERR_OK,
                                28,
                            ),
                        ],
                    ],
                ),
            ),
        );

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'file');

        self::assertCount(
            2,
            $files,
            'Should return an array with two files when using PSR-7 with multiple files and model attribute.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for first PSR-7 file with model and attribute.',
        );
        self::assertSame(
            'psr7-array-1.txt',
            $files[0]->name,
            "Should preserve 'name' from first PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            'text/plain',
            $files[0]->type,
            "Should preserve 'type' from first PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[0]->error,
            "Should preserve 'error' from first PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            28,
            $files[0]->size,
            "Should preserve 'size' from first PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[1] ?? null,
            'Should return an instance of UploadedFile for second PSR-7 file with model and attribute.',
        );
        self::assertSame(
            'psr7-array-2.txt',
            $files[1]->name,
            "Should preserve 'name' from second PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            'text/plain',
            $files[1]->type,
            "Should preserve 'type' from second PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[1]->error,
            "Should preserve 'error' from second PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            28,
            $files[1]->size,
            "Should preserve 'size' from second PSR-7 UploadedFile when using model and attribute.",
        );
    }

    public function testGetInstancesWithModelAndAttributeReturnsArrayForSingleFileUpload(): void
    {
        $_FILES = [
            'UploadedFileModel[file]' => [
                'name' => 'single-file.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/phpsingle',
                'error' => UPLOAD_ERR_OK,
                'size' => 128,
            ],
        ];

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'file');

        self::assertCount(
            1,
            $files,
            'Should return an array with one file for single file upload.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for single file upload.',
        );
        self::assertSame(
            'single-file.txt',
            $files[0]->name,
            'Should preserve \'name\' from $_FILES for single file upload.',
        );
        self::assertSame(
            'text/plain',
            $files[0]->type,
            'Should preserve \'type\' from $_FILES for single file upload.',
        );
        self::assertSame(
            '/tmp/phpsingle',
            $files[0]->tempName,
            'Should preserve \'tmp_name\' from $_FILES for single file upload.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $files[0]->error,
            'Should preserve \'error\' from $_FILES for single file upload.',
        );
        self::assertSame(
            128,
            $files[0]->size,
            'Should preserve \'size\' from $_FILES for single file upload.',
        );
    }

    public function testGetInstancesWithModelAndAttributeReturnsEmptyArrayWhenNoFilesUploaded(): void
    {
        $_FILES = [];

        $files = UploadedFile::getInstances(new UploadedFileModel(), 'file');

        self::assertEmpty(
            $files,
            'Should return an empty array when no files are uploaded for the specified model attribute.',
        );
    }

    public function testGetInstancesWithModelAndTabularAttributeReturnsArrayForTabularUpload(): void
    {
        $_FILES = [
            'UploadedFileModel[1][file]' => [
                'name' => 'tabular-array-1.txt',
                'type' => 'application/json',
                'tmp_name' => '/tmp/phptabular1',
                'error' => UPLOAD_ERR_OK,
                'size' => 400,
            ],
            'UploadedFileModel[2][file]' => [
                'name' => 'tabular-array-2.txt',
                'type' => 'application/json',
                'tmp_name' => '/tmp/phptabular2',
                'error' => UPLOAD_ERR_OK,
                'size' => 450,
            ],
        ];

        $files = UploadedFile::getInstances(new UploadedFileModel(), '[1]file');

        self::assertCount(
            1,
            $files,
            'Should return an array with one file for specific tabular index.',
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $files[0] ?? null,
            'Should return an instance of UploadedFile for tabular-style attribute upload.',
        );
        self::assertSame(
            'tabular-array-1.txt',
            $files[0]->name,
            'Should preserve \'name\' from $_FILES for tabular-style attribute upload.',
        );
        self::assertSame(
            'application/json',
            $files[0]->type,
            'Should preserve \'type\' from $_FILES for tabular-style attribute upload.',
        );
        self::assertSame(
            400,
            $files[0]->size,
            'Should preserve \'size\' from $_FILES for tabular-style attribute upload.',
        );
    }

    public function testGetInstanceWithComplexUploadedFileModelAndPsr7Adapter(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'Complex_Model-Name[file_attribute]' => FactoryHelper::createUploadedFile(
                            'psr7-complex-model-test.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_OK,
                            30,
                        ),
                    ],
                ),
            ),
        );

        $uploadedFile = UploadedFile::getInstance(new ComplexUploadedFileModel(), 'file_attribute');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile when using PSR-7 with ComplexUploadedFileModel.',
        );
        self::assertSame(
            'psr7-complex-model-test.txt',
            $uploadedFile->name,
            "Should preserve 'name' from PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->type,
            "Should preserve 'type' from PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->error,
            "Should preserve 'error' from PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            30,
            $uploadedFile->size,
            "Should preserve 'size' from PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertNotEmpty(
            $uploadedFile->tempName,
            "Should have a valid 'tempName' from PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
    }

    public function testGetInstanceWithComplexUploadedFileModelAndPsr7AdapterWithError(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'Complex_Model-Name[file_attribute]' => FactoryHelper::createUploadedFile(
                            'psr7-complex-error-test.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_CANT_WRITE,
                            75,
                        ),
                    ],
                ),
            ),
        );

        $uploadedFile = UploadedFile::getInstance(new ComplexUploadedFileModel(), 'file_attribute');

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            'Should return an instance of UploadedFile even when there is an upload error using PSR-7 with ComplexUploadedFileModel.',
        );
        self::assertSame(
            'psr7-complex-error-test.txt',
            $uploadedFile->name,
            "Should preserve the original file 'name' even when there is an upload error using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->type,
            "Should preserve the original file 'type' even when there is an upload error using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            '',
            $uploadedFile->tempName,
            "Should have an empty 'tempName' when there is an upload error using PSR-7 with ComplexUploadedFileModel.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $uploadedFile->error,
            "Should preserve the upload 'error' code from PSR-7 UploadedFile when using ComplexUploadedFileModel.",
        );
        self::assertSame(
            75,
            $uploadedFile->size,
            "Should preserve the original file 'size' even when there is an upload error using PSR-7 with ComplexUploadedFileModel.",
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
            'Should preserve \'name\' from $_FILES when using array-indexed attribute.',
        );
        self::assertSame(
            150,
            $uploadedFile->size,
            'Should preserve \'size\' from $_FILES when using array-indexed attribute.',
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
            'Should preserve \'name\' from $_FILES when using complex model name.',
        );
        self::assertSame(
            250,
            $uploadedFile->size,
            'Should preserve \'size\' from $_FILES when using complex model name.',
        );
    }

    public function testGetInstanceWithModelAndAttributeHandlesErrorFiles(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'UploadedFileModel[file]' => FactoryHelper::createUploadedFile(
                            'error-model-test.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_CANT_WRITE,
                            50,
                        ),
                    ],
                ),
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
            "Should preserve the original file 'name' even when there is an upload error using model and attribute.",
        );
        self::assertSame(
            '',
            $uploadedFile->tempName,
            "Should have an empty 'tempName' when there is an upload error using model and attribute.",
        );
        self::assertSame(
            UPLOAD_ERR_CANT_WRITE,
            $uploadedFile->error,
            "Should preserve the upload 'error' code from PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            50,
            $uploadedFile->size,
            "Should preserve the original file 'size' even when there is an upload error using model and attribute.",
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
            'Should preserve \'name\' from $_FILES when using model and attribute.',
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->type,
            'Should preserve \'type\' from $_FILES when using model and attribute.',
        );
        self::assertSame(
            '/tmp/phpmodel',
            $uploadedFile->tempName,
            'Should preserve \'tmp_name\' from $_FILES when using model and attribute.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->error,
            'Should preserve \'error\' from $_FILES when using model and attribute.',
        );
        self::assertSame(
            200,
            $uploadedFile->size,
            'Should preserve \'size\' from $_FILES when using model and attribute.',
        );
    }

    public function testGetInstanceWithModelAndAttributeReturnsUploadedFileForPsr7Files(): void
    {
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/upload')
                ->withUploadedFiles(
                    [
                        'UploadedFileModel[file]' => FactoryHelper::createUploadedFile(
                            'psr7-model-test.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_OK,
                            24,
                        ),
                    ],
                ),
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
            "Should preserve 'name' from PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            'text/plain',
            $retrievedFile->type,
            "Should preserve 'type' from PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $retrievedFile->error,
            "Should preserve 'error' from PSR-7 UploadedFile when using model and attribute.",
        );
        self::assertSame(
            24,
            $retrievedFile->size,
            "Should preserve 'size' from PSR-7 UploadedFile when using model and attribute.",
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
            'Should preserve \'name\' from $_FILES when using tabular-style attribute.',
        );
        self::assertSame(
            'application/json',
            $uploadedFile->type,
            'Should preserve \'type\' from $_FILES when using tabular-style attribute.',
        );
        self::assertSame(
            300,
            $uploadedFile->size,
            'Should preserve \'size\' from $_FILES when using tabular-style attribute.',
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
        UploadedFile::setPsr7Adapter(
            new ServerRequestAdapter(
                FactoryHelper::createRequest('POST', 'site/post')
                ->withUploadedFiles(
                    [
                        'reset-test' => FactoryHelper::createUploadedFile(
                            'reset-test.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_OK,
                            32,
                        ),
                    ],
                ),
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
        $adapter = new ServerRequestAdapter(
            FactoryHelper::createRequest('POST', 'http://example.com')
            ->withUploadedFiles(
                [
                    'files' => [
                        FactoryHelper::createUploadedFile(
                            'file1.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
                            UPLOAD_ERR_OK,
                            8,
                        ),
                        FactoryHelper::createUploadedFile(
                            'file2.txt',
                            'text/plain',
                            FactoryHelper::createStream($this->createTmpFile()),
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
