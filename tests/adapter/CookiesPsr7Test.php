<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use yii\base\{InvalidCallException, InvalidConfigException};
use yii\web\Cookie;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('adapter')]
#[Group('cookies')]
final class CookiesPsr7Test extends TestCase
{
    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testResetCookieCollectionAfterReset(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['reset_cookie' => 'test_value']);

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = self::COOKIE_VALIDATION_KEY;

        $request->setPsr7Request($psr7Request);

        $cookies1 = $request->getCookies();

        $newPsr7Request = FactoryHelper::createRequest('GET', '/test');

        $newPsr7Request = $newPsr7Request->withCookieParams(['new_cookie' => 'new_value']);

        $request->setPsr7Request($newPsr7Request);

        $cookies2 = $request->getCookies();

        self::assertNotSame(
            $cookies1,
            $cookies2,
            "After 'reset' method, 'getCookies()' should return a new CookieCollection instance.",
        );
        self::assertTrue(
            $cookies2->has('new_cookie'),
            "New CookieCollection should contain 'new_cookie' after 'reset' method.",
        );
        self::assertSame(
            'new_value',
            $cookies2->getValue('new_cookie'),
            "New cookie 'new_cookie' should have the expected value after 'reset' method.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnNewCookieCollectionInstanceOnEachCall(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['cached_cookie' => 'test_value']);

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = self::COOKIE_VALIDATION_KEY;

        $request->setPsr7Request($psr7Request);

        $cookies1 = $request->getCookies();
        $cookies2 = $request->getCookies();

        self::assertNotSame(
            $cookies1,
            $cookies2,
            "Each call to 'getCookies()' should return a new CookieCollection instance, not a cached one.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testThrowInvalidCallExceptionWhenReturnReadOnlyCookieCollectionWhenAdapterIsSet(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'another_cookie' => 'another_value',
                'test_cookie' => 'test_value',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = self::COOKIE_VALIDATION_KEY;

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        $this->expectException(InvalidCallException::class);
        $this->expectExceptionMessage('The cookie collection is read only.');

        $cookies->add(
            new Cookie(
                [
                    'name' => 'new_cookie',
                    'value' => 'new_value',
                ],
            ),
        );
    }

    public function testThrowInvalidConfigExceptionWhenValidationEnabledButNoValidationKey(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(['session_id' => 'abc123']);

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = '';

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage(Message::COOKIE_VALIDATION_KEY_REQUIRED->getMessage());

        $request->setPsr7Request($psr7Request);

        $request->getCookies();
    }
}
