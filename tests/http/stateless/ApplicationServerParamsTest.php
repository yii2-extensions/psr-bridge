<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\ApplicationProvider;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Unit tests for {@see \yii2\extensions\psrbridge\http\Application} server parameter handling in stateless mode.
 *
 * Test coverage.
 * - Ensures remote IP resolution handles valid and invalid REMOTE_ADDR values.
 * - Verifies server port resolution from trusted forwarded headers.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
#[Group('http')]
final class ApplicationServerParamsTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(ApplicationProvider::class, 'remoteIPAddresses')]
    public function testGetRemoteIPHandlesValidAndInvalidAddresses(
        int|string $remoteAddr,
        string|null $expectedIP,
        string $assertionMessage,
    ): void {
        $app = $this->statelessApplication();

        $response = $app->handle(
            FactoryHelper::createRequest(
                method: 'GET',
                uri: '/site/index',
                serverParams: ['REMOTE_ADDR' => $remoteAddr],
            ),
        );

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertSame(
            $expectedIP,
            $app->request->getRemoteIP(),
            $assertionMessage,
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-param array<string, string> $headers
     */
    #[DataProviderExternal(ApplicationProvider::class, 'serverPortHeaders')]
    public function testGetServerPortHandlesValidAndInvalidServerPortFromHeaders(
        array $headers,
        int|null $expectedPort,
        string $assertionMessage,
    ): void {
        $app = $this->statelessApplication(
            [
                'components' => [
                    'request' => [
                        'trustedHosts' => ['*'],
                    ],
                ],
            ],
        );

        $response = $app->handle(
            FactoryHelper::createRequest(
                'GET',
                '/site/index',
                $headers,
                serverParams: ['REMOTE_ADDR' => '10.0.0.1'],
            ),
        );

        $this->assertSiteIndexJsonResponse(
            $response,
        );
        self::assertSame(
            $expectedPort,
            $app->request->getServerPort(),
            $assertionMessage,
        );
    }
}
