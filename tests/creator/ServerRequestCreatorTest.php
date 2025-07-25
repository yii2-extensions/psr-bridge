<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\creator;

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use yii2\extensions\psrbridge\creator\ServerRequestCreator;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function stream_get_meta_data;

#[Group('http')]
#[Group('creator')]
final class ServerRequestCreatorTest extends TestCase
{
    public function testCreateFromGlobalsWithBodyStream(): void
    {
        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $body = $request->getBody();

        self::assertTrue($body->isReadable(), "'Body' stream should be readable.");
    }

    public function testCreateFromGlobalsWithBodyStreamException(): void
    {
        $failingStreamFactory = new class implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                throw new RuntimeException('Stream creation failed.');
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new RuntimeException('Stream creation failed.');
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                throw new RuntimeException('Stream creation failed.');
            }
        };

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test';

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            $failingStreamFactory,
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertSame(
            'POST',
            $request->getMethod(),
            "Should preserve request 'method' when body stream creation fails.",
        );
        self::assertSame(
            '/test',
            (string) $request->getUri(),
            "Should preserve request 'URI' when body stream creation fails.",
        );
        self::assertInstanceOf(
            StreamInterface::class,
            $request->getBody(),
            "Should return a valid 'body' stream even if creation fails.",
        );
    }

    public function testCreateFromGlobalsWithComplexScenario(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $_SERVER = [
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/api/profile/update?force=true',
            'HTTP_AUTHORIZATION' => 'Bearer token123',
        ];
        $_COOKIE['session'] = 'sess_abc123';
        $_POST = [
            'name' => 'John Doe',
            'bio' => 'Software developer',
        ];
        $_GET['force'] = 'true';
        $_FILES = [
            'photo' => [
                'error' => UPLOAD_ERR_OK,
                'name' => 'avatar.png',
                'size' => 1500,
                'tmp_name' => $tempPath,
                'type' => 'image/png',
            ],
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertSame(
            'PUT',
            $request->getMethod(),
            "Should preserve 'PUT' method from complex scenario.",
        );
        self::assertSame(
            '/api/profile/update?force=true',
            (string) $request->getUri(),
            "Should preserve complex 'URI' from scenario.",
        );
        self::assertSame(
            'Bearer token123',
            $request->getServerParams()['HTTP_AUTHORIZATION'] ?? '',
            "Should preserve 'HTTP_AUTHORIZATION' header in 'server params'.",
        );
        self::assertSame(
            'sess_abc123',
            $request->getCookieParams()['session'] ?? '',
            "Should preserve 'session' cookie.",
        );

        $parsedBody = $request->getParsedBody();

        self::assertIsArray(
            $parsedBody,
            "'Parsed body' should be an array in complex scenario.",
        );
        self::assertSame(
            'John Doe',
            $parsedBody['name'] ?? '',
            "Should preserve 'name' from 'POST' data.",
        );
        self::assertSame(
            'Software developer',
            $parsedBody['bio'] ?? '',
            "Should preserve 'bio' from 'POST' data.",
        );
        self::assertSame(
            'true',
            $request->getQueryParams()['force'] ?? '',
            "Should preserve 'force' query parameter.",
        );

        $uploadedFiles = $request->getUploadedFiles();

        $photoFile = $uploadedFiles['photo'] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $photoFile,
            "Should have 'photo' file as instance of 'UploadedFileInterface'.",
        );
        self::assertSame(
            'avatar.png',
            $photoFile->getClientFilename(),
            "Should preserve 'photo' filename in complex scenario.",
        );
        self::assertSame(
            'image/png',
            $photoFile->getClientMediaType(),
            "Should preserve 'photo' media type in complex scenario.",
        );
        self::assertSame(
            1500,
            $photoFile->getSize(),
            "Should preserve 'photo' size in complex scenario.",
        );
    }

    public function testCreateFromGlobalsWithCookieParams(): void
    {
        $_COOKIE = [
            'language' => 'en',
            'session_id' => 'abc123',
            'theme' => 'dark',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $cookieParams = $request->getCookieParams();

        self::assertCount(
            3,
            $cookieParams,
            "Should have all cookies from '\$_COOKIE'.",
        );
        self::assertSame(
            'en',
            $cookieParams['language'] ?? '',
            "Should preserve 'language' cookie value.",
        );
        self::assertSame(
            'abc123',
            $cookieParams['session_id'] ?? '',
            "Should preserve 'session_id' cookie value.",
        );
        self::assertSame(
            'dark',
            $cookieParams['theme'] ?? '',
            "Should preserve 'theme' cookie value.",
        );
    }

    public function testCreateFromGlobalsWithDefaultValues(): void
    {
        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertSame(
            'GET',
            $request->getMethod(),
            "Should use default 'GET' method when 'REQUEST_METHOD' is not set.",
        );
        self::assertSame(
            '/',
            (string) $request->getUri(),
            "Should use default root 'URI' when 'REQUEST_URI' is not set.",
        );
        self::assertEmpty(
            $request->getCookieParams(),
            "Should have empty 'cookie params' when '\$_COOKIE' is empty.",
        );
        self::assertEmpty(
            $request->getParsedBody(),
            "Should have empty 'parsed body' when '\$_POST' is empty.",
        );
        self::assertEmpty(
            $request->getQueryParams(),
            "Should have empty 'query params' when '\$_GET' is empty.",
        );
        self::assertEmpty(
            $request->getUploadedFiles(),
            "Should have empty 'uploaded files' when '\$_FILES' is empty.",
        );
    }

    public function testCreateFromGlobalsWithInvalidRequestMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = null;

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertSame(
            'GET',
            $request->getMethod(),
            "Should default to 'GET' when 'REQUEST_METHOD' is not a string.",
        );
    }

    public function testCreateFromGlobalsWithInvalidRequestUri(): void
    {
        $_SERVER['REQUEST_URI'] = null;

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertSame(
            '/',
            (string) $request->getUri(),
            "Should default to root 'URI' when 'REQUEST_URI' is not a string.",
        );
    }

    public function testCreateFromGlobalsWithMultipleUploadedFiles(): void
    {
        $tempPath1 = stream_get_meta_data($this->getTmpFile1())['uri'];
        $tempPath2 = stream_get_meta_data($this->getTmpFile2())['uri'];

        $_FILES = [
            'documents' => [
                'name' => [
                    'doc1.pdf',
                    'doc2.txt',
                ],
                'type' => [
                    'application/pdf',
                    'text/plain',
                ],
                'tmp_name' => [
                    $tempPath1,
                    $tempPath2,
                ],
                'error' => [
                    \UPLOAD_ERR_OK,
                    \UPLOAD_ERR_OK,
                ],
                'size' => [
                    2048,
                    512,
                ],
            ],
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $uploadedFiles = $request->getUploadedFiles();

        self::assertCount(
            1,
            $uploadedFiles,
            "Should have 'documents' array in 'uploaded files'.",
        );
        self::assertArrayHasKey(
            'documents',
            $uploadedFiles,
            "Should have 'documents' key in 'uploaded files'.",
        );

        $documentsArray = $uploadedFiles['documents'] ?? null;

        self::assertIsArray(
            $documentsArray,
            "Should have array of 'uploaded files' for multiple files.",
        );
        self::assertCount(
            2,
            $documentsArray,
            "Should have two 'uploaded files' in 'documents' array.",
        );

        $firstFile = $documentsArray[0] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $firstFile,
            "First file should be an instance of 'UploadedFileInterface'.",
        );
        self::assertSame(
            'doc1.pdf',
            $firstFile->getClientFilename(),
            "Should preserve first file 'name'.",
        );
        self::assertSame(
            'application/pdf',
            $firstFile->getClientMediaType(),
            "Should preserve first file 'media type'.",
        );
        self::assertSame(
            2048,
            $firstFile->getSize(),
            "Should preserve first file 'size'.",
        );

        $secondFile = $documentsArray[1] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $secondFile,
            "Second file should be an instance of 'UploadedFileInterface'.",
        );
        self::assertSame(
            'doc2.txt',
            $secondFile->getClientFilename(),
            "Should preserve second file 'name'.",
        );
        self::assertSame(
            'text/plain',
            $secondFile->getClientMediaType(),
            "Should preserve second file 'media type'.",
        );
        self::assertSame(
            512,
            $secondFile->getSize(),
            "Should preserve second file 'size'.",
        );
    }

    public function testCreateFromGlobalsWithNonStringServerValues(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 123,
            'REQUEST_URI' => [],
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertSame(
            'GET',
            $request->getMethod(),
            "Should use default 'GET' method when 'REQUEST_METHOD' is not string.",
        );
        self::assertSame(
            '/',
            (string) $request->getUri(),
            "Should use default root 'URI' when 'REQUEST_URI' is not string.",
        );
    }

    public function testCreateFromGlobalsWithNoUploadedFiles(): void
    {
        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertEmpty($request->getUploadedFiles(), "Should have empty 'uploaded files' when '\$_FILES' is empty.");
    }

    public function testCreateFromGlobalsWithParsedBody(): void
    {
        $_POST = [
            'age' => '30',
            'email' => 'john@example.com',
            'username' => 'john_doe',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $parsedBody = $request->getParsedBody();

        self::assertIsArray($parsedBody, "Should return 'parsed body' as array when '\$_POST' contains data.");
        self::assertCount(3, $parsedBody, "Should have all fields from '\$_POST'.");
        self::assertSame('30', $parsedBody['age'] ?? '', "Should preserve 'age' from '\$_POST'.");
        self::assertSame('john@example.com', $parsedBody['email'] ?? '', "Should preserve 'email' from '\$_POST'.");
        self::assertSame('john_doe', $parsedBody['username'] ?? '', "Should preserve 'username' from '\$_POST'.");
    }

    public function testCreateFromGlobalsWithQueryParams(): void
    {
        $_GET = [
            'category' => 'electronics',
            'price_max' => '500',
            'price_min' => '100',
            'sort' => 'price_asc',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $queryParams = $request->getQueryParams();

        self::assertCount(4, $queryParams, "Should have all 'query parameters' from '\$_GET'.");
        self::assertSame('electronics', $queryParams['category'] ?? '', "Should preserve 'category' query parameter.");
        self::assertSame('500', $queryParams['price_max'] ?? '', "Should preserve 'price_max' query parameter.");
        self::assertSame('100', $queryParams['price_min'] ?? '', "Should preserve 'price_min' query parameter.");
        self::assertSame('price_asc', $queryParams['sort'] ?? '', "Should preserve 'sort' query parameter.");
    }

    public function testCreateFromGlobalsWithServerValues(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/users?page=1',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();

        self::assertArrayHasKey(
            'HTTP_HOST',
            $request->getServerParams(),
            "Should include 'server parameters' from '\$_SERVER'.",
        );
        self::assertSame(
            'example.com',
            $request->getServerParams()['HTTP_HOST'] ?? '',
            "Should preserve 'server parameter' values from '\$_SERVER'.",
        );
        self::assertSame(
            'on',
            $request->getServerParams()['HTTPS'] ?? '',
            "Should preserve 'HTTPS' server parameter from '\$_SERVER'.",
        );
        self::assertSame(
            'POST',
            $request->getMethod(),
            "Should use 'REQUEST_METHOD' from '\$_SERVER' when set.",
        );
        self::assertSame(
            '/api/users?page=1',
            (string) $request->getUri(),
            "Should use 'REQUEST_URI' from '\$_SERVER' when set.",
        );
    }

    public function testCreateFromGlobalsWithSingleUploadedFile(): void
    {
        $tempPath = stream_get_meta_data($this->getTmpFile1())['uri'];

        $_FILES['avatar'] = [
            'name' => 'profile.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tempPath,
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $uploadedFiles = $request->getUploadedFiles();

        self::assertCount(
            1,
            $uploadedFiles,
            "Should have one 'uploaded file' when '\$_FILES' contains single file.",
        );
        self::assertArrayHasKey(
            'avatar',
            $uploadedFiles,
            "Should have 'avatar' key in 'uploaded files'.",
        );

        $uploadedFile = $uploadedFiles['avatar'] ?? null;

        self::assertInstanceOf(
            UploadedFileInterface::class,
            $uploadedFile,
            "Should have avatar file as \'UploadedFileInterface\' instance.",
        );

        self::assertSame(
            'profile.jpg',
            $uploadedFile->getClientFilename(),
            'Should preserve client filename from \'$_FILES\'.',
        );
        self::assertSame(
            'image/jpeg',
            $uploadedFile->getClientMediaType(),
            'Should preserve client media type from \'$_FILES\'.',
        );
        self::assertSame(
            1024,
            $uploadedFile->getSize(),
            'Should preserve file size from \'$_FILES\'.',
        );
        self::assertSame(
            UPLOAD_ERR_OK,
            $uploadedFile->getError(),
            'Should preserve error code from \'$_FILES\'.',
        );
    }
}
