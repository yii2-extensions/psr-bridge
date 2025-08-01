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

use const UPLOAD_ERR_OK;

#[Group('http')]
#[Group('creator')]
final class UploadedFileCreatorTest extends TestCase
{
    public function testCreateFromArrayWithMinimalFileSpec(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tmpFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];

        $tmpFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tmpFile2)['uri'];

        $tmpFile3 = $this->createTmpFile();
        $tmpPath3 = stream_get_meta_data($tmpFile3)['uri'];

        $files = [
            'single' => [
                'tmp_name' => $tmpPath1,
                'size' => 1024,
                'error' => UPLOAD_ERR_OK,
                'name' => 'single.txt',
                'type' => 'text/plain',
            ],
            'multiple' => [
                'tmp_name' => [
                    $tmpPath2,
                    $tmpPath3,
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
        $tmpFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];

        $tmpFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tmpFile2)['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    $tmpPath1,
                    $tmpPath2,
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
        $tmpFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];

        $tmpFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tmpFile2)['uri'];

        $files = [
            'nested' => [
                'level1' => [
                    'tmp_name' => $tmpPath1,
                    'size' => 1024,
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'nested1.txt',
                    'type' => 'text/plain',
                ],
                'level2' => [
                    'level3' => [
                        'tmp_name' => [$tmpPath2],
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $files = [
            'upload' => [
                'tmp_name' => $tmpPath,
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

    public function testDepthParameterStartsAtZeroNotOne(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $tenLevelFiles = $this->createDeeplyNestedFileStructure($tmpPath, 11);

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        // this should succeed without throwing an exception if 'depth' starts at '0'
        $result = $creator->createFromGlobals($tenLevelFiles);

        // navigate to the deepest file to verify it was processed correctly
        $finalFile = $this->navigateToDeepestFile($result, 11);

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $finalFile,
            "Should successfully process exactly '10' levels when 'depth' parameter starts at '0', not '1'.",
        );
        self::assertSame(
            'deep_file_level_11.txt',
            $finalFile->getClientFilename(),
            "Should preserve 'client filename' at exactly '10' levels when 'depth' starts at '0'.",
        );
        self::assertSame(
            1024,
            $finalFile->getSize(),
            "Should preserve 'file size' at exactly '10' levels when 'depth' starts at '0'.",
        );
    }

    public function testSuccessWithMaximumAllowedRecursionDepth(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $maxDepthFiles = $this->createDeeplyNestedFileStructure($tmpPath, 10);

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        // this should succeed because depth starts at 0, reaching exactly 'depth' = '10'
        $result = $creator->createFromGlobals($maxDepthFiles);

        self::assertArrayHasKey(
            'deep',
            $result,
            "Should successfully process structure that reaches exactly 'depth' = '10'.",
        );

        $finalFile = $this->navigateToDeepestFile($result, 10);

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $finalFile,
            "Should successfully process file at maximum allowed 'depth' of '10' levels.",
        );
        self::assertSame(
            'deep_file_level_10.txt',
            $finalFile->getClientFilename(),
            "Should preserve 'client filename' at maximum 'depth'.",
        );
        self::assertSame(
            1024,
            $finalFile->getSize(),
            "Should preserve 'file size' at maximum 'depth'.",
        );
    }

    public function testThrowExceptionInBuildFileTreeRecursion(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    'category' => [
                        'subcategory' => $tmpPath,
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    'level1' => [$tmpPath],
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $files = [
            'documents' => [
                'tmp_name' => [
                    'level1' => [$tmpPath],
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tempFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tempFile1)['uri'];

        $tempFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tempFile2)['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [
                    $tmpPath1,
                    $tmpPath2,
                ],
                'size' => [
                    2048,
                    1536,
                ],
                'error' => [
                    UPLOAD_ERR_OK,
                    UPLOAD_ERR_OK,
                ],
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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

    public function testThrowExceptionWhenRecursionDepthExceeded(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $deeplyNestedFiles = $this->createDeeplyNestedFileStructure($tmpPath, 15);

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum nesting depth exceeded for file uploads');

        $creator->createFromGlobals($deeplyNestedFiles);
    }

    public function testThrowExceptionWhenSizeIsNotInteger(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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

    public function testThrowExceptionWhenTmpFileDoesNotExist(): void
    {
        $nonExistentPath = '/tmp/non_existent_file_' . uniqid();

        $fileSpec = [
            'tmp_name' => $nonExistentPath,
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
            'name' => 'test.txt',
            'type' => 'text/plain',
        ];

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::FAILED_CREATE_STREAM_FROM_TMP_FILE->getMessage($nonExistentPath));

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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tmpFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];

        $tmpFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tmpFile2)['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [$tmpPath1, $tmpPath2],
                'size' => [
                    2048,
                    1536,
                ],
                'error' => [UPLOAD_ERR_OK], // missing 'error code' for second file
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
        $tmpFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];

        $tmpFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tmpFile2)['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [
                    $tmpPath1,
                    $tmpPath2,
                ],
                'size' => [2048], // missing 'size' for second file
                'error' => [
                    UPLOAD_ERR_OK,
                    UPLOAD_ERR_OK,
                ],
            ],
        ];

        $uploadedFileFactory = FactoryHelper::createUploadedFileFactory();
        $streamFactory = FactoryHelper::createStreamFactory();

        $creator = new UploadedFileCreator($uploadedFileFactory, $streamFactory);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Message::SIZE_MUST_BE_INTEGER->getMessage('size'));

        $creator->createFromGlobals($files);
    }

    public function testThrowsExceptionForDepthValidation(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $elevenLevelFiles = $this->createDeeplyNestedFileStructure($tmpPath, 12);

        $creator = new UploadedFileCreator(
            FactoryHelper::createUploadedFileFactory(),
            FactoryHelper::createStreamFactory(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            Message::MAXIMUM_NESTING_DEPTH_EXCEEDED->getMessage(11),
        );

        $creator->createFromGlobals($elevenLevelFiles);
    }

    public function testThrowsExceptionWhenMissingError(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $fileSpec = [
            'tmp_name' => $tmpPath,
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $files = [
            'invalid' => [
                'tmp_name' => [$tmpPath],
                'size' => 'not_array', // should be array when 'tmp_name' is array
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

    /**
     * Create deeply nested file structures for testing recursion limits.
     *
     * @param string $tmpPath Path to temporary file.
     * @param int $depth Desired nesting depth.
     *
     * @return array Nested file structure.
     *
     * @phpstan-return array<array<mixed>|UploadedFileInterface>
     */
    private function createDeeplyNestedFileStructure(string $tmpPath, int $depth): array
    {
        return [
            'deep' => $this->createSingleDeeplyNestedFileSpec($tmpPath, $depth),
        ];
    }

    /**
     * Create a single deeply nested file specification.
     *
     * @param string $tmpPath Path to temporary file.
     * @param int $depth Desired nesting depth.
     *
     * @return array Nested file specification matching PHP $_FILES structure.
     *
     * @phpstan-return array<
     *   string,
     *   array<string, array<string, array<string, array<string, int|string>|int|string>|string>|int|string>|int|string
     * >
     */
    private function createSingleDeeplyNestedFileSpec(string $tmpPath, int $depth): array
    {
        $tmpName = $tmpPath;
        $size = 1024;
        $error = UPLOAD_ERR_OK;
        $name = "deep_file_level_{$depth}.txt";
        $type = 'text/plain';

        for ($i = $depth; $i > 0; $i--) {
            $tmpName = ["level_{$i}" => $tmpName];
            $size = ["level_{$i}" => $size];
            $error = ["level_{$i}" => $error];
            $name = ["level_{$i}" => $name];
            $type = ["level_{$i}" => $type];
        }

        return [
            'tmp_name' => $tmpName,
            'size' => $size,
            'error' => $error,
            'name' => $name,
            'type' => $type,
        ];
    }

    /**
     * Navigate to the deepest file in a nested structure.
     *
     * @param array $result Processed file structure.
     * @param int $expectedDepth Expected depth to navigate.
     * @param string $rootKey Root key to start navigation from.
     *
     * @return mixed Deepest file found.
     *
     * @phpstan-param array<array<mixed>|UploadedFileInterface> $result
     */
    private function navigateToDeepestFile(array $result, int $expectedDepth, string $rootKey = 'deep'): mixed
    {
        $current = $result[$rootKey] ?? null;

        for ($i = 1; $i <= $expectedDepth; $i++) {
            $levelKey = "level_{$i}";

            self::assertIsArray(
                $current,
                "Should be array at level {$i}",
            );
            self::assertArrayHasKey(
                $levelKey,
                $current,
                "Should have '{$levelKey}' key at level {$i}",
            );

            $current = $current[$levelKey] ?? null;
        }

        return $current;
    }
}
