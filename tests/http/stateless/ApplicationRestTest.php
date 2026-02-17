<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\http\UploadedFile;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

#[Group('http')]
final class ApplicationRestTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPrepareForRequestCallsOverriddenReinitializeApplicationHook(): void
    {
        $app = $this->ApplicationRest();

        $app->runPrepareForRequest(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertTrue(
            $app->reinitializeApplicationCalled,
            "Overridden 'reinitializeApplication()' hook should be invoked by 'prepareForRequest()'.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testPrepareForRequestCallsOverriddenResetUploadedFilesStateHook(): void
    {
        $app = $this->ApplicationRest();

        UploadedFile::$_files = [
            'avatar' => [
                'name' => 'avatar.png',
                'tempName' => '/tmp/php123',
                'tempResource' => null,
                'type' => 'image/png',
                'size' => 1024,
                'error' => UPLOAD_ERR_OK,
                'fullPath' => null,
            ],
        ];

        $app->runPrepareForRequest(FactoryHelper::createRequest('GET', 'site/index'));

        self::assertTrue(
            $app->resetUploadedFilesStateCalled,
            "Overridden 'resetUploadedFilesState()' hook should be invoked by 'prepareForRequest()'.",
        );
        self::assertSame(
            [],
            UploadedFile::$_files,
            'Overridden hook should preserve uploaded-file static state reset behavior.',
        );
    }
}
