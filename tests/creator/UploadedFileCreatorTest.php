<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\creator;

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\UploadedFileInterface;
use yii\base\InvalidArgumentException;
use yii2\extensions\psrbridge\creator\UploadedFileCreator;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function stream_get_meta_data;

#[Group('http')]
#[Group('creator')]

final class UploadedFileCreatorTest extends TestCase
{
    public function testCreateFromArrayWithMinimalFileSpec(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 512,
            'error' => UPLOAD_ERR_OK,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $uploadedFile = $creator->createFromArray($fileSpec);

        self::assertNull(
            $uploadedFile->getClientFilename(),
            "Should return 'null' for client filename when not specified.",
        );
        self::assertNull(
            $uploadedFile->getClientMediaType(),
            "Should return 'null' for 'client media type' when not specified.",
        );
        self::assertSame(
            512,
            $uploadedFile->getSize(),
            "Should preserve 'file size' from minimal specification.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->getError(),
            "Should preserve 'error code' from minimal specification.",
        );
    }

    public function testCreateFromArrayWithNullOptionalFields(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 256,
            'error' => UPLOAD_ERR_OK,
            'name' => null,
            'type' => null,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $uploadedFile = $creator->createFromArray($fileSpec);

        self::assertNull(
            $uploadedFile->getClientFilename(),
            "Should return 'null' for 'client filename' when explicitly set to 'null'.",
        );
        self::assertNull(
            $uploadedFile->getClientMediaType(),
            "Should return 'null' for 'client media type' when explicitly set to 'null'.",
        );
        self::assertSame(
            256,
            $uploadedFile->getSize(),
            "Should preserve 'file size' when optional fields are 'null'.",
        );
    }

    public function testCreateFromArrayWithValidFileSpec(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $uploadedFile = $creator->createFromArray($fileSpec);

        self::assertSame(
            'test.txt',
            $uploadedFile->getClientFilename(),
            "Should preserve 'client filename' from file specification.",
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->getClientMediaType(),
            "Should preserve 'client media type' from file specification.",
        );
        self::assertSame(
            1024,
            $uploadedFile->getSize(),
            "Should preserve 'file size' from file specification.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->getError(),
            "Should preserve 'error code' from file specification.",
        );
    }

    public function testCreateFromGlobalsWithEmptyArray(): void
    {
        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        self::assertEmpty(
            $creator->createFromGlobals([]),
            "Should return empty 'array' when no files are provided.",
        );
    }

    public function testCreateFromGlobalsWithExistingUploadedFileInterface(): void
    {
        $existingUploadedFile = FactoryHelper::createUploadedFile(
            'existing.txt',
            'text/plain',
            '/tmp/existing',
            UPLOAD_ERR_OK,
            1024,
        );

        $files = [
            'existing' => $existingUploadedFile,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $result = $creator->createFromGlobals($files);

        self::assertCount(
            1,
            $result,
            "Should return 'array' with existing 'UploadedFileInterface' instance.",
        );
        self::assertSame(
            $existingUploadedFile,
            $result['existing'] ?? null,
            "Should preserve existing 'UploadedFileInterface' instance in result 'array'.",
        );
    }

    public function testCreateFromGlobalsWithMixedFileStructures(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];
        $tempPath3 = stream_get_meta_data($this->getTmpFile3())['uri'];

        $files = [
            'single' => [
                'tmp_name' => $tempPath1,
                'size' => 1024,
                'error' => UPLOAD_ERR_OK,
                'name' => 'single.txt',
                'type' => 'text/plain',
            ],
            'multiple' => [
                'tmp_name' => [
                    $tempPath2,
                    $tempPath3,
                ],
                'size' => [
                    2048,
                    1536,
                ],
                'error' => [
                    UPLOAD_ERR_OK,
                    UPLOAD_ERR_OK,
                ],
                'name' => [
                    'multi1.pdf',
                    'multi2.doc',
                ],
                'type' => [
                    'application/pdf',
                    'application/msword',
                ],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $result = $creator->createFromGlobals($files);

        self::assertCount(
            2,
            $result,
            "Should return 'array' with both 'single' and 'multiple' keys.",
        );

        $singleFile = $result['single'] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $singleFile,
            "Should return instance of 'UploadedFileInterface' for 'single' file.",
        );
        self::assertSame(
            'single.txt',
            $singleFile->getClientFilename(),
            "Should preserve 'client filename' for 'single' file.",
        );
        self::assertSame(
            1024,
            $singleFile->getSize(),
            "Should preserve 'file size' for 'single' file.",
        );

        $multipleFiles = $result['multiple'] ?? null;

        self::assertIsArray(
            $multipleFiles,
            "Should return 'array' for 'multiple' files.",
        );
        self::assertCount(
            2,
            $multipleFiles,
            "Should have two files in 'multiple' arrays.",
        );

        $firstMultiple = $multipleFiles[0] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $firstMultiple,
            "Should return instance of 'UploadedFileInterface' for first file in 'multiple'.",
        );
        self::assertSame(
            'multi1.pdf',
            $firstMultiple->getClientFilename(),
            "Should preserve 'client filename' for first file in 'multiple'.",
        );
        self::assertSame(
            2048,
            $firstMultiple->getSize(),
            "Should preserve 'file size' for first file in 'multiple'.",
        );

        $secondMultiple = $multipleFiles[1] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $secondMultiple,
            "Should return instance of 'UploadedFileInterface' for second file in 'multiple'.",
        );
        self::assertSame(
            'multi2.doc',
            $secondMultiple->getClientFilename(),
            "Should preserve 'client filename' for second file in 'multiple'.",
        );
        self::assertSame(
            1536,
            $secondMultiple->getSize(),
            "Should preserve 'file size' for second file in 'multiple'.",
        );
    }

    public function testCreateFromGlobalsWithMultipleFiles(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    $tempPath1,
                    $tempPath2,
                ],
                'size' => [
                    2048,
                    1536,
                ],
                'error' => [
                    UPLOAD_ERR_OK,
                    UPLOAD_ERR_OK,
                ],
                'name' => [
                    'doc1.txt',
                    'doc2.pdf',
                ],
                'type' => [
                    'text/plain',
                    'application/pdf',
                ],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $result = $creator->createFromGlobals($files);

        self::assertCount(
            1,
            $result,
            "Should return 'array' with 'documents' key.",
        );
        self::assertArrayHasKey(
            'documents',
            $result,
            "Should preserve 'documents' key from input.",
        );

        $documentsArray = $result['documents'] ?? null;

        self::assertIsArray(
            $documentsArray,
            "Should return 'array' of files for 'documents' upload.",
        );
        self::assertCount(
            2,
            $documentsArray,
            "Should have two files in 'documents' arrays.",
        );

        $firstFile = $documentsArray[0] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $firstFile,
            "Should return instance of 'UploadedFileInterface' for first file in 'documents'.",
        );
        self::assertSame(
            'doc1.txt',
            $firstFile->getClientFilename(),
            "Should preserve 'client filename' for first file in 'documents'.",
        );
        self::assertSame(
            'text/plain',
            $firstFile->getClientMediaType(),
            "Should preserve 'client media type' for first file in 'documents'.",
        );
        self::assertSame(
            2048,
            $firstFile->getSize(),
            "Should preserve 'file size' for first file in 'documents'.",
        );

        $secondFile = $documentsArray[1] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $secondFile,
            "Should return instance of 'UploadedFileInterface' for second file in 'documents'.",
        );
        self::assertSame(
            'doc2.pdf',
            $secondFile->getClientFilename(),
            "Should preserve 'client filename' for second file in 'documents'.",
        );
        self::assertSame(
            'application/pdf',
            $secondFile->getClientMediaType(),
            "Should preserve 'client media type' for second file in 'documents'.",
        );
        self::assertSame(
            1536,
            $secondFile->getSize(),
            "Should preserve 'file size' for second file in 'documents'.",
        );
    }

    public function testCreateFromGlobalsWithNestedStructure(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];

        $files = [
            'nested' => [
                'level1' => [
                    'tmp_name' => $tempPath1,
                    'size' => 1024,
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'nested1.txt',
                    'type' => 'text/plain',
                ],
                'level2' => [
                    'level3' => [
                        'tmp_name' => [$tempPath2],
                        'size' => [512],
                        'error' => [UPLOAD_ERR_OK],
                        'name' => ['nested2.jpg'],
                        'type' => ['image/jpeg'],
                    ],
                ],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $result = $creator->createFromGlobals($files);

        self::assertCount(
            1,
            $result,
            "Should return array with 'nested' key.",
        );
        self::assertArrayHasKey(
            'nested',
            $result,
            "Should preserve 'nested' key from input.",
        );

        $nestedLevel = $result['nested'] ?? null;

        self::assertIsArray(
            $nestedLevel,
            "Should return nested 'array' structure for 'nested' key.",
        );
        self::assertArrayHasKey(
            'level1',
            $nestedLevel,
            "Should have 'level1' in nested structure.",
        );
        self::assertArrayHasKey(
            'level2',
            $nestedLevel,
            "Should have 'level2' in nested structure.",
        );

        $level1File = $nestedLevel['level1'] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $level1File,
            "Should return instance of 'UploadedFileInterface' for 'level1' file.",
        );
        self::assertSame(
            'nested1.txt',
            $level1File->getClientFilename(),
            "Should preserve 'client filename' in 'level1'.",
        );
        self::assertSame(
            1024,
            $level1File->getSize(),
            "Should preserve 'file size' in 'level1'.",
        );

        $level2Structure = $nestedLevel['level2'] ?? null;

        self::assertIsArray(
            $level2Structure,
            "Should return 'array' for 'level2'.",
        );
        self::assertArrayHasKey(
            'level3',
            $level2Structure,
            "Should have 'level3' in 'level2' structure.",
        );

        $level3Array = $level2Structure['level3'] ?? null;

        self::assertIsArray(
            $level3Array,
            "Should return 'array' for 'level3'.",
        );
        self::assertCount(
            1,
            $level3Array,
            "Should have one file in 'level3' array.",
        );

        $level3File = $level3Array[0] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $level3File,
            "Should return instance of 'UploadedFileInterface' for 'level3' file.",
        );
        self::assertSame(
            'nested2.jpg',
            $level3File->getClientFilename(),
            "Should preserve 'client filename' in 'level3'.",
        );
        self::assertSame(
            'image/jpeg',
            $level3File->getClientMediaType(),
            "Should preserve 'client media type' in 'level3'.",
        );
        self::assertSame(
            512,
            $level3File->getSize(),
            "Should preserve 'file size' in 'level3'.",
        );
    }

    public function testCreateFromGlobalsWithSingleFile(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $files = [
            'upload' => [
                'tmp_name' => $tempPath,
                'size' => 1024,
                'error' => UPLOAD_ERR_OK,
                'name' => 'document.pdf',
                'type' => 'application/pdf',
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $result = $creator->createFromGlobals($files);

        self::assertCount(
            1,
            $result,
            "Should return 'array' with one file ('upload' key).",
        );
        self::assertArrayHasKey(
            'upload',
            $result,
            "Should preserve 'upload' key from input.",
        );

        $uploadedFile = $result['upload'] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $uploadedFile,
            "Should return instance of 'UploadedFileInterface' for 'upload' file.",
        );
        self::assertSame(
            'document.pdf',
            $uploadedFile->getClientFilename(),
            "Should preserve 'client filename' from 'upload' file.",
        );
        self::assertSame(
            'application/pdf',
            $uploadedFile->getClientMediaType(),
            "Should preserve 'client media type' from 'upload' file.",
        );
        self::assertSame(
            1024,
            $uploadedFile->getSize(),
            "Should preserve 'file size' from 'upload' file.",
        );
    }

    public function testThrowExceptionInBuildFileTreeRecursion(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    'category' => [
                        'subcategory' => $tempPath,
                    ],
                ],
                'size' => [
                    'category' => [],
                ],
                'error' => [
                    'category' => [
                        'subcategory' => UPLOAD_ERR_OK,
                    ],
                ],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::SIZE_MUST_BE_INTEGER->getMessage('subcategory'),
        );

        $creator->createFromGlobals($files);
    }

    public function testThrowExceptionInBuildFileTreeWithMismatchedArrayStructureError(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    'level1' => [$tempPath],
                ],
                'size' => [
                    'level1' => [1024],
                ],
                'error' => [
                    'level1' => UPLOAD_ERR_OK,
                ],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MISMATCHED_ARRAY_STRUCTURE_ERRORS->getMessage('level1'),
        );

        $creator->createFromGlobals($files);
    }

    public function testThrowExceptionInBuildFileTreeWithMismatchedArrayStructureSize(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    'level1' => [$tempPath],
                ],
                'size' => [
                    'level1' => 1024,
                ],
                'error' => [
                    'level1' => [UPLOAD_ERR_OK],
                ],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MISMATCHED_ARRAY_STRUCTURE_SIZES->getMessage('level1'),
        );

        $creator->createFromGlobals($files);
    }

    public function testThrowExceptionWhenErrorIsNotInteger(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 1024,
            'error' => 'invalid',
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::ERROR_MUST_BE_INTEGER->getMessage('error'));

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowExceptionWhenMissingSize(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'error' => UPLOAD_ERR_OK,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MISSING_REQUIRED_KEY_IN_FILE_SPEC->getMessage('size', 'validateFileSpec()'),
        );

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }
    public function testThrowExceptionWhenMissingTmpNameInFileSpec(): void
    {
        $fileSpec = [
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MISSING_REQUIRED_KEY_IN_FILE_SPEC->getMessage('tmp_name', 'validateFileSpec()'),
        );

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowExceptionWhenNameIsNotArrayOrNullInMultiFileSpec(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [$tempPath1, $tempPath2],
                'size' => [2048, 1536],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'name' => 'not_array', // should be array or 'null' when 'tmp_name' is array
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::INVALID_OPTIONAL_ARRAY_IN_MULTI_SPEC->getMessage('name', 'validateMultiFileSpec()'),
        );

        $creator->createFromGlobals($files);
    }

    public function testThrowExceptionWhenNameIsNotStringOrNull(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
            'name' => 123,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::NAME_MUST_BE_STRING_OR_NULL->getMessage('name'));

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowExceptionWhenSizeIsNotInteger(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 'invalid',
            'error' => UPLOAD_ERR_OK,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::SIZE_MUST_BE_INTEGER->getMessage('size'));

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowExceptionWhenTmpNameIsNotString(): void
    {
        $fileSpec = [
            'tmp_name' => 123,
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::TMP_NAME_MUST_BE_STRING->getMessage('tmp_name'));

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowExceptionWhenTypeIsNotStringOrNull(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
            'type' => 123,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::TYPE_MUST_BE_STRING_OR_NULL->getMessage('type'));

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowExceptionWithMismatchedErrorsArray(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [$tempPath1, $tempPath2],
                'size' => [2048, 1536],
                'error' => [UPLOAD_ERR_OK], // Missing error for second file
            ],
        ];

        $uploadedFileFactory = FactoryHelper::createUploadedFileFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $creator = new UploadedFileCreator($uploadedFileFactory, $streamFactory);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::ERROR_MUST_BE_INTEGER->getMessage('error'));

        $creator->createFromGlobals($files);
    }

    public function testThrowExceptionWithMismatchedSizesArray(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [$tempPath1, $tempPath2],
                'size' => [2048], // Missing size for second file
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            ],
        ];

        $uploadedFileFactory = FactoryHelper::createUploadedFileFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $creator = new UploadedFileCreator($uploadedFileFactory, $streamFactory);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::SIZE_MUST_BE_INTEGER->getMessage('size'));

        $creator->createFromGlobals($files);
    }

    public function testThrowsExceptionWhenMissingError(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $fileSpec = [
            'tmp_name' => $tempPath,
            'size' => 1024,
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MISSING_REQUIRED_KEY_IN_FILE_SPEC->getMessage('error', 'validateFileSpec()'),
        );

        // @phpstan-ignore-next-line
        $creator->createFromArray($fileSpec);
    }

    public function testThrowsExceptionWithMismatchedStructures(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [$tempPath],
                'size' => 'not_array', // Should be array when tmp_name is array
                'error' => [UPLOAD_ERR_OK],
            ],
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MISSING_OR_INVALID_ARRAY_IN_MULTI_SPEC->getMessage('size', 'validateMultiFileSpec()'),
        );

        $creator->createFromGlobals($files);
    }
}
