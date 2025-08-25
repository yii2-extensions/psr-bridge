<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http\stateless;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\provider\StatelessApplicationProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('http')]
final class ApplicationRemoteIPTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    #[DataProviderExternal(StatelessApplicationProvider::class, 'remoteIPAddresses')]
    public function testReturnValidRemoteIPForIPv4AndIPv6Addresses(
        string $remoteAddr,
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

        self::assertSame($expectedIP, $app->request->getRemoteIP(), $assertionMessage);
    }
}
