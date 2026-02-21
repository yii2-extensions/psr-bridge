<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\{ServerRequestInterface, UploadedFileInterface};
use Yii;
use yii\base\InvalidConfigException;
use yii\web\{CookieCollection, HeaderCollection, NotFoundHttpException};
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;
use yii2\extensions\psrbridge\exception\Message;

use function array_key_exists;
use function base64_decode;
use function explode;
use function filter_var;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_check_encoding;
use function mb_substr;
use function str_starts_with;
use function strncasecmp;

/**
 * Extends Yii request handling with PSR-7 request data.
 *
 * {@see ServerRequestAdapter} PSR-7 to Yii request adapter.
 *
 * @phpstan-property array<string, class-string|array{class: class-string, ...}|callable(): object> $parsers
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
class Request extends \yii\web\Request
{
    /**
     * @var string A secret key used for cookie validation.
     *
     * This property must be set if {@see enableCookieValidation} is `true`.
     */
    public $cookieValidationKey = '';

    /**
     * PSR-7 ServerRequestAdapter for bridging PSR-7 ServerRequestInterface with Yii Request component.
     */
    private ServerRequestAdapter|null $adapter = null;

    /**
     * Retrieves HTTP Basic authentication credentials from the current request.
     *
     * Returns an array containing the username and password sent via HTTP authentication, supporting both standard PHP
     * SAPI variables and the Authorization header for environments where credentials are not passed directly.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * [$username, $password] = $request->getAuthCredentials();
     * ```
     *
     * @return array Contains exactly two elements.
     *   - 0: username sent via HTTP authentication, `null` if the username is not given.
     *   - 1: password sent via HTTP authentication, `null` if the password is not given.
     *
     * @phpstan-return array{0: string|null, 1: string|null}
     */
    public function getAuthCredentials(): array
    {
        $username = isset($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_USER'])
            ? $_SERVER['PHP_AUTH_USER']
            : null;
        $password = isset($_SERVER['PHP_AUTH_PW']) && is_string($_SERVER['PHP_AUTH_PW'])
            ? $_SERVER['PHP_AUTH_PW']
            : null;

        if ($username !== null || $password !== null) {
            return [$username, $password];
        }

        /**
         * Apache with php-cgi does not pass HTTP Basic authentication to PHP by default.
         * To make it work, add one of the following lines to your .htaccess file:
         *
         * SetEnvIf Authorization .+ HTTP_AUTHORIZATION=$0
         * --OR--
         * RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
         */
        $authToken = $this->getHeaders()->get('Authorization');

        if ($authToken !== null && strncasecmp($authToken, 'basic', 5) === 0) {
            $encoded = mb_substr($authToken, 6);
            $decoded = base64_decode($encoded, true); // strict mode

            // validate decoded data
            if ($decoded === false || mb_check_encoding($decoded, 'UTF-8') === false) {
                return [null, null]; // return null for malformed credentials
            }

            $parts = explode(':', $decoded, 2);

            return [
                $parts[0] === '' ? null : $parts[0],
                (isset($parts[1]) && $parts[1] !== '') ? $parts[1] : null,
            ];
        }

        return [null, null];
    }

    /**
     * Retrieves the request body parameters, excluding the HTTP method override parameter if present.
     *
     * Returns the parsed body parameters from the PSR-7 ServerRequestInterface, removing the specified method override
     * parameter (such as `_method`) if it exists.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $params = $request->getBodyParams();
     * ```
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return array|object Request body parameters with the method override parameter removed if present.
     *
     * @phpstan-return array<array-key, mixed>|object
     */
    public function getBodyParams(): array|object
    {
        if ($this->adapter !== null) {
            return $this->adapter->getBodyParams($this->methodParam);
        }

        return parent::getBodyParams();
    }

    /**
     * Retrieves the Content-Type header value for the current request.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $contentType = $request->getContentType();
     * ```
     *
     * @return string 'Content-Type' header value, or an empty string if not present.
     */
    public function getContentType(): string
    {
        if ($this->adapter !== null) {
            return $this->getHeaders()->get('Content-Type') ?? '';
        }

        return parent::getContentType();
    }

    /**
     * Retrieves cookies from the current request, supporting PSR-7 and Yii validation.
     *
     * Returns a {@see CookieCollection} containing cookies extracted from the PSR-7 ServerRequestInterface if the
     * adapter is set, applying Yii style validation when enabled.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $cookies = $request->getCookies();
     * $value = $cookies->getValue('session_id');
     * ```
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return CookieCollection Collection of cookies for the current request.
     */
    public function getCookies(): CookieCollection
    {
        if ($this->adapter !== null) {
            $cookies = $this->adapter->getCookies($this->enableCookieValidation, $this->cookieValidationKey);

            return new CookieCollection($cookies, ['readOnly' => true]);
        }

        return parent::getCookies();
    }

    /**
     * Retrieves the CSRF token from the request headers.
     *
     * This method uses {@see getHeaders()} to access the header collection and retrieves the value for
     * {@see self::csrfHeader}.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $token = $request->getCsrfTokenFromHeader();
     * ```
     *
     * @return string|null CSRF token value from the request header, or `null` if not present.
     */
    public function getCsrfTokenFromHeader(): string|null
    {
        return $this->getHeaders()->get($this->csrfHeader);
    }

    /**
     * Retrieves HTTP headers from the current request, supporting PSR-7 and Yii fallback.
     *
     * Returns a {@see HeaderCollection} containing all HTTP headers extracted from the PSR-7 ServerRequestInterface if
     * the adapter is set.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $headers = $request->getHeaders();
     * $authorization = $headers->get('Authorization');
     * ```
     *
     * @return HeaderCollection Collection of HTTP headers for the current request.
     */
    public function getHeaders(): HeaderCollection
    {
        if ($this->adapter !== null) {
            $headers = $this->adapter->getHeaders();

            $this->filterHeaders($headers);

            return $headers;
        }

        return parent::getHeaders();
    }

    /**
     * Retrieves the HTTP method for the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $method = $request->getMethod();
     * ```
     *
     * @return string Resolved HTTP method for the current request.
     */
    public function getMethod(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getMethod($this->methodParam);
        }

        return parent::getMethod();
    }

    /**
     * Retrieves the parsed body parameters from the current request.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $bodyParams = $request->getParsedBody();
     * ```
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @phpstan-return array<array-key, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        if ($this->adapter !== null) {
            return $this->adapter->getParsedBody();
        }

        return parent::getBodyParams();
    }

    /**
     * Retrieves the underlying PSR-7 ServerRequestInterface instance from the adapter.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $psr7Request = $request->getPsr7Request();
     * ```
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return ServerRequestInterface PSR-7 ServerRequestInterface instance from the adapter.
     */
    public function getPsr7Request(): ServerRequestInterface
    {
        if ($this->adapter === null) {
            throw new InvalidConfigException(Message::PSR7_REQUEST_ADAPTER_NOT_SET->getMessage());
        }

        return $this->adapter->psrRequest;
    }

    /**
     * Retrieves query parameters from the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $queryParams = $request->getQueryParams();
     * ```
     *
     * @phpstan-return array<array-key, mixed> Query parameters as an associative array.
     */
    public function getQueryParams(): array
    {
        if ($this->adapter !== null) {
            $parentParams = parent::getQueryParams();

            if ($parentParams !== []) {
                return $parentParams;
            }

            return $this->adapter->getQueryParams();
        }

        return parent::getQueryParams();
    }

    /**
     * Retrieves the query string from the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $queryString = $request->getQueryString();
     * ```
     *
     * @return string Query string for the current request.
     */
    public function getQueryString(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getQueryString();
        }

        return parent::getQueryString();
    }

    /**
     * Retrieves the raw body content from the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $rawBody = $request->getRawBody();
     * ```
     *
     * @return string Raw body content for the current request.
     */
    public function getRawBody(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getRawBody();
        }

        return parent::getRawBody();
    }

    /**
     * Retrieves the remote host name for the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $host = $request->getRemoteHost();
     * ```
     *
     * @return string|null Remote host name for the current request, or `null` if not available.
     */
    public function getRemoteHost(): string|null
    {
        if ($this->adapter !== null) {
            $remoteHost = $this->getServerParam('REMOTE_HOST');

            return is_string($remoteHost) ? $remoteHost : null;
        }

        return parent::getRemoteHost();
    }

    /**
     * Retrieves the remote IP address for the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $ip = $request->getRemoteIP();
     * ```
     *
     * @return string|null Remote IP address for the current request, or `null` if not available or invalid.
     */
    public function getRemoteIP(): string|null
    {
        if ($this->adapter !== null) {
            $remoteIP = $this->getServerParam('REMOTE_ADDR');

            if (is_string($remoteIP) === false) {
                return null;
            }

            if (filter_var($remoteIP, FILTER_VALIDATE_IP) === false) {
                return null;
            }

            return $remoteIP;
        }

        return parent::getRemoteIP();
    }

    /**
     * Returns the request script URL.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $scriptUrl = $request->getScriptUrl();
     * ```
     *
     * @throws InvalidConfigException if unable to determine the entry script URL.
     *
     * @return string Script URL for the current request.
     */
    public function getScriptUrl(): string
    {
        if ($this->adapter !== null) {
            return $this->getScriptName();
        }

        return parent::getScriptUrl();
    }

    /**
     * Retrieves the server name for the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $serverName = $request->getServerName();
     * ```
     *
     * @return string|null Server name for the current request, or `null` if not available.
     */
    public function getServerName(): string|null
    {
        if ($this->adapter !== null) {
            $serverName = $this->getServerParam('SERVER_NAME');

            return is_string($serverName) ? $serverName : null;
        }

        return parent::getServerName();
    }

    /**
     * Retrieves a server parameter by name from the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $param = $request->getServerParam('REMOTE_ADDR', '127.0.0.1');
     * ```
     *
     * @param string $name Name of the server parameter to retrieve.
     * @param mixed $default Default value to return if the parameter is not set.
     *
     * @return mixed Value of the server parameter, or the default value if not set.
     */
    public function getServerParam(string $name, mixed $default = null): mixed
    {
        $params = $this->getServerParams();

        return array_key_exists($name, $params) ? $params[$name] : $default;
    }

    /**
     * Retrieves server parameters from the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $params = $request->getServerParams();
     * ```
     *
     * @return array Server parameters for the current request.
     *
     * @phpstan-return array<array-key, mixed>
     */
    public function getServerParams(): array
    {
        if ($this->adapter !== null) {
            return $this->adapter->getServerParams();
        }

        // fallback to '$_SERVER' for non-PSR7 environments
        return $_SERVER;
    }

    /**
     * Retrieves the server port number for the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $port = $request->getServerPort();
     * ```
     *
     * @return int|null Server port number, or `null` if unavailable.
     */
    public function getServerPort(): int|null
    {
        if ($this->adapter !== null) {
            $headers = $this->getHeaders();

            foreach ($this->portHeaders as $portHeader) {
                if ($headers->has($portHeader)) {
                    $headerPort = $headers->get($portHeader);

                    if (is_string($headerPort)) {
                        $ports = explode(',', $headerPort);

                        $firstPort = $ports[0];

                        if (is_numeric($firstPort)) {
                            $port = (int) $firstPort;

                            if ($port >= 1 && $port <= 65535) {
                                return $port;
                            }
                        }
                    }
                }
            }

            $port = $this->getServerParam('SERVER_PORT');

            return is_numeric($port) ? (int) $port : null;
        }

        return parent::getServerPort();
    }

    /**
     * Retrieves uploaded files from the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $files = $request->getUploadedFiles();
     * ```
     *
     * @return array Array of uploaded files for the current request.
     *
     * @phpstan-return array<array<array-key, UploadedFile>, mixed>
     */
    public function getUploadedFiles(): array
    {
        if ($this->adapter !== null) {
            return $this->convertPsr7ToUploadedFiles($this->adapter->getUploadedFiles());
        }

        return [];
    }

    /**
     * Retrieves the URL of the current request, supporting PSR-7 and Yii fallback.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * $url = $request->getUrl();
     * ```
     *
     * @throws InvalidConfigException if the configuration is invalid or incomplete.
     *
     * @return string URL of the current request.
     */
    public function getUrl(): string
    {
        if ($this->adapter !== null) {
            return $this->adapter->getUrl();
        }

        return parent::getUrl();
    }

    /**
     * Resolves the current request into a route and parameters, supporting PSR-7 and Yii fallback.
     *
     * Parses the request using the Yii UrlManager and merges parameters with those from the PSR-7 adapter if present.
     * - If the PSR-7 adapter is set, this method delegates parsing to the UrlManager, merges the resulting parameters
     *   with the query parameters from the adapter, and updates the request's query parameters accordingly.
     * - If no adapter is present, it falls back to the parent implementation.
     *
     * Usage example:
     * ```php
     * $request = new \yii2\extensions\psrbridge\http\Request();
     * [$route, $params] = $request->resolve();
     * ```
     *
     * @throws NotFoundHttpException if the route is not found or undefined.
     *
     * @return array An array containing the resolved route and parameters.
     *
     * @phpstan-return array<array-key, mixed>
     */
    public function resolve(): array
    {
        if ($this->adapter !== null) {
            /** @phpstan-var array{0: string, 1: array<string, mixed>}|false $result*/
            $result = Yii::$app->getUrlManager()->parseRequest($this);

            if ($result !== false) {
                [$route, $params] = $result;

                $mergedParams = $params + $this->adapter->getQueryParams();

                $this->setQueryParams($mergedParams);

                return [$route, $mergedParams];
            }

            throw new NotFoundHttpException(Yii::t('yii', Message::PAGE_NOT_FOUND->getMessage()));
        }

        return parent::resolve();
    }

    /**
     * Sets and bridges a PSR-7 ServerRequestInterface instance for the current request.
     *
     * Usage example:
     * ```php
     * $psr7Request = new \GuzzleHttp\Psr7\ServerRequest('GET', '/api/resource');
     * $request->setPsr7Request($psr7Request);
     * ```
     *
     * @param ServerRequestInterface $request PSR-7 ServerRequestInterface instance to bridge.
     */
    public function setPsr7Request(ServerRequestInterface $request): void
    {
        $this->adapter = new ServerRequestAdapter(
            $request,
            $this->parsers,
        );

        UploadedFile::setPsr7Adapter($this->adapter);
    }

    /**
     * Converts an array of PSR-7 UploadedFileInterface to Yii UploadedFile instances recursively.
     *
     * Iterates through the provided array of uploaded files, converting each {@see UploadedFileInterface} instance
     * to a Yii {@see UploadedFile} object.
     *
     * @param array $uploadedFiles Array of uploaded files or nested arrays to convert.
     *
     * @return array Converted array of Yii UploadedFile instances, preserving keys and nesting.
     *
     * @phpstan-param array<array-key, mixed> $uploadedFiles Array of uploaded files or nested arrays to convert.
     *
     * @phpstan-return array<array<array-key, UploadedFile>, mixed>
     */
    private function convertPsr7ToUploadedFiles(array $uploadedFiles): array
    {
        $converted = [];

        foreach ($uploadedFiles as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                $converted[$key] = $this->createUploadedFile($file);
            } elseif (is_array($file)) {
                $converted[$key] = $this->convertPsr7ToUploadedFiles($file);
            }
        }

        return $converted;
    }

    /**
     * Creates a new {@see UploadedFile} instance from a PSR-7 UploadedFileInterface.
     *
     * Converts a {@see UploadedFileInterface} object to a Yii {@see UploadedFile} instance by extracting the error
     * code, client filename, file size, temporary file path, and media type from the PSR-7 UploadedFileInterface.
     *
     * @param UploadedFileInterface $psrFile PSR-7 UploadedFileInterface instance to convert.
     *
     * @return UploadedFile Yii UploadedFile instance created from the PSR-7 UploadedFileInterface.
     */
    private function createUploadedFile(UploadedFileInterface $psrFile): UploadedFile
    {
        return new UploadedFile(
            [
                'error' => $psrFile->getError(),
                'name' => $psrFile->getClientFilename() ?? '',
                'size' => $psrFile->getSize(),
                'tempName' => $psrFile->getStream()->getMetadata('uri') ?? '',
                'type' => $psrFile->getClientMediaType() ?? '',
                'tempResource' => $psrFile->getStream()->detach(),
            ],
        );
    }

    /**
     * Returns the script name from server parameters.
     *
     * @return string Script name, or an empty string when unavailable.
     */
    private function getScriptName(): string
    {
        $scriptName = $this->getServerParam('SCRIPT_NAME');

        if (is_string($scriptName) && $scriptName !== '' && str_starts_with($scriptName, '/')) {
            return $scriptName;
        }

        return ''; // script-less worker mode fallback
    }
}
