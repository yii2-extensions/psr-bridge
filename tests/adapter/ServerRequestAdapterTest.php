<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use Psr\Http\Message\ServerRequestInterface;
use yii\base\{InvalidConfigException};
use yii\web\{UploadedFile};
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\provider\RequestProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function dirname;
use function filesize;
use function is_array;
use function stream_get_meta_data;

#[Group('http')]
final class ServerRequestAdapterTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnBodyParamsWhenPsr7RequestHasFormData(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'key1' => 'value1',
                    'key2' => 'value2',
                ],
            ),
        );

        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            'Body parameters should be returned as an array when PSR-7 request contains form data.',
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' when present in the PSR-7 request.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value from the PSR-7 request.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' when present in the PSR-7 request.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value from the PSR-7 request.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnBodyParamsWithMethodParamRemoved(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'key1' => 'value1',
                    'key2' => 'value2',
                    '_method' => 'PUT',
                ],
            ),
        );

        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            'Body parameters should be returned as an array when method parameter is present.',
        );
        self::assertArrayNotHasKey(
            '_method',
            $bodyParams,
            "Method parameter '_method' should be removed from body parameters.",
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' after method parameter removal.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value after method parameter removal.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' after method parameter removal.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value after method parameter removal.",
        );
    }

    public function testReturnEmptyQueryParamsWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/products'),
        );

        self::assertEmpty(
            $request->getQueryParams(),
            'Query parameters should be empty when PSR-7 request has no query string.',
        );
    }

    public function testReturnEmptyQueryStringWhenAdapterIsSetWithNoQuery(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertEmpty(
            $request->getQueryString(),
            'Query string should be empty when no query parameters are present.',
        );
    }

    public function testReturnHttpMethodFromAdapterWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test'),
        );

        self::assertSame(
            'POST',
            $request->getMethod(),
            'HTTP method should be returned from adapter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithBodyOverrideAndLowerCaseMethodsWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'post',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    '_method' => 'put',
                    'data' => 'value',
                ],
            ),
        );

        self::assertSame(
            'PUT',
            $request->getMethod(),
            'HTTP method should be overridden by body parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithBodyOverrideWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    '_method' => 'PUT',
                    'data' => 'value',
                ],
            ),
        );

        self::assertSame(
            'PUT',
            $request->getMethod(),
            'HTTP method should be overridden by body parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithCustomMethodParamWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->methodParam = 'custom_method';

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/test',
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'custom_method' => 'PATCH',
                    'data' => 'value',
                ],
            ),
        );

        self::assertSame(
            'PATCH',
            $request->getMethod(),
            'HTTP method should be overridden by custom method parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithHeaderOverrideWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/test', ['X-Http-Method-Override' => 'DELETE']),
        );

        self::assertSame(
            'DELETE',
            $request->getMethod(),
            'HTTP method should be overridden by header when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithoutOverrideWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertSame(
            'GET',
            $request->getMethod(),
            'HTTP method should return original method when no override is present and adapter is set.',
        );
    }

    public function testReturnMultipleUploadedFilesWithDifferentStructures(): void
    {
        $tmpFile1 = $this->createTmpFile();

        $tmpPathFile1 = stream_get_meta_data($tmpFile1)['uri'];
        $tmpFileSize1 = filesize($tmpPathFile1);

        $tmpFile2 = $this->createTmpFile();

        $tmpPathFile2 = stream_get_meta_data($tmpFile2)['uri'];
        $tmpFileSize2 = filesize($tmpPathFile2);

        self::assertNotFalse(
            $tmpFileSize1,
            "'filesize' for 'test1.txt' should not be 'false'.",
        );
        self::assertNotFalse(
            $tmpFileSize2,
            "'filesize' for 'test2.php' should not be 'false'.",
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

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParentGetParsedBodyWhenAdapterIsNull(): void
    {
        $request = new Request();

        // ensure adapter is `null` (default state)
        $request->reset();

        self::assertEmpty(
            $request->getParsedBody(),
            "Parsed body should return empty array when PSR-7 request has no parsed body and adapter is 'null'.",
        );
    }

    public function testReturnParentHttpMethodWhenAdapterIsNull(): void
    {
        $request = new Request();

        // ensure adapter is `null` (default state)
        $request->reset();

        self::assertNotEmpty($request->getMethod(), "HTTP method should not be empty when adapter is 'null'.");
    }

    public function testReturnParentQueryParamsWhenAdapterIsNull(): void
    {
        $request = new Request();

        // ensure adapter is `null` (default state)
        $request->reset();

        self::assertEmpty(
            $request->getQueryParams(),
            'Query parameters should be empty when PSR-7 request has no query string.',
        );
    }

    public function testReturnParentQueryStringWhenAdapterIsNull(): void
    {
        $request = new Request();

        self::assertEmpty(
            $request->getQueryString(),
            "Query string should be empty when PSR-7 request has no query string and adapter is 'null'.",
        );
    }

    public function testReturnParentRawBodyWhenAdapterIsNull(): void
    {
        $request = new Request();

        // ensure adapter is `null` (default state)
        $request->reset();

        self::assertEmpty(
            $request->getRawBody(),
            "Raw body should return empty string when PSR-7 request has no body content and adapter is 'null'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParsedBodyArrayWhenAdapterIsSet(): void
    {
        $parsedBodyData = [
            'name' => 'John',
            'email' => 'john@example.com',
            'age' => 30,
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'POST',
                '/api/users',
                ['Content-Type' => 'application/json'],
                $parsedBodyData,
            ),
        );

        $result = $request->getParsedBody();

        self::assertIsArray(
            $result,
            'Parsed body should return an array when PSR-7 request contains array data.',
        );
        self::assertSame(
            $parsedBodyData,
            $result,
            'Parsed body should match the original data from PSR-7 request.',
        );
        self::assertArrayHasKey(
            'name',
            $result,
            "Parsed body should contain the 'name' field.",
        );
        self::assertSame(
            'John',
            $result['name'],
            "'name' field should match the expected value.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParsedBodyNullWhenAdapterIsSetWithNullBody(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/api/users'),
        );

        self::assertNull(
            $request->getParsedBody(),
            "Parsed body should return 'null' when PSR-7 request has no parsed body.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnParsedBodyObjectWhenAdapterIsSet(): void
    {
        $parsedBodyObject = (object) [
            'title' => 'Test Article',
            'content' => 'Article content',
        ];

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest(
                'PUT',
                '/api/articles/1',
                ['Content-Type' => 'application/json'],
                $parsedBodyObject,
            ),
        );

        $result = $request->getParsedBody();

        self::assertIsObject(
            $result,
            'Parsed body should return an object when PSR-7 request contains object data.',
        );
        self::assertSame(
            $parsedBodyObject,
            $result,
            'Parsed body object should match the original object from PSR-7 request.',
        );
        self::assertSame(
            'Test Article',
            $result->title,
            "Object 'title' property should match the expected value.",
        );
        self::assertSame(
            'Article content',
            $result->content,
            "Object 'content' property should match the expected value.",
        );
    }

    /**
     * @throws InvalidConfigException
     */
    public function testReturnPsr7RequestInstanceWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertInstanceOf(
            ServerRequestInterface::class,
            $request->getPsr7Request(),
            "'getPsr7Request()' should return a '" . ServerRequestInterface::class . "' instance when the PSR-7 " .
            'adapter is set.',
        );
    }

    public function testReturnQueryParamsWhenAdapterIsSet(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/products?category=electronics&price=500&sort=desc'),
        );

        $queryParams = $request->getQueryParams();

        self::assertArrayHasKey(
            'category',
            $queryParams,
            "Query parameters should contain the key 'category' when present in the PSR-7 request URI.",
        );
        self::assertSame(
            'electronics',
            $queryParams['category'] ?? null,
            "Query parameter 'category' should have the expected value from the PSR-7 request URI.",
        );
        self::assertArrayHasKey(
            'price',
            $queryParams,
            "Query parameters should contain the key 'price' when present in the PSR-7 request URI.",
        );
        self::assertSame(
            '500',
            $queryParams['price'] ?? null,
            "Query parameter 'price' should have the expected value from the PSR-7 request URI.",
        );
        self::assertArrayHasKey(
            'sort',
            $queryParams,
            "Query parameters should contain the key 'sort' when present in the PSR-7 request URI.",
        );
        self::assertSame(
            'desc',
            $queryParams['sort'] ?? null,
            "Query parameter 'sort' should have the expected value from the PSR-7 request URI.",
        );
    }

    /**
     * @phpstan-param string $expectedString
     */
    #[DataProviderExternal(RequestProvider::class, 'getQueryString')]
    public function testReturnQueryStringWhenAdapterIsSet(string $queryString, string $expectedString): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', "/test?{$queryString}"),
        );

        self::assertSame(
            $expectedString,
            $request->getQueryString(),
            "Query string should match the expected value for: '{$queryString}'.",
        );
    }

    public function testReturnRawBodyFromAdapterWhenAdapterIsSet(): void
    {
        $bodyContent = '{"name":"John","email":"john@example.com","message":"Hello World"}';

        $stream = FactoryHelper::createStream();

        $stream->write($bodyContent);

        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('POST', '/api/contact')->withBody($stream),
        );

        self::assertSame(
            $bodyContent,
            $request->getRawBody(),
            'Raw body should return the exact content from the PSR-7 request body when adapter is set.',
        );
    }

    public function testReturnRawBodyWhenAdapterIsSetWithEmptyBody(): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertEmpty(
            $request->getRawBody(),
            'Raw body should return empty string when PSR-7 request has no body content.',
        );
    }

    public function testReturnUploadedFilesRecursivelyConvertsNestedArrays(): void
    {
        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';
        $file2 = dirname(__DIR__) . '/support/stub/files/test2.php';
        $size1 = filesize($file1);
        $size2 = filesize($file2);

        self::assertNotFalse(
            $size1,
            "'filesize' for 'test1.txt' should not be 'false'.",
        );
        self::assertNotFalse(
            $size2,
            "'filesize' for 'test2.php' should not be 'false'.",
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

        $expectedUpdloadedFiles = [
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

        $runtimePath = dirname(__DIR__, 2) . '/runtime';

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
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['name'] ?? null,
                                $uploadedFile->name,
                                "Uploaded file '{$name}' should have the expected client filename.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['type'] ?? null,
                                $uploadedFile->type,
                                "Uploaded file '{$name}' should have the expected client media type.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['tempName'] ?? null,
                                $uploadedFile->tempName,
                                "Uploaded file '{$name}' should have the expected temporary name.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['error'] ?? null,
                                $uploadedFile->error,
                                "Uploaded file '{$name}' should have the expected error code.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['size'] ?? null,
                                $uploadedFile->size,
                                "Uploaded file '{$name}' should have the expected size.",
                            );
                            self::assertTrue(
                                $uploadedFile->saveAs("{$runtimePath}/{$uploadedFile->name}", false),
                                "Uploaded file '{$uploadedFile->name}' should be saved to the runtime directory " .
                                'successfully.',
                            );
                            self::assertFileExists(
                                "{$runtimePath}/{$uploadedFile->name}",
                                "Uploaded file '{$uploadedFile->name}' should exist in the runtime directory after " .
                                'saving.',
                            );
                        }
                    }
                }
            }
        }
    }

    public function testReturnUploadedFilesWhenAdapterIsSet(): void
    {
        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';
        $file2 = dirname(__DIR__) . '/support/stub/files/test2.php';
        $size1 = filesize($file1);
        $size2 = filesize($file2);

        self::assertNotFalse(
            $size1,
            "'filesize' for 'test1.txt' should not be 'false'.",
        );
        self::assertNotFalse(
            $size2,
            "'filesize' for 'test2.php' should not be 'false'.",
        );

        $uploadedFile1 = FactoryHelper::createUploadedFile('test1.txt', 'text/plain', $file1, size: $size1);
        $uploadedFile2 = FactoryHelper::createUploadedFile('test2.php', 'application/x-php', $file2, size: $size2);
        $psr7Request = FactoryHelper::createRequest('POST', '/upload');

        $psr7Request = $psr7Request->withUploadedFiles(
            [
                'file1' => $uploadedFile1,
                'file2' => $uploadedFile2,
            ],
        );

        $request = new Request();

        $request->setPsr7Request($psr7Request);

        $uploadedFiles = $request->getUploadedFiles();

        $expectedNames = [
            'file1',
            'file2',
        ];
        $expectedUpdloadedFiles = [
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

        $runtimePath = dirname(__DIR__, 2) . '/runtime';

        foreach ($uploadedFiles as $name => $uploadedFile) {
            self::assertContains(
                $name,
                $expectedNames,
                "Uploaded file name '{$name}' should be in the expected names list.",
            );
            self::assertInstanceOf(
                UploadedFile::class,
                $uploadedFile,
                "Uploaded file '{$name}' should be an instance of '" . UploadedFile::class . "'.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['name'] ?? null,
                $uploadedFile->name,
                "Uploaded file '{$name}' should have the expected client filename.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['type'] ?? null,
                $uploadedFile->type,
                "Uploaded file '{$name}' should have the expected client media type.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['tempName'] ?? null,
                $uploadedFile->tempName,
                "Uploaded file '{$name}' should have the expected temporary name.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['error'] ?? null,
                $uploadedFile->error,
                "Uploaded file '{$name}' should have the expected error code.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['size'] ?? null,
                $uploadedFile->size,
                "Uploaded file '{$name}' should have the expected size.",
            );
            self::assertTrue(
                $uploadedFile->saveAs("{$runtimePath}/{$uploadedFile->name}", false),
                "Uploaded file '{$uploadedFile->name}' should be saved to the runtime directory successfully.",
            );
            self::assertFileExists(
                "{$runtimePath}/{$uploadedFile->name}",
                "Uploaded file '{$uploadedFile->name}' should exist in the runtime directory after saving.",
            );
        }
    }

    public function testReturnUploadedFileWithZeroSizeWhenPsr7FileSizeIsNull(): void
    {
        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';

        $uploadedFile1 = FactoryHelper::createUploadedFile('test1.txt', 'text/plain', $file1);
        $psr7Request = FactoryHelper::createRequest('POST', '/upload');

        $psr7Request = $psr7Request->withUploadedFiles(['test_file' => $uploadedFile1]);

        $request = new Request();

        $request->setPsr7Request($psr7Request);

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
            "Value for 'test_file' should be an instance of 'UploadedFile'.",
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->error,
            "'UploadedFile' 'error' property should be 'UPLOAD_ERR_OK'.",
        );
        self::assertSame(
            'test1.txt',
            $uploadedFile->name,
            "'UploadedFile' 'name' property should match the original filename.",
        );
        self::assertSame(
            0,
            $uploadedFile->size,
            "'UploadedFile' 'size' should default to 0 when PSR-7 file 'getSize()' returns 'null'.",
        );
        self::assertSame(
            $file1,
            $uploadedFile->tempName,
            "'UploadedFile' 'tempName' should match the original file path.",
        );
        self::assertSame(
            'text/plain',
            $uploadedFile->type,
            "'UploadedFile' 'type' should match the original MIME type.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(RequestProvider::class, 'getUrl')]
    public function testReturnUrlFromAdapterWhenAdapterIsSet(string $url, string $expectedUrl): void
    {
        $request = new Request();

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', $url),
        );

        self::assertSame(
            $expectedUrl,
            $request->getUrl(),
            "URL should match the expected value for: {$url}.",
        );
    }
}
