<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\{Action, InvalidConfigException};
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\{ApplicationFactory, HelperFactory, TestCase};

/**
 * Unit tests for the lifecycle hook overrides in {@see \yii2\extensions\psrbridge\tests\support\stub\ApplicationRest}.
 *
 * Test coverage.
 * - Verifies that `handle()` invokes in the correct sequence the overridden `reinitializeApplication()`,
 *   `resetUploadedFilesState()`, `resetRequestState()`, `prepareErrorHandler()`, `attachPsrRequest()`,
 *   `syncCookieValidationState()`, `openSessionFromRequestCookies()`, `finalizeSessionState()`, and `terminate()`
 *   hooks.
 * - Verifies that `prepareForRequest()` invokes the overridden `resetRequestState()` hook.
 * - Verifies that `prepareForRequest()` invokes the overridden `resetUploadedFilesState()` hook.
 * - Verifies that disabling `resetUploadedFiles` skips the uploaded-file reset hook.
 * - Verifies that disabling `syncCookieValidation` skips the cookie-sync hook.
 * - Verifies that disabling `useSession` skips session open/finalize hooks.
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
    public function testHandleInvokesOverriddenCoreLifecycleHooks(): void
    {
        $app = ApplicationFactory::rest();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        // order assertions to verify the sequence of lifecycle hook invocations
        self::assertSame(
            [
                'reinitializeApplication',
                'resetUploadedFilesState',
                'resetRequestState',
                'prepareErrorHandler',
                'attachPsrRequest',
                'syncCookieValidationState',
                'openSessionFromRequestCookies',
                'finalizeSessionState',
                'terminate',
            ],
            $app->hookCallLog,
            'Lifecycle hooks must be invoked in the documented sequence.',
        );
        self::assertTrue(
            $app->resetUploadedFilesStateCalled,
            "Overridden 'resetUploadedFilesState()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->reinitializeApplicationCalled,
            "Overridden 'reinitializeApplication()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->resetRequestStateCalled,
            "Overridden 'resetRequestState()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->prepareErrorHandlerCalled,
            "Overridden 'prepareErrorHandler()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->attachPsrRequestCalled,
            "Overridden 'attachPsrRequest()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->syncCookieValidationStateCalled,
            "Overridden 'syncCookieValidationState()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->openSessionFromRequestCookiesCalled,
            "Overridden 'openSessionFromRequestCookies()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->finalizeSessionStateCalled,
            "Overridden 'finalizeSessionState()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertTrue(
            $app->terminateCalled,
            "Overridden 'terminate()' hook should be invoked by 'handle()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleSkipsResetUploadedFilesHookWhenResetUploadedFilesIsDisabled(): void
    {
        $app = ApplicationFactory::rest(['resetUploadedFiles' => false]);

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertFalse(
            $app->resetUploadedFilesStateCalled,
            "'resetUploadedFilesState()' should not be invoked when 'resetUploadedFiles' is disabled.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleSkipsSessionHooksWhenUseSessionIsDisabled(): void
    {
        $app = ApplicationFactory::rest(['useSession' => false]);

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertFalse(
            $app->openSessionFromRequestCookiesCalled,
            "'openSessionFromRequestCookies()' should not be invoked when 'useSession' is disabled.",
        );
        self::assertFalse(
            $app->finalizeSessionStateCalled,
            "'finalizeSessionState()' should not be invoked when 'useSession' is disabled.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testHandleSkipsSyncCookieValidationHookWhenSyncCookieValidationIsDisabled(): void
    {
        $app = ApplicationFactory::rest(['syncCookieValidation' => false]);

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertFalse(
            $app->syncCookieValidationStateCalled,
            "'syncCookieValidationState()' should not be invoked when 'syncCookieValidation' is disabled.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPrepareForRequestCallsOverriddenResetRequestStateHook(): void
    {
        $app = ApplicationFactory::rest();

        $response = $app->handle(HelperFactory::createRequest('GET', 'site/index'));

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

        $app->runPrepareForRequest(HelperFactory::createRequest());

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

        $app = ApplicationFactory::rest();

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

        $app->resetUploadedFilesStateCalled = false;

        $app->runPrepareForRequest(HelperFactory::createRequest());

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
