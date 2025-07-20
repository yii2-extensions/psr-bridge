<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\http;

use PHPUnit\Framework\Attributes\{DataProviderExternal, Group};
use Psr\Http\Message\ServerRequestInterface;
use Yii;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\UploadedFile;
use yii2\extensions\psrbridge\exception\Message;
use yii2\extensions\psrbridge\http\Request;
use yii2\extensions\psrbridge\tests\provider\RequestProvider;
use yii2\extensions\psrbridge\tests\support\FactoryHelper;
use yii2\extensions\psrbridge\tests\TestCase;

use function dirname;
use function filesize;

#[Group('http')]
final class PSR7RequestTest extends TestCase
{
    public function testResetCookieCollectionAfterReset(): void
    {
        $this->mockWebApplication();

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
            "After reset, 'getCookies()' should return a new 'CookieCollection' instance.",
        );
        self::assertTrue(
            $cookies2->has('new_cookie'),
            "New 'CookieCollection' should contain 'new_cookie' after reset.",
        );
        self::assertSame(
            'new_value',
            $cookies2->getValue('new_cookie'),
            "New cookie 'new_cookie' should have the expected value after reset.",
        );
    }

    public function testReturnBodyParamsWhenPsr7RequestHasFormData(): void
    {
        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            "Body parameters should be returned as an array when 'PSR-7' request contains form data.",
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' when present in the 'PSR-7' request.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value from the 'PSR-7' request.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' when present in the 'PSR-7' request.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value from the 'PSR-7' request.",
        );
    }

    public function testReturnBodyParamsWithMethodParamRemoved(): void
    {
        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'key1' => 'value1',
                'key2' => 'value2',
                '_method' => 'PUT',
            ],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $bodyParams = $request->getBodyParams();

        self::assertIsArray(
            $bodyParams,
            'Body parameters should be returned as an array when method parameter is present.',
        );
        self::assertArrayNotHasKey(
            '_method',
            $bodyParams,
            "Method parameter '_method' should be removed from body parameters.",
        );
        self::assertArrayHasKey(
            'key1',
            $bodyParams,
            "Body parameters should contain the key 'key1' after method parameter removal.",
        );
        self::assertSame(
            'value1',
            $bodyParams['key1'] ?? null,
            "Body parameter 'key1' should have the expected value after method parameter removal.",
        );
        self::assertArrayHasKey(
            'key2',
            $bodyParams,
            "Body parameters should contain the key 'key2' after method parameter removal.",
        );
        self::assertSame(
            'value2',
            $bodyParams['key2'] ?? null,
            "Body parameter 'key2' should have the expected value after method parameter removal.",
        );
    }

    public function testReturnCookieCollectionWhenCookiesPresent(): void
    {
        $this->mockWebApplication();

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

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when cookies are present.",
        );
        self::assertCount(
            2,
            $cookies,
            "'CookieCollection' should contain '2' cookies when empty cookies are filtered out.",
        );
        self::assertTrue(
            $cookies->has('session_id'),
            "'CookieCollection' should contain 'session_id' cookie.",
        );
        self::assertSame(
            'abc123',
            $cookies->getValue('session_id'),
            "Cookie 'session_id' should have the expected value from the 'PSR-7' request.",
        );
        self::assertTrue(
            $cookies->has('theme'),
            "'CookieCollection' should contain 'theme' cookie.",
        );
        self::assertSame(
            'dark',
            $cookies->getValue('theme'),
            "Cookie 'theme' should have the expected value from the 'PSR-7' request.",
        );
        self::assertFalse(
            $cookies->has('empty_cookie'),
            "'CookieCollection' should not contain empty cookies.",
        );
    }

    public function testReturnCookieCollectionWhenNoCookiesPresent(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');
        $request = new Request();

        $request->enableCookieValidation = false;
        $request->cookieValidationKey = 'test-validation-key-32-characters';

        $request->setPsr7Request($psr7Request);
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when no cookies are present.",
        );
        self::assertCount(
            0,
            $cookies,
            "'CookieCollection' should be empty when no cookies are present in the 'PSR-7' request.",
        );
    }

    public function testReturnCookieCollectionWithValidationDisabled(): void
    {
        $this->mockWebApplication();

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

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when validation is disabled.",
        );
        self::assertCount(
            2,
            $cookies,
            "'CookieCollection' should contain all non-empty cookies when validation is disabled.",
        );
        self::assertTrue(
            $cookies->has('user_id'),
            "'CookieCollection' should contain 'user_id' cookie when validation is disabled.",
        );
        self::assertSame(
            '42',
            $cookies->getValue('user_id'),
            "Cookie 'user_id' should have the expected value when validation is disabled.",
        );
        self::assertTrue(
            $cookies->has('preferences'),
            "'CookieCollection' should contain 'preferences' cookie when validation is disabled.",
        );
        self::assertSame(
            'compact',
            $cookies->getValue('preferences'),
            "Cookie 'preferences' should have the expected value when validation is disabled.",
        );
    }

    public function testReturnCookieWithCorrectNamePropertyWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

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

    public function testReturnCsrfTokenFromHeaderWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $csrfToken = 'test-csrf-token-value';

        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['X-CSRF-Token' => $csrfToken],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getCsrfTokenFromHeader();

        self::assertSame(
            $csrfToken,
            $result,
            "'CSRF' token from header should match the value provided in the 'PSR-7' request header 'X-CSRF-Token'.",
        );
    }

    public function testReturnCsrfTokenFromHeaderWithCustomHeaderWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $customHeaderName = 'X-Custom-CSRF';
        $csrfToken = 'custom-csrf-token-value';

        $psr7Request = FactoryHelper::createRequest(
            'PUT',
            '/api/resource',
            [$customHeaderName => $csrfToken],
        );
        $request = new Request();

        $request->csrfHeader = $customHeaderName;

        $request->setPsr7Request($psr7Request);
        $result = $request->getCsrfTokenFromHeader();

        self::assertSame(
            $csrfToken,
            $result,
            "'CSRF' token from header should match the value provided in the custom 'PSR-7' request header.",
        );
    }

    public function testReturnEmptyCookieCollectionWhenValidationEnabledButNoValidationKey(): void
    {
        $this->mockWebApplication();

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

    public function testReturnEmptyCookieCollectionWhenValidationEnabledWithInvalidCookies(): void
    {
        $this->mockWebApplication();

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
        $cookies = $request->getCookies();

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when validation is enabled with invalid cookies.",
        );
        self::assertCount(
            0,
            $cookies,
            "'CookieCollection' should be empty when validation is enabled but cookies are invalid.",
        );
    }

    public function testReturnEmptyQueryParamsWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/products');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $queryParams = $request->getQueryParams();

        self::assertEmpty(
            $queryParams,
            "Query parameters should be empty when 'PSR-7' request has no query string.",
        );
    }

    public function testReturnEmptyQueryStringWhenAdapterIsSetWithNoQuery(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getQueryString();

        self::assertEmpty($result, 'Query string should be empty when no query parameters are present.');
    }

    public function testReturnEmptyScriptUrlWhenAdapterIsSetInTraditionalModeWithoutScriptName(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test', [], null, []);
        $request = new Request(['workerMode' => false]);

        $request->setPsr7Request($psr7Request);
        $scriptUrl = $request->getScriptUrl();

        self::assertEmpty(
            $scriptUrl,
            "Script URL should be empty when adapter is set in traditional mode without 'SCRIPT_NAME'.",
        );
    }

    public function testReturnEmptyScriptUrlWhenAdapterIsSetInWorkerMode(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $scriptUrl = $request->getScriptUrl();

        self::assertSame(
            '',
            $scriptUrl,
            'Script URL should be empty when adapter is set in worker mode (default).',
        );
    }

    public function testReturnHttpMethodFromAdapterWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('POST', '/test');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $method = $request->getMethod();

        self::assertSame(
            'POST',
            $method,
            'HTTP method should be returned from adapter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithBodyOverrideAndLowerCaseMethodsWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest(
            'post',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                '_method' => 'put',
                'data' => 'value',
            ],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $method = $request->getMethod();

        self::assertSame(
            'PUT',
            $method,
            'HTTP method should be overridden by body parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithBodyOverrideWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                '_method' => 'PUT',
                'data' => 'value',
            ],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $method = $request->getMethod();

        self::assertSame(
            'PUT',
            $method,
            'HTTP method should be overridden by body parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithCustomMethodParamWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'custom_method' => 'PATCH',
                'data' => 'value',
            ],
        );
        $request = new Request();

        $request->methodParam = 'custom_method';

        $request->setPsr7Request($psr7Request);
        $method = $request->getMethod();

        self::assertSame(
            'PATCH',
            $method,
            'HTTP method should be overridden by custom method parameter when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithHeaderOverrideWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/test',
            ['X-Http-Method-Override' => 'DELETE'],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $method = $request->getMethod();

        self::assertSame(
            'DELETE',
            $method,
            'HTTP method should be overridden by header when adapter is set.',
        );
    }

    public function testReturnHttpMethodWithoutOverrideWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $method = $request->getMethod();

        self::assertSame(
            'GET',
            $method,
            'HTTP method should return original method when no override is present and adapter is set.',
        );
    }

    public function testReturnNewCookieCollectionInstanceOnEachCall(): void
    {
        $this->mockWebApplication();

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
            "Each call to 'getCookies()' should return a new 'CookieCollection' instance, not a cached one.",
        );
    }

    public function testReturnNullFromHeaderWhenCsrfHeaderEmptyAndAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest(
            'PATCH',
            '/api/update',
            ['X-CSRF-Token' => ''],
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getCsrfTokenFromHeader();

        self::assertSame(
            '',
            $result,
            "'CSRF' token from header should return empty string when 'CSRF' header is present but empty in the " .
            "'PSR-7' request.",
        );
    }

    public function testReturnNullFromHeaderWhenCsrfHeaderNotPresentAndAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('DELETE', '/api/resource');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getCsrfTokenFromHeader();

        self::assertNull(
            $result,
            "'CSRF' token from header should return 'null' when no 'CSRF' header is present in the 'PSR-7' request.",
        );
    }

    public function testReturnParentCsrfTokenFromHeaderWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is null (default state)
        $request->reset();
        $result = $request->getCsrfTokenFromHeader();

        self::assertNull(
            $result,
            "'CSRF' token from header should return parent implementation result when adapter is 'null'.",
        );
    }

    public function testReturnParentGetParsedBodyWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is 'null' (default state)
        $request->reset();

        self::assertEmpty(
            $request->getParsedBody(),
            "Parsed body should return empty array when 'PSR-7' request has no parsed body and adapter is 'null'.",
        );
    }

    public function testReturnParentGetScriptUrlWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is 'null' (default state)
        $request->reset();

        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $_SERVER['SCRIPT_FILENAME'] = '/path/to/test.php';

        $scriptUrl = $request->getScriptUrl();

        // kust verify the method executes without throwing exception when adapter is 'null'
        self::assertSame(
            '/test.php',
            $scriptUrl,
            "'getScriptUrl()' should return 'SCRIPT_NAME' when adapter is 'null'.",
        );
    }

    public function testReturnParentHttpMethodWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is 'null' (default state)
        $request->reset();
        $method = $request->getMethod();

        self::assertNotEmpty($method, "HTTP method should not be empty when adapter is 'null'.");
    }

    public function testReturnParentQueryParamsWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is 'null' (default state)
        $request->reset();
        $queryParams = $request->getQueryParams();

        self::assertEmpty($queryParams, "Query parameters should be empty when 'PSR-7' request has no query string.");
    }

    public function testReturnParentQueryStringWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        $queryString = $request->getQueryString();

        self::assertEmpty(
            $queryString,
            "Query string should be empty when 'PSR-7' request has no query string and adapter is 'null'.",
        );
    }

    public function testReturnParentRawBodyWhenAdapterIsNull(): void
    {
        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is 'null' (default state)
        $request->reset();
        $result = $request->getRawBody();

        self::assertEmpty(
            $result,
            "Raw body should return empty string when 'PSR-7' request has no body content and adapter is 'null'.",
        );
    }

    public function testReturnParentUrlWhenAdapterIsNull(): void
    {
        $_SERVER['REQUEST_URI'] = '/legacy/path?param=value';

        $this->mockWebApplication();

        $request = new Request();

        // ensure adapter is 'null' (default state)
        $request->reset();

        $url = $request->getUrl();

        self::assertSame(
            '/legacy/path?param=value',
            $url,
            "URL should return parent implementation result when adapter is 'null'.",
        );

        unset($_SERVER['REQUEST_URI']);
    }

    public function testReturnParsedBodyArrayWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $parsedBodyData = [
            'name' => 'John',
            'email' => 'john@example.com',
            'age' => 30,
        ];

        $psr7Request = FactoryHelper::createRequest(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            $parsedBodyData,
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getParsedBody();

        self::assertIsArray(
            $result,
            "Parsed body should return an array when 'PSR-7' request contains array data.",
        );
        self::assertSame(
            $parsedBodyData,
            $result,
            "Parsed body should match the original data from 'PSR-7' request.",
        );
        self::assertArrayHasKey(
            'name',
            $result,
            "Parsed body should contain the 'name' field.",
        );
        self::assertSame(
            'John',
            $result['name'],
            'Name field should match the expected value.',
        );
    }

    public function testReturnParsedBodyNullWhenAdapterIsSetWithNullBody(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest(
            'GET',
            '/api/users',
            [],
            null,
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getParsedBody();

        self::assertNull($result, "Parsed body should return 'null' when 'PSR-7' request has no parsed body.");
    }

    public function testReturnParsedBodyObjectWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $parsedBodyObject = (object) [
            'title' => 'Test Article',
            'content' => 'Article content',
        ];

        $psr7Request = FactoryHelper::createRequest(
            'PUT',
            '/api/articles/1',
            ['Content-Type' => 'application/json'],
            $parsedBodyObject,
        );
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getParsedBody();

        self::assertIsObject(
            $result,
            "Parsed body should return an 'object' when 'PSR-7' request contains 'object' data.",
        );
        self::assertSame(
            $parsedBodyObject,
            $result,
            "Parsed body 'object' should match the original 'object' from 'PSR-7' request.",
        );
        self::assertSame(
            'Test Article',
            $result->title,
            "Object 'title' property should match the expected value.",
        );
        self::assertSame(
            'Article content',
            $result->content,
            "Object 'content' property should match the expected value.",
        );
    }

    public function testReturnPsr7RequestInstanceWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');
        $request = new Request();

        $request->setPsr7Request($psr7Request);

        self::assertInstanceOf(
            ServerRequestInterface::class,
            $request->getPsr7Request(),
            "'getPsr7Request()' should return a '" . ServerRequestInterface::class . "' instance when the 'PSR-7' " .
            'adapter is set.',
        );
    }

    public function testReturnQueryParamsWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/products?category=electronics&price=500&sort=desc');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $queryParams = $request->getQueryParams();

        self::assertArrayHasKey(
            'category',
            $queryParams,
            "Query parameters should contain the key 'category' when present in the 'PSR-7' request URI.",
        );
        self::assertSame(
            'electronics',
            $queryParams['category'] ?? null,
            "Query parameter 'category' should have the expected value from the 'PSR-7' request URI.",
        );
        self::assertArrayHasKey(
            'price',
            $queryParams,
            "Query parameters should contain the key 'price' when present in the 'PSR-7' request URI.",
        );
        self::assertSame(
            '500',
            $queryParams['price'] ?? null,
            "Query parameter 'price' should have the expected value from the 'PSR-7' request URI.",
        );
        self::assertArrayHasKey(
            'sort',
            $queryParams,
            "Query parameters should contain the key 'sort' when present in the 'PSR-7' request URI.",
        );
        self::assertSame(
            'desc',
            $queryParams['sort'] ?? null,
            "Query parameter 'sort' should have the expected value from the 'PSR-7' request URI.",
        );
    }

    /**
     * @phpstan-param string $expectedString
     */
    #[DataProviderExternal(RequestProvider::class, 'getQueryString')]
    public function testReturnQueryStringWhenAdapterIsSet(string $queryString, string $expectedString): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', "/test?{$queryString}");
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getQueryString();

        self::assertSame(
            $expectedString,
            $result,
            "Query string should match the expected value for: '{$queryString}'.",
        );
    }

    public function testReturnRawBodyFromAdapterWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $bodyContent = '{"name":"John","email":"john@example.com","message":"Hello World"}';

        $stream = FactoryHelper::createStream('php://temp', 'wb+');

        $stream->write($bodyContent);

        $psr7Request = FactoryHelper::createRequest('POST', '/api/contact')->withBody($stream);
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getRawBody();

        self::assertSame(
            $bodyContent,
            $result,
            "Raw body should return the exact content from the 'PSR-7' request body when adapter is set.",
        );
    }

    public function testReturnRawBodyWhenAdapterIsSetWithEmptyBody(): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', '/test');
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $result = $request->getRawBody();

        self::assertEmpty(
            $result,
            "Raw body should return empty string when 'PSR-7' request has no body content.",
        );
    }

    public function testReturnReadOnlyCookieCollectionWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

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

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when adapter is set.",
        );

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

    public function testReturnScriptNameWhenAdapterIsSetInTraditionalMode(): void
    {
        $this->mockWebApplication();

        $expectedScriptName = '/app/public/index.php';

        $psr7Request = FactoryHelper::createRequest(
            'GET',
            '/test',
            [],
            null,
            ['SCRIPT_NAME' => $expectedScriptName],
        );
        $request = new Request(['workerMode' => false]);

        $request->setPsr7Request($psr7Request);
        $scriptUrl = $request->getScriptUrl();

        self::assertSame(
            $expectedScriptName,
            $scriptUrl,
            "Script URL should return 'SCRIPT_NAME' when adapter is set in traditional mode.",
        );
    }

    public function testReturnUploadedFilesRecursivelyConvertsNestedArrays(): void
    {
        $this->mockWebApplication();

        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';
        $file2 = dirname(__DIR__) . '/support/stub/files/test2.php';
        $size1 = filesize($file1);
        $size2 = filesize($file2);

        self::assertNotFalse(
            $size1,
            "File size for 'test1.txt' should not be 'false'.",
        );
        self::assertNotFalse(
            $size2,
            "File size for 'test2.php' should not be 'false'.",
        );

        $uploadedFile1 = FactoryHelper::createUploadedFile('test1.txt', 'text/plain', $file1, size: $size1);
        $uploadedFile2 = FactoryHelper::createUploadedFile('test2.php', 'application/x-php', $file2, size: $size2);

        $deepNestedFiles = [
            'docs' => [
                'sub' => [
                    'file1' => $uploadedFile1,
                    'file2' => $uploadedFile2,
                ],
            ],
        ];

        $psr7Request = FactoryHelper::createRequest('POST', '/upload')->withUploadedFiles($deepNestedFiles);

        $request = new Request();
        $request->setPsr7Request($psr7Request);

        $deepNestedUploadedFiles = $request->getUploadedFiles();

        $expectedUpdloadedFiles = [
            'file1' => [
                'name' => 'test1.txt',
                'type' => 'text/plain',
                'tempName' => $file1,
                'error' => UPLOAD_ERR_OK,
                'size' => $size1,
            ],
            'file2' => [
                'name' => 'test2.php',
                'type' => 'application/x-php',
                'tempName' => $file2,
                'error' => UPLOAD_ERR_OK,
                'size' => $size2,
            ],
        ];

        $runtimePath = dirname(__DIR__, 2) . '/runtime';

        foreach ($deepNestedUploadedFiles as $nestedUploadFiles) {
            if (is_array($nestedUploadFiles)) {
                foreach ($nestedUploadFiles as $uploadedFiles) {
                    if (is_array($uploadedFiles)) {
                        foreach ($uploadedFiles as $name => $uploadedFile) {
                            self::assertInstanceOf(
                                UploadedFile::class,
                                $uploadedFile,
                                "Uploaded file '{$name}' should be an instance of '" . UploadedFile::class . "'.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['name'] ?? null,
                                $uploadedFile->name,
                                "Uploaded file '{$name}' should have the expected client filename.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['type'] ?? null,
                                $uploadedFile->type,
                                "Uploaded file '{$name}' should have the expected client media type.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['tempName'] ?? null,
                                $uploadedFile->tempName,
                                "Uploaded file '{$name}' should have the expected temporary name.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['error'] ?? null,
                                $uploadedFile->error,
                                "Uploaded file '{$name}' should have the expected error code.",
                            );
                            self::assertSame(
                                $expectedUpdloadedFiles[$name]['size'] ?? null,
                                $uploadedFile->size,
                                "Uploaded file '{$name}' should have the expected size.",
                            );
                            self::assertTrue(
                                $uploadedFile->saveAs("{$runtimePath}/{$uploadedFile->name}", false),
                                "Uploaded file '{$uploadedFile->name}' should be saved to the runtime directory " .
                                'successfully.',
                            );
                            self::assertFileExists(
                                "{$runtimePath}/{$uploadedFile->name}",
                                "Uploaded file '{$uploadedFile->name}' should exist in the runtime directory after " .
                                'saving.',
                            );
                        }
                    }
                }
            }
        }
    }

    public function testReturnUploadedFilesWhenAdapterIsSet(): void
    {
        $this->mockWebApplication();

        $file1 = dirname(__DIR__) . '/support/stub/files/test1.txt';
        $file2 = dirname(__DIR__) . '/support/stub/files/test2.php';
        $size1 = filesize($file1);
        $size2 = filesize($file2);

        self::assertNotFalse(
            $size1,
            "File size for 'test1.txt' should not be 'false'.",
        );
        self::assertNotFalse(
            $size2,
            "File size for 'test2.php' should not be 'false'.",
        );

        $uploadedFile1 = FactoryHelper::createUploadedFile('test1.txt', 'text/plain', $file1, size: $size1);
        $uploadedFile2 = FactoryHelper::createUploadedFile('test2.php', 'application/x-php', $file2, size: $size2);
        $psr7Request = FactoryHelper::createRequest('POST', '/upload');

        $psr7Request = $psr7Request->withUploadedFiles(
            [
                'file1' => $uploadedFile1,
                'file2' => $uploadedFile2,
            ],
        );

        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $uploadedFiles = $request->getUploadedFiles();

        $expectedNames = [
            'file1',
            'file2',
        ];
        $expectedUpdloadedFiles = [
            'file1' => [
                'name' => 'test1.txt',
                'type' => 'text/plain',
                'tempName' => $file1,
                'error' => UPLOAD_ERR_OK,
                'size' => $size1,
            ],
            'file2' => [
                'name' => 'test2.php',
                'type' => 'application/x-php',
                'tempName' => $file2,
                'error' => UPLOAD_ERR_OK,
                'size' => $size2,
            ],
        ];

        $runtimePath = dirname(__DIR__, 2) . '/runtime';

        foreach ($uploadedFiles as $name => $uploadedFile) {
            self::assertContains(
                $name,
                $expectedNames,
                "Uploaded file name '{$name}' should be in the expected names list.",
            );
            self::assertInstanceOf(
                UploadedFile::class,
                $uploadedFile,
                "Uploaded file '{$name}' should be an instance of '" . UploadedFile::class . "'.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['name'] ?? null,
                $uploadedFile->name,
                "Uploaded file '{$name}' should have the expected client filename.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['type'] ?? null,
                $uploadedFile->type,
                "Uploaded file '{$name}' should have the expected client media type.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['tempName'] ?? null,
                $uploadedFile->tempName,
                "Uploaded file '{$name}' should have the expected temporary name.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['error'] ?? null,
                $uploadedFile->error,
                "Uploaded file '{$name}' should have the expected error code.",
            );
            self::assertSame(
                $expectedUpdloadedFiles[$name]['size'] ?? null,
                $uploadedFile->size,
                "Uploaded file '{$name}' should have the expected size.",
            );
            self::assertTrue(
                $uploadedFile->saveAs("{$runtimePath}/{$uploadedFile->name}", false),
                "Uploaded file '{$uploadedFile->name}' should be saved to the runtime directory successfully.",
            );
            self::assertFileExists(
                "{$runtimePath}/{$uploadedFile->name}",
                "Uploaded file '{$uploadedFile->name}' should exist in the runtime directory after saving.",
            );
        }
    }

    #[DataProviderExternal(RequestProvider::class, 'getUrl')]
    public function testReturnUrlFromAdapterWhenAdapterIsSet(string $url, string $expectedUrl): void
    {
        $this->mockWebApplication();

        $psr7Request = FactoryHelper::createRequest('GET', $url);
        $request = new Request();

        $request->setPsr7Request($psr7Request);
        $url = $request->getUrl();

        self::assertSame(
            $expectedUrl,
            $url,
            "URL should match the expected value for: {$url}.",
        );
    }

    public function testReturnValidatedCookiesWhenValidationEnabledWithValidCookies(): void
    {
        $this->mockWebApplication();

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

        self::assertInstanceOf(
            CookieCollection::class,
            $cookies,
            "Cookies should return a 'CookieCollection' instance when validation is enabled with valid cookies.",
        );
        self::assertCount(
            1,
            $cookies,
            "'CookieCollection' should contain only the valid signed cookie when validation is enabled.",
        );
        self::assertTrue(
            $cookies->has($cookieName),
            "'CookieCollection' should contain the valid signed cookie '{$cookieName}'.",
        );
        self::assertSame(
            $cookieValue,
            $cookies->getValue($cookieName),
            "Valid signed cookie '{$cookieName}' should have the expected decrypted value.",
        );
        self::assertFalse(
            $cookies->has('invalid_cookie'),
            "'CookieCollection' should not contain invalid cookies when validation is enabled.",
        );
    }
}
