<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use Psr\Http\Message\ServerRequestInterface;
use yii\base\InvalidConfigException;
use yii\web\IdentityInterface;
use yii2\extensions\psrbridge\http\StatelessApplication;

/**
 * Test stub for REST-style StatelessApplication customization.
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
     * Flags to track method calls for testing purposes.
     */
    public bool $reinitializeApplicationCalled = false;

    /**
     * Flags to track method calls for testing purposes.
     */
    public bool $resetUploadedFilesStateCalled = false;

    /**
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
