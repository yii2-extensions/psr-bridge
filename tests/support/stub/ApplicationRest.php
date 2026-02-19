<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use yii\base\InvalidConfigException;
use yii\web\IdentityInterface;
use yii2\extensions\psrbridge\http\{Application, Response};

/**
 * REST application stub for lifecycle hook assertions.
 *
 * @template TUserIdentity of IdentityInterface
 * @extends Application<TUserIdentity>
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ApplicationRest extends Application
{
    /**
     * Indicates whether `attachPsrRequest()` was invoked.
     */
    public bool $attachPsrRequestCalled = false;

    /**
     * Indicates whether `finalizeSessionState()` was invoked.
     */
    public bool $finalizeSessionStateCalled = false;

    /**
     * Log of lifecycle hook calls for testing purposes.
     *
     * Each entry is a string representing the name of the lifecycle hook that was called.
     *
     * @phpstan-var list<string> $hookCallLog
     */
    public array $hookCallLog = [];

    /**
     * Indicates whether `openSessionFromRequestCookies()` was invoked.
     */
    public bool $openSessionFromRequestCookiesCalled = false;

    /**
     * Indicates whether `prepareErrorHandler()` was invoked.
     */
    public bool $prepareErrorHandlerCalled = false;

    /**
     * Indicates whether `reinitializeApplication()` was invoked.
     */
    public bool $reinitializeApplicationCalled = false;

    /**
     * Indicates whether `resetRequestState()` was invoked.
     */
    public bool $resetRequestStateCalled = false;

    /**
     * Indicates whether `resetUploadedFilesState()` was invoked.
     */
    public bool $resetUploadedFilesStateCalled = false;

    /**
     * Indicates whether `syncCookieValidationState()` was invoked.
     */
    public bool $syncCookieValidationStateCalled = false;

    /**
     * Indicates whether `terminate()` was invoked.
     */
    public bool $terminateCalled = false;

    /**
     * Runs request preparation and triggers lifecycle hooks.
     *
     * @param ServerRequestInterface $request The incoming PSR-7 server request.
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function runPrepareForRequest(ServerRequestInterface $request): void
    {
        $this->hookCallLog[] = 'prepareForRequest';

        $this->prepareForRequest($request);
    }

    protected function attachPsrRequest(ServerRequestInterface $request): void
    {
        $this->hookCallLog[] = 'attachPsrRequest';
        $this->attachPsrRequestCalled = true;

        parent::attachPsrRequest($request);
    }

    protected function finalizeSessionState(): void
    {
        $this->hookCallLog[] = 'finalizeSessionState';
        $this->finalizeSessionStateCalled = true;

        parent::finalizeSessionState();
    }

    protected function openSessionFromRequestCookies(): void
    {
        $this->hookCallLog[] = 'openSessionFromRequestCookies';
        $this->openSessionFromRequestCookiesCalled = true;

        parent::openSessionFromRequestCookies();
    }

    protected function prepareErrorHandler(): void
    {
        $this->hookCallLog[] = 'prepareErrorHandler';
        $this->prepareErrorHandlerCalled = true;

        parent::prepareErrorHandler();
    }

    protected function reinitializeApplication(): void
    {
        $this->hookCallLog[] = 'reinitializeApplication';
        $this->reinitializeApplicationCalled = true;

        parent::reinitializeApplication();
    }

    protected function resetRequestState(): void
    {
        $this->hookCallLog[] = 'resetRequestState';
        $this->resetRequestStateCalled = true;

        parent::resetRequestState();
    }

    protected function resetUploadedFilesState(): void
    {
        $this->hookCallLog[] = 'resetUploadedFilesState';
        $this->resetUploadedFilesStateCalled = true;

        parent::resetUploadedFilesState();
    }

    protected function syncCookieValidationState(): void
    {
        $this->hookCallLog[] = 'syncCookieValidationState';
        $this->syncCookieValidationStateCalled = true;

        parent::syncCookieValidationState();
    }

    protected function terminate(Response $response): ResponseInterface
    {
        $this->hookCallLog[] = 'terminate';
        $this->terminateCalled = true;

        return parent::terminate($response);
    }
}
