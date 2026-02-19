<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\creator\ServerRequestCreator;
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

use function filesize;

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} uploaded file handling in stateless mode.
 *
 * Test coverage.
 * - Ensures multipart uploads from PHP superglobals create expected uploaded file instances.
 * - Verifies uploaded files are reset between requests in stateless handling.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationUploadedTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testCreateUploadedFileFromSuperglobalWhenMultipartFormDataPosted(): void
    {
        $_FILES['avatar'] = [
            'name' => 'profile.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $this->createTmpFile(),
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
        ];
        $_POST = ['action' => 'upload'];
        $_SERVER = [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----WebKitFormBoundary7MA4YWxkTrZu0gW',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_HOST' => 'example.com',
            'HTTP_USER_AGENT' => 'PHPUnit Test',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/site/post',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        $creator = new ServerRequestCreator(
            HelperFactory::createServerRequestFactory(),
            HelperFactory::createStreamFactory(),
            HelperFactory::createUploadedFileFactory(),
        );

        $app = ApplicationFactory::stateless();

        $response = $app->handle($creator->createFromGlobals());

        $this->assertSitePostUploadJsonResponse(
            $response,
        );

        $uploadedFiles = UploadedFile::getInstancesByName('avatar');

        foreach ($uploadedFiles as $uploadedFile) {
            self::assertSame(
                'profile.jpg',
                $uploadedFile->name,
                'Should preserve \'name\' from $_FILES.',
            );
            self::assertSame(
                'image/jpeg',
                $uploadedFile->type,
                'Should preserve \'type\' from $_FILES.',
            );
            self::assertSame(
                1024,
                $uploadedFile->size,
                'Should preserve \'size\' from $_FILES.',
            );
            self::assertSame(
                UPLOAD_ERR_OK,
                $uploadedFile->error,
                'Should preserve \'error\' from $_FILES.',
            );
        }
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testUploadedFilesAreResetBetweenRequests(): void
    {
        $tmpPath1 = $this->createTmpFile();
        $size1 = filesize($tmpPath1);

        self::assertIsInt(
            $size1,
            'Temporary file should have a valid size greater than zero.',
        );

        $app = ApplicationFactory::stateless();

        $response = $app->handle(
            HelperFactory::createRequest('POST', '/site/post', parsedBody: ['action' => 'upload'])
                ->withUploadedFiles(
                    [
                        'file1' => HelperFactory::createUploadedFile(
                            'test1.txt',
                            'text/plain',
                            $tmpPath1,
                            size: $size1,
                        ),
                    ],
                ),
        );

        $this->assertSitePostUploadJsonResponse(
            $response,
        );
        self::assertNotEmpty(
            UploadedFile::getInstancesByName('file1'),
            'Expected PSR-7 Request should have uploaded files.',
        );

        $response = $app->handle(
            HelperFactory::createRequest('GET', '/site/post')
                ->withQueryParams(['action' => 'check']),
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route '/site/post'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route '/site/post'.",
        );
        self::assertSame(
            '[]',
            $response->getBody()->getContents(),
            'Expected PSR-7 Response body to be empty for POST request with no uploaded files.',
        );
        self::assertEmpty(
            UploadedFile::getInstancesByName('file1'),
            'PSR-7 Request should NOT have uploaded files from previous request.',
        );

        $tmpPath2 = $this->createTmpFile();
        $size2 = filesize($tmpPath2);

        self::assertIsInt(
            $size2,
            'Temporary file should have a valid size greater than zero.',
        );

        $response = $app->handle(
            HelperFactory::createRequest('POST', '/site/post', parsedBody: ['action' => 'upload'])
                ->withUploadedFiles(
                    [
                        'file2' => HelperFactory::createUploadedFile(
                            'test3.txt',
                            'text/plain',
                            $tmpPath2,
                            size: $size2,
                        ),
                    ],
                ),
        );

        $this->assertSitePostUploadJsonResponse(
            $response,
        );
        self::assertNotEmpty(
            UploadedFile::getInstancesByName('file2'),
            'Expected PSR-7 Request should have uploaded files.',
        );
        self::assertEmpty(
            UploadedFile::getInstancesByName('file1'),
            'Files from first request should still not be present after third request',
        );
    }
}
