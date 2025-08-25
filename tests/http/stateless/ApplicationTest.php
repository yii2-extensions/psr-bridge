<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class ApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->closeApplication();

        parent::tearDown();
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPlainTextFileResponseForSiteFileRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/file',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/plain' for 'site/file' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response 'body' should match expected plain text 'This is a test file content.' for 'site/file' route " .
            "in 'StatelessApplication'.",
        );
        self::assertSame(
            'attachment; filename="testfile.txt"',
            $response->getHeaderLine('Content-Disposition'),
            "Response 'Content-Disposition' should be 'attachment; filename=\"testfile.txt\"' for 'site/file' route " .
            "in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnPlainTextResponseWithFileContentForSiteStreamRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/stream',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'text/plain',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'text/plain' for 'site/stream' route in 'StatelessApplication'.",
        );
        self::assertSame(
            'This is a test file content.',
            $response->getBody()->getContents(),
            "Response 'body' should match expected plain text 'This is a test file content.' for 'site/stream' route " .
            "in 'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnRedirectResponseForSiteRedirectRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/redirect',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/redirect' in 'StatelessApplication'.",
        );
        self::assertSame(
            '/site/index',
            $response->getHeaderLine('Location'),
            "Response 'Location' header should be '/site/index' for redirect route 'site/redirect' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnRedirectResponseForSiteRefreshRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/refresh',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            302,
            $response->getStatusCode(),
            "Response 'status code' should be '302' for redirect route 'site/refresh' in 'StatelessApplication'.",
        );
        self::assertSame(
            'site/refresh#stateless',
            $response->getHeaderLine('Location'),
            "Response 'Location' header should be 'site/refresh#stateless' for redirect route 'site/refresh' in " .
            "'StatelessApplication'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnsJsonResponse(): void
    {
        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Response 'status code' should be '200' for successful 'StatelessApplication' handling.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Response 'Content-Type' should be 'application/json; charset=UTF-8' for JSON output.",
        );
        self::assertSame(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Response 'body' should match expected JSON string '{\"hello\":\"world\"}'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnsStatusCode201ForSiteStatusCodeRoute(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'site/statuscode',
        ];

        $app = $this->statelessApplication();

        $response = $app->handle(FactoryHelper::createServerRequestCreator()->createFromGlobals());

        self::assertSame(
            201,
            $response->getStatusCode(),
            "Response 'status code' should be '201' for 'site/statuscode' route in 'StatelessApplication'.",
        );
    }
}
