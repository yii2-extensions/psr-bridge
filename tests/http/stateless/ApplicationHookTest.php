<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\{Action, InvalidConfigException};
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Unit tests for the lifecycle hook overrides in {@see \yii2\extensions\psrbridge\tests\support\stub\ApplicationRest}.
 *
 * Test coverage.
 * - Verifies that `handle()` invokes the overridden `terminate()` hook.
 * - Verifies that `prepareForRequest()` invokes the overridden `reinitializeApplication()` hook.
 * - Verifies that `prepareForRequest()` invokes the overridden `resetRequestState()` hook.
 * - Verifies that `prepareForRequest()` invokes the overridden `resetUploadedFilesState()` hook.
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationHookTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPrepareForRequestCallsOverriddenReinitializeApplicationHook(): void
    {
        $app = $this->applicationRest();

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertTrue(
            $app->reinitializeApplicationCalled,
            "Overridden 'reinitializeApplication()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->terminateCalled,
            "Overridden 'terminate()' hook should be invoked by 'handle()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPrepareForRequestCallsOverriddenResetRequestStateHook(): void
    {
        $app = $this->applicationRest();

        $response = $app->handle(FactoryHelper::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertInstanceOf(
            Action::class,
            $app->requestedAction,
            "Should be an instance of 'yii\base\Action' after request handling.",
        );
        self::assertTrue(
            $app->resetRequestStateCalled,
            "Overridden 'resetRequestState()' hook should be invoked by 'prepareForRequest()'.",
        );

        $app->resetRequestStateCalled = false;

        $app->runPrepareForRequest(FactoryHelper::createRequest());

        self::assertSame(
            '',
            $app->requestedRoute,
            "Should be reset to empty 'string'.",
        );
        self::assertNull(
            $app->requestedAction,
            "Should be reset to 'null'.",
        );
        self::assertSame(
            [],
            $app->requestedParams,
            "Should be reset to empty 'array'.",
        );
        self::assertTrue(
            $app->resetRequestStateCalled,
            "Overridden 'resetRequestState()' hook should be invoked by 'prepareForRequest()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPrepareForRequestCallsOverriddenResetUploadedFilesStateHook(): void
    {
        $tmpPath1 = $this->createTmpFile();
        $size1 = filesize($tmpPath1);

        self::assertNotFalse(
            $size1,
            "'filesize()' must not fail on the temporary file.",
        );

        $app = $this->applicationRest();

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

        $app->resetUploadedFilesStateCalled = false;

        $app->runPrepareForRequest(FactoryHelper::createRequest());

        self::assertSame(
            [],
            UploadedFile::$_files,
            'Overridden hook should preserve uploaded file static state reset behavior.',
        );
        self::assertTrue(
            $app->resetUploadedFilesStateCalled,
            "Overridden 'resetUploadedFilesState()' hook should be invoked by 'prepareForRequest()'.",
        );
    }
}
