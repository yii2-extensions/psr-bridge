<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Psr\Http\Message\ServerRequestInterface;
use yii\base\InvalidConfigException;
use yii\web\IdentityInterface;
use yii2\extensions\psrbridge\http\StatelessApplication;

/**
 * Stub for a REST application.
 *
 * @template TUserIdentity of IdentityInterface
 * @extends StatelessApplication<TUserIdentity>
 *
 * @copyright Copyright (C) 2026 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class ApplicationRest extends StatelessApplication
{
    /**
     * Indicates whether `reinitializeApplication()` was invoked.
     */
    public bool $reinitializeApplicationCalled = false;

    /**
     * Indicates whether `resetUploadedFilesState()` was invoked.
     */
    public bool $resetUploadedFilesStateCalled = false;

    /**
     * Runs request preparation and triggers lifecycle hooks.
     *
     * @param ServerRequestInterface $request The incoming PSR-7 server request.
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function runPrepareForRequest(ServerRequestInterface $request): void
    {
        $this->prepareForRequest($request);
    }

    protected function reinitializeApplication(): void
    {
        $this->reinitializeApplicationCalled = true;

        parent::reinitializeApplication();
    }

    protected function resetUploadedFilesState(): void
    {
        $this->resetUploadedFilesStateCalled = true;

        parent::resetUploadedFilesState();
    }
}
