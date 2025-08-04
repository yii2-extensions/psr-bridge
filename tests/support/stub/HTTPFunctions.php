<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use function array_key_exists;
use function explode;
use function str_starts_with;
use function strtolower;

/**
 * Mocks system HTTP functions for emitter and header testing with controlled state and inspection.
 *
 * Provides controlled replacements for core PHP HTTP header and response functions to facilitate testing of HTTP
 * emitter and response-related code without actual header output or side effects.
 *
 * This class allows tests to simulate and inspect HTTP header operations, response codes, and output flushing by
 * maintaining internal state and exposing methods to manipulate and query that state.
 *
 * It enables validation of emitter logic, header management, and response code handling in isolation from PHP's global
 * state.
 *
 * Key features.
 * - Complete simulation of {@see \header()}, {@see \headers_sent()}, {@see \header_remove()}, {@see \headers_list()},
 *   and {@see \http_response_code()}
 * - Consistent behavior matching PHP's native functions for test reliability.
 * - File and line tracking for headers_sent simulation.
 * - Header inspection and manipulation for assertions.
 * - Simulated output flushing and flush count tracking.
 * - State reset capability for test isolation and repeatability.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class HTTPFunctions
{
    /**
     * Tracks the number of times {@see \flush()} was called.
     */
    private static int $flushedTimes = 0;

    /**
     * Tracks the headers sent by the application.
     *
     * @phpstan-var string[][]
     */
    private static array $headers = [];

    /**
     * Indicates whether headers have been sent.
     */
    private static bool $headersSent = false;

    /**
     * Tracks the file and line number where headers were sent.
     */
    private static string $headersSentFile = '';

    /**
     * Tracks the line number where headers were sent.
     */
    private static int $headersSentLine = 0;

    /**
     * Tracks the HTTP response code.
     */
    private static int $responseCode = 200;

    /**
     * Controls whether stream_get_contents should fail.
     */
    private static bool $streamGetContentsShouldFail = false;

    public static function flush(): void
    {
        self::$flushedTimes++;
    }

    public static function getFlushTimes(): int
    {
        return self::$flushedTimes;
    }

    /**
     * @phpstan-return string[]
     */
    public static function getHeader(string $header): array
    {
        return self::$headers[strtolower($header)] ?? [];
    }

    public static function hasHeader(string $header): bool
    {
        return array_key_exists(strtolower($header), self::$headers);
    }

    public static function header(string $string, bool $replace = true, int|null $http_response_code = 0): void
    {
        if (str_starts_with($string, 'HTTP/') === false) {
            $header = strtolower(explode(':', $string, 2)[0]);

            if ($replace || array_key_exists($header, self::$headers) === false) {
                self::$headers[$header] = [];
            }

            self::$headers[$header][] = $string;
        }

        if ($http_response_code > 0) {
            self::$responseCode = $http_response_code;
        }
    }

    public static function header_remove(string|null $header = null): void
    {
        if ($header === null) {
            self::$headers = [];
        } else {
            unset(self::$headers[strtolower($header)]);
        }
    }

    /**
     * @phpstan-return string[]
     */
    public static function headers_list(): array
    {
        $result = [];

        foreach (self::$headers as $values) {
            foreach ($values as $header) {
                $result[] = $header;
            }
        }

        return $result;
    }

    public static function headers_sent(mixed &$file = null, mixed &$line = null): bool
    {
        $file = self::$headersSentFile;
        $line = self::$headersSentLine;

        return self::$headersSent;
    }

    public static function http_response_code(int|null $response_code = 0): int
    {
        if ($response_code > 0) {
            self::$responseCode = $response_code;
        }

        return self::$responseCode;
    }

    public static function reset(): void
    {
        self::$flushedTimes = 0;
        self::$headers = [];
        self::$headersSent = false;
        self::$headersSentFile = '';
        self::$headersSentLine = 0;
        self::$responseCode = 200;
        self::$streamGetContentsShouldFail = false;
    }

    public static function set_headers_sent(bool $value = false, string $file = '', int $line = 0): void
    {
        self::$headersSent = $value;
        self::$headersSentFile = $file;
        self::$headersSentLine = $line;
    }

    public static function set_stream_get_contents_should_fail(bool $shouldFail = true): void
    {
        self::$streamGetContentsShouldFail = $shouldFail;
    }

    public static function stream_get_contents(mixed $resource, int $maxlength = -1, int $offset = -1): string|false
    {
        if (self::$streamGetContentsShouldFail) {
            return false;
        }

        if (is_resource($resource) === false) {
            return false;
        }

        return \stream_get_contents($resource, $maxlength, $offset);
    }
}
