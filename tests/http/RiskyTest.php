<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\Group;
use yii\base\InvalidConfigException;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('risky')]
final class RiskyTest extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowableOccursDuringRequestHandling(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'nonexistent/invalidaction',
        ];

        $request = FactoryHelper::createServerRequestCreator()->createFromGlobals();

        $app = $this->statelessApplication();

        $response = $app->handle($request);

        self::assertSame(
            404,
            $response->getStatusCode(),
            "Response status code should be '404' when handling a request to 'non-existent' route in " .
            "'StatelessApplication', confirming proper error handling in catch block.",
        );
        self::assertSame(
            'text/html; charset=UTF-8',
            $response->getHeaders()['content-type'][0] ?? '',
            "Response 'content-type' should be 'text/html; charset=UTF-8' for error response when 'Throwable' occurs " .
            "during request handling in 'StatelessApplication'.",
        );

        $body = $response->getBody()->getContents();

        self::assertStringContainsString(
            '<pre>Not Found: Page not found.</pre>',
            $body,
            "Response body should contain error message about 'Not Found: Page not found' when 'Throwable' occurs " .
            "during request handling in 'StatelessApplication'.",
        );
    }
}
