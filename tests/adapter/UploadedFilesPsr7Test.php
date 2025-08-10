<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use yii\web\UploadedFile;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('adapter')]
#[Group('uploaded-files')]
final class UploadedFilesPsr7Test extends TestCase
{
    public function testReturnMultipleUploadedFilesWithDifferentStructures(): void
    {
        $tmpFile1 = $this->createTmpFile();

        $tmpPathFile1 = stream_get_meta_data($tmpFile1)['uri'];
        $tmpFileSize1 = filesize($tmpPathFile1);

        self::assertIsInt(
            $tmpFileSize1,
            "'filesize' for 'test1.txt' should be an integer.",
        );

        $tmpFile2 = $this->createTmpFile();

        $tmpPathFile2 = stream_get_meta_data($tmpFile2)['uri'];
        $tmpFileSize2 = filesize($tmpPathFile2);

        self::assertIsInt(
            $tmpFileSize2,
            "'filesize' for 'test2.php' should be an integer.",
        );

        $uploadedFiles = [
            'simple1' => FactoryHelper::createUploadedFile(
                'simple1.txt',
                'text/plain',
                $tmpPathFile1,
                size: $tmpFileSize1,
            ),
            'simple2' => FactoryHelper::createUploadedFile(
                'simple2.php',
                'application/x-php',
                $tmpPathFile2,
                size: $tmpFileSize2,
            ),
            'nested' => [
                'level1' => FactoryHelper::createUploadedFile(
                    'nested1.txt',
                    'text/plain',
                    $tmpPathFile1,
                    size: $tmpFileSize1,
                ),
                'level2' => FactoryHelper::createUploadedFile(
                    'nested2.php',
                    'application/x-php',
                    $tmpPathFile2,
                    size: $tmpFileSize2,
                ),
            ],
            'array_files' => [
                FactoryHelper::createUploadedFile(
                    'array1.txt',
                    'text/plain',
                    $tmpPathFile1,
                    size: $tmpFileSize1,
                ),
                FactoryHelper::createUploadedFile(
                    'array2.php',
                    'application/x-php',
                    $tmpPathFile2,
                    size: $tmpFileSize2,
                ),
            ],
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/upload')->withUploadedFiles($uploadedFiles),
        );

        $convertedFiles = $request->getUploadedFiles();

        self::assertCount(
            4,
            $convertedFiles,
            "Should return all '4' top-level items in the 'UploadedFiles' array, matching the original structure.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $convertedFiles['simple1'] ?? null,
            "'simple1' should be an instance of 'UploadedFile', representing a single uploaded file.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $convertedFiles['simple2'] ?? null,
            "'simple2' should be an instance of 'UploadedFile', representing a single uploaded file.",
        );
        self::assertIsArray(
            $convertedFiles['nested'] ?? null,
            "'nested' should be an array, representing a nested structure of uploaded files.",
        );
        self::assertCount(
            2,
            $convertedFiles['nested'],
            "'nested' array should contain exactly '2' items: 'level1' and 'level2'.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $convertedFiles['nested']['level1'] ?? null,
            "'nested['level1']' should be an instance of 'UploadedFile', representing a nested uploaded file.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $convertedFiles['nested']['level2'] ?? null,
            "'nested['level2']' should be an instance of 'UploadedFile', representing a nested uploaded file.",
        );
        self::assertIsArray(
            $convertedFiles['array_files'] ?? null,
            "'array_files' should be an array, representing a list of uploaded files.",
        );
        self::assertCount(
            2,
            $convertedFiles['array_files'],
            "'array_files' should contain exactly '2' items, each representing an uploaded file.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $convertedFiles['array_files'][0] ?? null,
            "'array_files[0]' should be an instance of 'UploadedFile', representing the first file in the array.",
        );
        self::assertInstanceOf(
            UploadedFile::class,
            $convertedFiles['array_files'][1] ?? null,
            "'array_files[1]' should be an instance of 'UploadedFile', representing the second file in the array.",
        );
    }

    public function testReturnUploadedFilesRecursivelyConvertsNestedArrays(): void
    {
        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';
        $size1 = filesize($file1);

        self::assertIsInt(
            $size1,
            "'filesize' for 'test1.txt' should be an integer.",
        );

        $file2 = dirname(__DIR__) . '/support/stub/files/test2.php';
        $size2 = filesize($file2);

        self::assertIsInt(
            $size2,
            "'filesize' for 'test2.php' should be an integer.",
        );

        $uploadedFile1 = FactoryHelper::createUploadedFile('test1.txt', 'text/plain', $file1, size: $size1);
        $uploadedFile2 = FactoryHelper::createUploadedFile('test2.php', 'application/x-php', $file2, size: $size2);

        $deepNestedFiles = [
            'docs' => [
                'sub' => [
                    'file1' => $uploadedFile1,
                    'file2' => $uploadedFile2,
                ],
            ],
        ];

        $psr7Request = FactoryHelper::createRequest('POST', '/upload')->withUploadedFiles($deepNestedFiles);

        $request = new Request();

        $request->setPsr7Request($psr7Request);

        $deepNestedUploadedFiles = $request->getUploadedFiles();

        $expectedUploadedFiles = [
            'file1' => [
                'name' => 'test1.txt',
                'type' => 'text/plain',
                'tempName' => $file1,
                'error' => UPLOAD_ERR_OK,
                'size' => $size1,
            ],
            'file2' => [
                'name' => 'test2.php',
                'type' => 'application/x-php',
                'tempName' => $file2,
                'error' => UPLOAD_ERR_OK,
                'size' => $size2,
            ],
        ];

        foreach ($deepNestedUploadedFiles as $nestedUploadFiles) {
            if (is_array($nestedUploadFiles)) {
                foreach ($nestedUploadFiles as $uploadedFiles) {
                    if (is_array($uploadedFiles)) {
                        foreach ($uploadedFiles as $name => $uploadedFile) {
                            self::assertInstanceOf(
                                UploadedFile::class,
                                $uploadedFile,
                                "Uploaded file '{$name}' should be an instance of '" . UploadedFile::class . "'.",
                            );

                            if (isset($expectedUploadedFiles[$name]) === false) {
                                self::fail("Expected uploaded files should contain the key '{$name}'.");
                            }

                            $this->assertUploadedFileProps($uploadedFile, $expectedUploadedFiles[$name]);
                        }
                    }
                }
            }
        }
    }

    public function testReturnUploadedFilesWhenAdapterIsSet(): void
    {
        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';
        $size1 = filesize($file1);

        self::assertIsInt(
            $size1,
            "'filesize' for 'test1.txt' should be an integer.",
        );

        $file2 = dirname(__DIR__) . '/support/stub/files/test2.php';
        $size2 = filesize($file2);

        self::assertIsInt(
            $size2,
            "'filesize' for 'test2.php' should be an integer.",
        );

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/upload')
                ->withUploadedFiles(
                    [
                        'file1' => FactoryHelper::createUploadedFile(
                            'test1.txt',
                            'text/plain',
                            $file1,
                            size: $size1,
                        ),
                        'file2' => FactoryHelper::createUploadedFile(
                            'test2.php',
                            'application/x-php',
                            $file2,
                            size: $size2,
                        ),
                    ],
                ),
        );

        $uploadedFiles = $request->getUploadedFiles();

        $expectedUploadedFiles = [
            'file1' => [
                'name' => 'test1.txt',
                'type' => 'text/plain',
                'tempName' => $file1,
                'error' => UPLOAD_ERR_OK,
                'size' => $size1,
            ],
            'file2' => [
                'name' => 'test2.php',
                'type' => 'application/x-php',
                'tempName' => $file2,
                'error' => UPLOAD_ERR_OK,
                'size' => $size2,
            ],
        ];

        foreach ($uploadedFiles as $name => $uploadedFile) {
            self::assertInstanceOf(
                UploadedFile::class,
                $uploadedFile,
                "Value for {$name} should be an instance of UploadedFile.",
            );

            if (isset($expectedUploadedFiles[$name]) === false) {
                self::fail("Expected uploaded files should contain the key '{$name}'.");
            }

            $this->assertUploadedFileProps($uploadedFile, $expectedUploadedFiles[$name]);
        }
    }

    public function testReturnUploadedFileWithZeroSizeWhenPsr7FileSizeIsNull(): void
    {
        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/upload')
                ->withUploadedFiles(
                    [
                        'test_file' => FactoryHelper::createUploadedFile(
                            'test1.txt',
                            'text/plain',
                            $file1,
                        ),
                    ],
                ),
        );

        $uploadedFiles = $request->getUploadedFiles();

        self::assertArrayHasKey(
            'test_file',
            $uploadedFiles,
            "Uploaded files array should contain the 'test_file' key.",
        );

        $uploadedFile = $uploadedFiles['test_file'] ?? null;

        self::assertInstanceOf(
            UploadedFile::class,
            $uploadedFile,
            "Value for 'test_file' should be an instance of UploadedFile.",
        );
        $this->assertUploadedFileProps(
            $uploadedFile,
            [
                'error' => UPLOAD_ERR_OK,
                'name' => 'test1.txt',
                'size' => 0,
                'tempName' => $file1,
                'type' => 'text/plain',
            ],
        );
    }

    /**
     * @phpstan-param array{error: int, name: string, size: int, tempName: string, type: string} $expected
     */
    private function assertUploadedFileProps(UploadedFile $uploadedFile, array $expected): void
    {
        self::assertSame(
            $expected['error'],
            $uploadedFile->error,
            "UploadedFile 'error' property should be as expected, got '" . $expected['error'] . "'.",
        );
        self::assertSame(
            $expected['name'],
            $uploadedFile->name,
            "UploadedFile 'name' property should be as expected, got '" . $expected['name'] . "'.",
        );
        self::assertSame(
            $expected['size'],
            $uploadedFile->size,
            "UploadedFile 'size' property should be as expected, got '" . $expected['size'] . "'.",
        );
        self::assertSame(
            $expected['type'],
            $uploadedFile->type,
            "UploadedFile 'type' property should be as expected, got '" . $expected['type'] . "'.",
        );
        self::assertSame(
            $expected['tempName'],
            $uploadedFile->tempName,
            "UploadedFile 'tempName' property should be as expected, got '" . $expected['tempName'] . "'.",
        );

        $runtimePath = dirname(__DIR__, 2) . '/runtime';

        self::assertTrue(
            $uploadedFile->saveAs("{$runtimePath}/{$uploadedFile->name}", false),
            "UploadedFile '{$uploadedFile->name}' should be saved to the runtime directory successfully.",
        );
        self::assertFileExists(
            "{$runtimePath}/{$uploadedFile->name}",
            "UploadedFile '{$uploadedFile->name}' should exist in the runtime directory after saving.",
        );

        unlink("{$runtimePath}/{$uploadedFile->name}");
    }
}
