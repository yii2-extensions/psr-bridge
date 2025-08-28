<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class StatelessApplicationUploadedTest extends TestCase
{
    public function testUploadedFilesAreResetBetweenRequests(): void
    {
        $tmpFile1 = $this->createTmpFile();

        $tmpPath1 = stream_get_meta_data($tmpFile1)['uri'];
        $size1 = filesize($tmpPath1);

        self::assertIsInt(
            $size1,
            'Temporary file should have a valid size greater than zero.',
        );

        $app = $this->statelessApplication();

        $response = $app->handle(
            FactoryHelper::createRequest('POST', '/site/post', parsedBody: ['action' => 'upload'])
                ->withUploadedFiles(
                    [
                        'file1' => FactoryHelper::createUploadedFile(
                            'test1.txt',
                            'text/plain',
                            $tmpPath1,
                            size: $size1,
                        ),
                    ],
                ),
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
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"action": "upload"}
            JSON,
            $response->getBody()->getContents(),
            "Expected PSR-7 Response body '{\"action\":\"upload\"}'.",
        );
        self::assertNotEmpty(
            UploadedFile::getInstancesByName('file1'),
            'Expected PSR-7 Request should have uploaded files.',
        );

        $response = $app->handle(
            FactoryHelper::createRequest('GET', '/site/post')
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

        $tmpFile3 = $this->createTmpFile();

        $tmpPath3 = stream_get_meta_data($tmpFile3)['uri'];
        $size3 = filesize($tmpPath3);

        self::assertIsInt(
            $size3,
            'Temporary file should have a valid size greater than zero.',
        );

        $response = $app->handle(
            FactoryHelper::createRequest('POST', '/site/post', parsedBody: ['action' => 'upload'])
                ->withUploadedFiles(
                    [
                        'file2' => FactoryHelper::createUploadedFile(
                            'test3.txt',
                            'text/plain',
                            $tmpPath3,
                            size: $size3,
                        ),
                    ],
                ),
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
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"action": "upload"}
            JSON,
            $response->getBody()->getContents(),
            "Expected PSR-7 Response body '{\"action\":\"upload\"}'.",
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
