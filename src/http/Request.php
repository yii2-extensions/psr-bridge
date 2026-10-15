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
use function is_object;
use function is_string;
use function mb_check_encoding;
use function mb_substr;
use function str_starts_with;
use function strncasecmp;

use const UPLOAD_ERR_OK;

/**
 * Extends Yii request handling with PSR-7 request data.
 *
 * {@see ServerRequestAdapter} PSR-7 to Yii request adapter.
 *
 * @property array<
 *   string,
 *   class-string<object>|array{class?: class-string<object>, __class?: class-string<object>, ...}|callable(): object
 * > $parsers Parser definitions keyed by Content-Type.
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
     * Returns Basic credentials from the current Authorization header or current server parameters.
     *
     * When a PSR request is attached, fallback uses its `PHP_AUTH_USER` and `PHP_AUTH_PW` only, not worker globals.
     * Malformed Basic encoding or invalid UTF-8 yields `[null, null]`.
     *
     * @return array{0: string|null, 1: string|null} Username and password.
     */
    public function getAuthCredentials(): array
    {
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
                $parts[1] ?? null,
            ];
        }

        $username = $this->getServerParam('PHP_AUTH_USER');
        $password = $this->getServerParam('PHP_AUTH_PW');

        if (is_string($username) || is_string($password)) {
            return [
                is_string($username) ? $username : null,
                is_string($password) ? $password : null,
            ];
        }

        return [null, null];
    }

    /**
     * Returns parsed body parameters with the configured method override removed from array bodies.
     *
     * @throws InvalidConfigException when a fallback Yii parser definition is invalid.
     *
     * @return array<mixed>|object Body parameters; absent or discarded scalar bodies become `[]`.
     */
    public function getBodyParams(): array|object
    {
        if ($this->adapter !== null) {
            return $this->adapter->getBodyParams($this->methodParam);
        }

        return $this->normalizeParentBodyParams() ?? [];
    }

    /**
     * Returns the current Content-Type header, falling back to Yii when no PSR request is attached.
     *
     * @return string Header value, or an empty `string` when absent.
     */
    public function getContentType(): string
    {
        if ($this->adapter !== null) {
            return $this->getHeaders()->get('Content-Type') ?? '';
        }

        return parent::getContentType();
    }

    /**
     * Returns a read-only collection of current PSR cookies, or Yii's cookies without an attached request.
     *
     * Validated PSR cookies must contain a valid Yii signature and matching encoded cookie name.
     *
     * @throws InvalidConfigException when cookie validation is enabled without a key.
     *
     * @return CookieCollection Current request cookies.
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
     * `csrfHeader`.
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
     * Returns the PSR parsed body, or normalized Yii body parameters without an attached request.
     *
     * @throws InvalidConfigException when a fallback Yii parser definition is invalid.
     *
     * @return array<mixed>|object|null Parsed body; absent or discarded scalar bodies yield `null`.
     */
    public function getParsedBody(): array|object|null
    {
        if ($this->adapter !== null) {
            return $this->adapter->getParsedBody();
        }

        return $this->normalizeParentBodyParams();
    }

    /**
     * Returns the attached PSR request, including any body parsing performed during binding.
     *
     * Call {@see setPsr7Request()} first; this method does not create a request from Yii globals.
     *
     * @throws InvalidConfigException when no PSR request has been attached.
     *
     * @return ServerRequestInterface Attached request.
     */
    public function getPsr7Request(): ServerRequestInterface
    {
        if ($this->adapter === null) {
            throw new InvalidConfigException(Message::PSR7_REQUEST_ADAPTER_NOT_SET->getMessage());
        }

        return $this->adapter->psrRequest;
    }

    /**
     * Returns resolved Yii query parameters when non-empty, otherwise the attached PSR query parameters.
     *
     * Without an attached request, delegates to Yii. Binding a replacement request clears resolved parameters.
     *
     * @return array<mixed> Current query parameters.
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
     * @return array<mixed> Server parameters for the current request.
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
     * Converts attached PSR upload trees to bridge uploaded-file objects, preserving keys and nesting.
     *
     * Successful conversion detaches each PSR upload stream for Yii resource access. Failed uploads retain their
     * error without accessing a stream. Returns `[]` when no PSR request is attached; it does not read `$_FILES`.
     *
     * @return array<mixed> Nested bridge uploaded files.
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
     * Resolves the route with Yii's URL manager and merges route parameters over PSR query parameters.
     *
     * Without an attached request, delegates to Yii's route resolution.
     *
     * @throws NotFoundHttpException when the URL manager cannot resolve the request.
     *
     * @return array<mixed> Route and merged parameters as a two-element array.
     */
    public function resolve(): array
    {
        if ($this->adapter !== null) {
            /** @var array{0: string, 1: array<string, mixed>}|false $result*/
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
     * Binds a PSR request, resets resolved query parameters, and registers its upload adapter.
     *
     * Parses the body only when its parsed body is `null` and a matching configured parser exists. Scalar parser
     * results are discarded. Existing non-`null` parsed bodies, including `[]`, are preserved.
     *
     * @param ServerRequestInterface $request Request to bind.
     *
     * @throws InvalidConfigException when a configured parser does not implement Yii's request parser contract.
     */
    public function setPsr7Request(ServerRequestInterface $request): void
    {
        $this->setQueryParams([]);

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
     * @param array<mixed> $uploadedFiles Array of uploaded files or nested arrays to convert.
     *
     * @return array<mixed> Converted array of Yii UploadedFile instances, preserving keys and nesting.
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
     * For failed uploads (`error` other than `UPLOAD_ERR_OK`), the temporary path and resource stay empty and the
     * PSR-7 stream is never accessed, since PSR-7 implementations reject `getStream()` on error-state uploads.
     *
     * @param UploadedFileInterface $psrFile PSR-7 UploadedFileInterface instance to convert.
     *
     * @return UploadedFile Yii UploadedFile instance created from the PSR-7 UploadedFileInterface.
     */
    private function createUploadedFile(UploadedFileInterface $psrFile): UploadedFile
    {
        $error = $psrFile->getError();

        if ($error !== UPLOAD_ERR_OK) {
            return new UploadedFile(
                [
                    'error' => $error,
                    'name' => $psrFile->getClientFilename() ?? '',
                    'size' => $psrFile->getSize(),
                    'tempName' => '',
                    'type' => $psrFile->getClientMediaType() ?? '',
                    'tempResource' => null,
                ],
            );
        }

        $stream = $psrFile->getStream();

        return new UploadedFile(
            [
                'error' => $error,
                'name' => $psrFile->getClientFilename() ?? '',
                'size' => $psrFile->getSize(),
                'tempName' => $stream->getMetadata('uri') ?? '',
                'type' => $psrFile->getClientMediaType() ?? '',
                'tempResource' => $stream->detach(),
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

    /**
     * Normalizes the parent body parameters to the bridge's `array|object|null` contract.
     *
     * Yii types {@see \yii\web\Request::getBodyParams()} as `mixed` because request parsers may return scalar values
     * for valid scalar payloads; the bridge guarantees `array|object`, so scalar results are discarded.
     *
     * @throws InvalidConfigException if a registered parser does not implement RequestParserInterface.
     *
     * @return array<mixed>|object|null Parsed body parameters, or `null` when the parser yields a scalar value.
     */
    private function normalizeParentBodyParams(): array|object|null
    {
        $bodyParams = parent::getBodyParams();

        return is_array($bodyParams) || is_object($bodyParams) ? $bodyParams : null;
    }
}
