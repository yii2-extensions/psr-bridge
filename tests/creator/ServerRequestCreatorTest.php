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
    public function testCreateFromGlobalsWithBasicHttpHeaders(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Bearer token123',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'Authorization',
            $headers,
            "Should extract 'Authorization' header from 'HTTP_AUTHORIZATION'.",
        );
        self::assertSame(
            'Bearer token123',
            $headers['Authorization'][0] ?? '',
            "Should preserve 'Authorization' header value.",
        );
        self::assertArrayHasKey(
            'Content-Type',
            $headers,
            "Should extract 'Content-Type' header from 'HTTP_CONTENT_TYPE'.",
        );
        self::assertSame(
            'application/json',
            $headers['Content-Type'][0] ?? '',
            "Should preserve 'Content-Type' header value.",
        );
        self::assertArrayHasKey(
            'User-Agent',
            $headers,
            "Should extract 'User-Agent' header from 'HTTP_USER_AGENT'.",
        );
        self::assertSame(
            'Mozilla/5.0',
            $headers['User-Agent'][0] ?? '',
            "Should preserve 'User-Agent' header value.",
        );
    }

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

    public function testCreateFromGlobalsWithCaseInsensitiveHttpPrefix(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Bearer token123',
            'http_content_type' => 'application/json',
            'Http_User_Agent' => 'Mozilla/5.0',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'Authorization',
            $headers,
            "Should extract header with uppercase 'HTTP_' prefix.",
        );
        self::assertArrayNotHasKey(
            'Content-Type',
            $headers,
            "Should not extract header with lowercase 'http_' prefix.",
        );
        self::assertArrayNotHasKey(
            'User-Agent',
            $headers,
            "Should not extract header with mixed case 'Http_' prefix.",
        );
        self::assertCount(
            1,
            $headers,
            "Should only extract headers with exact 'HTTP_' prefix.",
        );
    }

    public function testCreateFromGlobalsWithComplexHeaderNames(): void
    {
        $_SERVER = [
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_CACHE_CONTROL' => 'no-cache',
            'HTTP_X_CUSTOM_HEADER' => 'custom-value',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'X-Custom-Header',
            $headers,
            "Should normalize 'HTTP_X_CUSTOM_HEADER' to 'X-Custom-Header'.",
        );
        self::assertSame(
            'custom-value',
            $headers['X-Custom-Header'][0] ?? '',
            "Should preserve 'X-Custom-Header' value.",
        );
        self::assertArrayHasKey(
            'X-Forwarded-For',
            $headers,
            "Should normalize 'HTTP_X_FORWARDED_FOR' to 'X-Forwarded-For'.",
        );
        self::assertSame(
            '192.168.1.1',
            $headers['X-Forwarded-For'][0] ?? '',
            "Should preserve 'X-Forwarded-For' value.",
        );
        self::assertArrayHasKey(
            'Accept-Language',
            $headers,
            "Should normalize 'HTTP_ACCEPT_LANGUAGE' to 'Accept-Language'.",
        );
        self::assertSame(
            'en-US,en;q=0.9',
            $headers['Accept-Language'][0] ?? '',
            "Should preserve 'Accept-Language' value.",
        );
        self::assertArrayHasKey(
            'Cache-Control',
            $headers,
            "Should normalize 'HTTP_CACHE_CONTROL' to 'Cache-Control'.",
        );
        self::assertSame(
            'no-cache',
            $headers['Cache-Control'][0] ?? '',
            "Should preserve 'Cache-Control' value.",
        );
    }

    public function testCreateFromGlobalsWithComplexScenario(): void
    {
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

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
                'error' => \UPLOAD_ERR_OK,
                'name' => 'avatar.png',
                'size' => 1500,
                'tmp_name' => $tmpPath,
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

    public function testCreateFromGlobalsWithEmptyHeaderValues(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => '',
            'HTTP_CONTENT_TYPE' => '',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $request = $creator->createFromGlobals();
        $headers = $request->getHeaders();

        self::assertArrayHasKey(
            'Authorization',
            $headers,
            'Should include header even with empty value.',
        );
        self::assertSame(
            '',
            $headers['Authorization'][0] ?? '',
            "Should preserve empty 'Authorization' header value.",
        );
        self::assertArrayHasKey(
            'Content-Type',
            $headers,
            "Should include 'Content-Type' header even with empty value.",
        );
        self::assertSame(
            '',
            $headers['Content-Type'][0] ?? '',
            "Should preserve empty 'Content-Type' header value.",
        );
    }

    public function testCreateFromGlobalsWithHeadersContainingNumbers(): void
    {
        $_SERVER = [
            'HTTP_X_API_VERSION' => 'v2',
            'HTTP_X_REQUEST_ID' => '12345',
            'HTTP_X_RPC_VERSION' => '1.0',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'X-Api-Version',
            $headers,
            "Should normalize 'HTTP_X_API_VERSION' to 'X-Api-Version'.",
        );
        self::assertSame(
            'v2',
            $headers['X-Api-Version'][0] ?? '',
            "Should preserve 'X-Api-Version' value.",
        );
        self::assertArrayHasKey(
            'X-Request-Id',
            $headers,
            "Should normalize 'HTTP_X_REQUEST_ID' to 'X-Request-Id'.",
        );
        self::assertSame(
            '12345',
            $headers['X-Request-Id'][0] ?? '',
            "Should preserve 'X-Request-Id' value.",
        );
        self::assertArrayHasKey(
            'X-Rpc-Version',
            $headers,
            "Should normalize 'HTTP_X_RPC_VERSION' to 'X-Rpc-Version'.",
        );
        self::assertSame(
            '1.0',
            $headers['X-Rpc-Version'][0] ?? '',
            "Should preserve 'X-Rpc-Version' value.",
        );
    }

    public function testCreateFromGlobalsWithHeadersSpecialCharacters(): void
    {
        $_SERVER = [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNzd29yZA==',
            'HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/form-submit',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertSame(
            'Basic dXNlcjpwYXNzd29yZA==',
            $headers['Authorization'][0] ?? '',
            "Should preserve 'Authorization' header with base64 encoded value.",
        );
        self::assertSame(
            'application/x-www-form-urlencoded; charset=UTF-8',
            $headers['Content-Type'][0] ?? '',
            "Should preserve 'Content-Type' header with charset parameter.",
        );
        self::assertSame(
            'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            $headers['Accept'][0] ?? '',
            "Should preserve 'Accept' header with multiple media types and quality values.",
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

    public function testCreateFromGlobalsWithMixedServerValues(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Bearer token123',
            'HTTP_HOST' => 'example.com',
            'NON_HTTP_HEADER' => 'should-not-be-included',
            'QUERY_STRING' => 'foo=bar',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/test',
            'SCRIPT_NAME' => '/index.php',
            'SERVER_NAME' => 'example.com',
            123 => 'numeric-key',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'Authorization',
            $headers,
            "Should extract 'Authorization' header from 'HTTP_AUTHORIZATION'.",
        );
        self::assertArrayHasKey(
            'Host',
            $headers,
            "Should extract 'Host' header from 'HTTP_HOST'.",
        );
        self::assertArrayNotHasKey(
            'Server-Name',
            $headers,
            "Should not extract 'SERVER_NAME' as it doesn't start with 'HTTP_'.",
        );
        self::assertArrayNotHasKey(
            'Script-Name',
            $headers,
            "Should not extract 'SCRIPT_NAME' as it doesn't start with 'HTTP_'.",
        );
        self::assertArrayNotHasKey(
            'Non-Http-Header',
            $headers,
            "Should not extract 'NON_HTTP_HEADER' as it doesn't start with 'HTTP_'.",
        );
    }

    public function testCreateFromGlobalsWithMultipleUnderscoreHeaders(): void
    {
        $_SERVER = [
            'HTTP_CONTENT_SECURITY_POLICY' => "default-src 'self'",
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_REAL_IP' => '203.0.113.195',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'X-Forwarded-Proto',
            $headers,
            "Should normalize 'HTTP_X_FORWARDED_PROTO' to 'X-Forwarded-Proto'.",
        );
        self::assertSame(
            'https',
            $headers['X-Forwarded-Proto'][0] ?? '',
            "Should preserve 'X-Forwarded-Proto' value.",
        );
        self::assertArrayHasKey(
            'X-Real-Ip',
            $headers,
            "Should normalize 'HTTP_X_REAL_IP' to 'X-Real-Ip'.",
        );
        self::assertSame(
            '203.0.113.195',
            $headers['X-Real-Ip'][0] ?? '',
            "Should preserve 'X-Real-Ip' value.",
        );
        self::assertArrayHasKey(
            'X-Requested-With',
            $headers,
            "Should normalize 'HTTP_X_REQUESTED_WITH' to 'X-Requested-With'.",
        );
        self::assertSame(
            'XMLHttpRequest',
            $headers['X-Requested-With'][0] ?? '',
            "Should preserve 'X-Requested-With' value.",
        );
        self::assertArrayHasKey(
            'Content-Security-Policy',
            $headers,
            "Should normalize 'HTTP_CONTENT_SECURITY_POLICY' to 'Content-Security-Policy'.",
        );
        self::assertSame(
            "default-src 'self'",
            $headers['Content-Security-Policy'][0] ?? '',
            "Should preserve 'Content-Security-Policy' value.",
        );
    }

    public function testCreateFromGlobalsWithMultipleUploadedFiles(): void
    {
        $tmpFile1 = $this->createTmpFile();
        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];

        $tmpFile2 = $this->createTmpFile();
        $tmpPath2 = stream_get_meta_data($tmpFile2)['uri'];

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
                    $tmpPath1,
                    $tmpPath2,
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

    public function testCreateFromGlobalsWithNoHttpHeaders(): void
    {
        $_SERVER = [
            'QUERY_STRING' => 'foo=bar',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
            'SCRIPT_NAME' => '/index.php',
            'SERVER_NAME' => 'example.com',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        self::assertEmpty(
            $creator->createFromGlobals()->getHeaders(),
            "Should have empty headers array when no 'HTTP_*' entries in '\$_SERVER'.",
        );
    }

    public function testCreateFromGlobalsWithNonStringHeaderKeys(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Bearer token123',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/test',
            123 => 'numeric-key-value',
            null => 'null-key-value',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'Authorization',
            $headers,
            "Should extract 'Authorization' header when key is valid string.",
        );
        self::assertSame(
            'Bearer token123',
            $headers['Authorization'][0] ?? '',
            "Should preserve 'Authorization' header value.",
        );
        self::assertCount(
            1,
            $headers,
            "Should only extract headers with valid string keys starting with 'HTTP_'.",
        );
    }

    public function testCreateFromGlobalsWithNonStringHeaderValues(): void
    {
        $_SERVER = [
            'HTTP_AUTHORIZATION' => 'Bearer token123',
            'HTTP_CONTENT_LENGTH' => 1024,
            'HTTP_X_CUSTOM_HEADER' => [],
            'HTTP_X_RATE_LIMIT' => null,
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/test',
        ];

        $creator = new ServerRequestCreator(
            FactoryHelper::createServerRequestFactory(),
            FactoryHelper::createStreamFactory(),
            FactoryHelper::createUploadedFileFactory(),
        );

        $headers = $creator->createFromGlobals()->getHeaders();

        self::assertArrayHasKey(
            'Authorization',
            $headers,
            "Should extract 'Authorization' header when value is string.",
        );
        self::assertSame(
            'Bearer token123',
            $headers['Authorization'][0] ?? '',
            "Should preserve string 'Authorization' header value.",
        );
        self::assertArrayNotHasKey(
            'Content-Length',
            $headers,
            "Should not extract 'HTTP_CONTENT_LENGTH' when value is not string.",
        );
        self::assertArrayNotHasKey(
            'X-Rate-Limit',
            $headers,
            "Should not extract 'HTTP_X_RATE_LIMIT' when value is null.",
        );
        self::assertArrayNotHasKey(
            'X-Custom-Header',
            $headers,
            "Should not extract 'HTTP_X_CUSTOM_HEADER' when value is array.",
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
        $tmpFile = $this->createTmpFile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];

        $_FILES['avatar'] = [
            'name' => 'profile.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmpPath,
            'error' => \UPLOAD_ERR_OK,
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
            \UPLOAD_ERR_OK,
            $uploadedFile->getError(),
            'Should preserve error code from \'$_FILES\'.',
        );
    }
}
