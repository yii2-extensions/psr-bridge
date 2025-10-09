<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Test suite for {@see \yii2\extensions\psrbridge\http\StatelessApplication} content type handling in stateless mode.
 *
 * Verifies the correct Content-Type, Content-Disposition, and response body for file and stream routes in stateless
 * Yii2 applications.
 *
 * Test coverage.
 * - Confirms 'Content-Type' and 'Content-Disposition' headers for file download routes.
 * - Ensures correct response body for file and stream endpoints.
 * - Validates HTTP status codes and header values for each route.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationContentTypesTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testFileDownloadWhenRequestingFileRoute(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/file'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/file'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/plain' for route 'site/file'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Expected Response body to match plain text 'This is a test file content.'.",
        );
        self::assertSame(
            'attachment; filename="testfile.txt"',
            $response->getHeaderLine('Content-Disposition'),
            "Expected Content-Disposition 'attachment; filename=\"testfile.txt\"'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testStreamContentWhenRequestingStreamRoute(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createRequest('GET', '/site/stream'));

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/stream'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'text/plain' for route 'site/stream'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Expected Response body to match plain text 'This is a test file content.'.",
        );
    }
}
