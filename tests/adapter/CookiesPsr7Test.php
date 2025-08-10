<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\adapter;

use PHPUnit\Framework\Attributes\Group;
use Yii;
use yii\base\{InvalidCallException, InvalidConfigException};
use yii\helpers\Json;
use yii\web\Cookie;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

#[Group('adapter')]
#[Group('psr7-request')]
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
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);

        $cookies1 = $request->getCookies();
        $request->reset();

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
    public function testReturnCookieCollectionWhenCookiesPresent(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'session_id' => 'abc123',
                'theme' => 'dark',
                'empty_cookie' => '',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        self::assertCount(
            2,
            $cookies,
            "CookieCollection should contain '2' cookies when empty cookies are filtered out.",
        );
        self::assertTrue(
            $cookies->has('session_id'),
            "CookieCollection should contain 'session_id' cookie.",
        );
        self::assertSame(
            'abc123',
            $cookies->getValue('session_id'),
            "Cookie 'session_id' should have the expected value from the PSR-7 request.",
        );
        self::assertTrue(
            $cookies->has('theme'),
            "CookieCollection should contain 'theme' cookie.",
        );
        self::assertSame(
            'dark',
            $cookies->getValue('theme'),
            "Cookie 'theme' should have the expected value from the PSR-7 request.",
        );
        self::assertFalse(
            $cookies->has('empty_cookie'),
            'CookieCollection should not contain empty cookies.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCookieCollectionWhenNoCookiesPresent(): void
    {
        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request(
            FactoryHelper::createRequest('GET', '/test'),
        );

        self::assertCount(
            0,
            $request->getCookies(),
            'CookieCollection should be empty when no cookies are present in the PSR-7 request.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCookieCollectionWithValidationDisabled(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'user_id' => '42',
                'preferences' => 'compact',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        self::assertCount(
            2,
            $cookies,
            'CookieCollection should contain all non-empty cookies when validation is disabled.',
        );
        self::assertTrue(
            $cookies->has('user_id'),
            "CookieCollection should contain 'user_id' cookie when validation is disabled.",
        );
        self::assertSame(
            '42',
            $cookies->getValue('user_id'),
            "Cookie 'user_id' should have the expected value when validation is disabled.",
        );
        self::assertTrue(
            $cookies->has('preferences'),
            "CookieCollection should contain 'preferences' cookie when validation is disabled.",
        );
        self::assertSame(
            'compact',
            $cookies->getValue('preferences'),
            "Cookie 'preferences' should have the expected value when validation is disabled.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnCookieWithCorrectNamePropertyWhenAdapterIsSet(): void
    {
        $cookieName = 'session_id';
        $cookieValue = 'abc123';

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams([$cookieName => $cookieValue]);

        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        $cookie = $cookies[$cookieName] ?? null;

        if ($cookie === null) {
            foreach ($cookies as $cookieObj) {
                if ($cookieObj->value === $cookieValue) {
                    $cookie = $cookieObj;
                    break;
                }
            }
        }

        self::assertNotNull(
            $cookie,
            'Cookie should be found in the collection.',
        );
        self::assertInstanceOf(
            Cookie::class,
            $cookie,
            'Should be a Cookie instance.',
        );
        self::assertSame(
            $cookieName,
            $cookie->name,
            "Cookie 'name' property should match the original cookie 'name' from PSR-7 request.",
        );
        self::assertSame(
            $cookieValue,
            $cookie->value,
            "Cookie 'value' property should match the original cookie 'value' from PSR-7 request.",
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnEmptyCookieCollectionWhenValidationEnabledWithInvalidCookies(): void
    {
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                'invalid_cookie' => 'invalid_data',
                'empty_cookie' => '',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);

        self::assertCount(
            0,
            $request->getCookies(),
            'CookieCollection should be empty when validation is enabled but cookies are invalid.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnMultipleValidatedCookiesWhenValidationEnabledWithMultipleValidCookies(): void
    {
        $validationKey = 'test-validation-key-32-characters';

        $cookies = [
            'session_id' => 'session_value_123',
            'user_pref' => 'preference_value_456',
            'theme' => 'dark_theme_789',
            'language' => 'en_US_012',
        ];

        $signedCookies = [];

        foreach ($cookies as $name => $value) {
            $data = [$name, $value];
            $signedCookies[$name] = Yii::$app->getSecurity()->hashData(Json::encode($data), $validationKey);
        }

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams($signedCookies);

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = $validationKey;

        $request->setPsr7Request($psr7Request);

        $cookieCollection = $request->getCookies();

        self::assertCount(
            4,
            $cookieCollection,
            "Should return all '4' validated cookies, not just one.",
        );

        foreach ($cookies as $expectedName => $expectedValue) {
            self::assertTrue(
                $cookieCollection->has($expectedName),
                "Cookie collection should contain '{$expectedName}'",
            );
            self::assertSame(
                $expectedValue,
                $cookieCollection->getValue($expectedName),
                "Cookie '{$expectedName}' should have the correct decrypted value",
            );
        }

        $cookieNames = [];

        foreach ($cookieCollection as $cookie) {
            $cookieNames[] = $cookie->name;
        }

        self::assertCount(
            4,
            $cookieNames,
            "Should have exactly '4' cookie names",
        );

        foreach (array_keys($cookies) as $expectedName) {
            self::assertContains(
                $expectedName,
                $cookieNames,
                "Cookie name '{$expectedName}' should be present in the collection",
            );
        }
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
        $request->cookieValidationKey = 'test-validation-key-32-characters';

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
    public function testReturnValidatedCookiesWhenValidationEnabledWithValidCookies(): void
    {
        $validationKey = 'test-validation-key-32-characters';

        // create a valid signed cookie using Yii security component
        $cookieName = 'valid_session';
        $cookieValue = 'abc123session';
        $data = [$cookieName, $cookieValue];

        $signedCookieValue = Yii::$app->getSecurity()->hashData(Json::encode($data), $validationKey);
        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams(
            [
                $cookieName => $signedCookieValue,
                'invalid_cookie' => 'invalid_data',
            ],
        );

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = $validationKey;

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        self::assertCount(
            1,
            $cookies,
            'CookieCollection should contain only the valid signed cookie when validation is enabled.',
        );
        self::assertTrue(
            $cookies->has($cookieName),
            "CookieCollection should contain the valid signed cookie '{$cookieName}'.",
        );
        self::assertSame(
            $cookieValue,
            $cookies->getValue($cookieName),
            "Valid signed cookie '{$cookieName}' should have the expected decrypted value.",
        );
        self::assertFalse(
            $cookies->has('invalid_cookie'),
            'CookieCollection should not contain invalid cookies when validation is enabled.',
        );
    }

    /**
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     */
    public function testReturnValidatedCookieWithCorrectNamePropertyWhenValidationEnabled(): void
    {
        $validationKey = 'test-validation-key-32-characters';
        $cookieName = 'validated_session';
        $cookieValue = 'secure_session_value';
        $data = [$cookieName, $cookieValue];

        $signedCookieValue = Yii::$app->getSecurity()->hashData(Json::encode($data), $validationKey);

        $psr7Request = FactoryHelper::createRequest('GET', '/test');

        $psr7Request = $psr7Request->withCookieParams([$cookieName => $signedCookieValue]);

        $request = new Request();

        $request->enableCookieValidation = true;
        $request->cookieValidationKey = $validationKey;

        $request->setPsr7Request($psr7Request);

        $cookies = $request->getCookies();

        $cookie = null;

        foreach ($cookies as $cookieObj) {
            if ($cookieObj->value === $cookieValue) {
                $cookie = $cookieObj;
                break;
            }
        }

        self::assertNotNull(
            $cookie,
            'Validated cookie should be found in the collection.',
        );
        self::assertInstanceOf(
            Cookie::class,
            $cookie,
            'Should be a Cookie instance.',
        );
        self::assertSame(
            $cookieName,
            $cookie->name,
            "Validated cookie 'name' property should match the original cookie 'name' from PSR-7 request",
        );
        self::assertSame(
            $cookieValue,
            $cookie->value,
            "Validated cookie 'value' property should match the decrypted cookie 'value'",
        );
        self::assertNull(
            $cookie->expire,
            "Validated cookie 'expire' property should be 'null' as set in the constructor",
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
        $request->cookieValidationKey = 'test-validation-key-32-characters';

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
