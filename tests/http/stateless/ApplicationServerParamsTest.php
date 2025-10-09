<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\{FactoryHelper, TestCase};

/**
 * Test suite for {@see \yii2\extensions\psrbridge\http\StatelessApplication} server parameter handling in stateless
 * mode.
 *
 * Verifies correct handling of remote IP addresses and server port extraction in stateless Yii2 applications.
 *
 * Test coverage.
 * - Confirms remote IP address extraction for valid and invalid REMOTE_ADDR values.
 * - Ensures correct HTTP status codes, content types, and JSON response bodies for each route.
 * - Validates server port extraction from headers and server parameters, including trusted host scenarios.
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
    #[DataProviderExternal(StatelessApplicationProvider::class, 'remoteIPAddresses')]
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

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
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
    #[DataProviderExternal(StatelessApplicationProvider::class, 'serverPortHeaders')]
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

        self::assertSame(
            200,
            $response->getStatusCode(),
            "Expected HTTP '200' for route 'site/index'.",
        );
        self::assertSame(
            'application/json; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
            "Expected Content-Type 'application/json; charset=UTF-8' for route 'site/index'.",
        );
        self::assertJsonStringEqualsJsonString(
            <<<JSON
            {"hello":"world"}
            JSON,
            $response->getBody()->getContents(),
            "Expected JSON Response body '{\"hello\":\"world\"}'.",
        );
        self::assertSame(
            $expectedPort,
            $app->request->getServerPort(),
            $assertionMessage,
        );
    }
}
