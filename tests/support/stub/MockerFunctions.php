<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\support\stub;

use function array_key_exists;
use function explode;
use function str_starts_with;
use function strtolower;

/**
 * Test double for system HTTP and environment functions with controlled state and inspection.
 *
 * Provides comprehensive mock implementations of core PHP HTTP header, response, and environment functions to enable
 * deterministic and isolated testing of emitter, response, and SAPI-dependent logic without side effects or global
 * state changes.
 *
 * This class allows tests to simulate, inspect, and manipulate HTTP header operations, response codes, output flushing,
 * microtime, time and stream reading by maintaining internal state and exposing static methods for fine-grained
 * control.
 *
 * The mock covers all critical PHP functions used in HTTP emission and environment-sensitive code, ensuring that tests
 * can validate emitter logic, header management, response code handling, output flushing, and time-dependent behavior
 * in complete isolation from the PHP runtime.
 *
 * Key features:
 * - Complete simulation of.
 *   - {@see \flush()} (output flush count tracking).
 *   - {@see \header()} (add/replace headers, response code).
 *   - {@see \header_remove()} (single/all headers).
 *   - {@see \headers_list()} (header inspection).
 *   - {@see \headers_sent()} (with file/line tracking).
 *   - {@see \http_response_code()} (get/set response code).
 *   - {@see \microtime()} (mockable time for timing tests).
 *   - {@see \stream_get_contents()} (controllable stream read/failure).
 *   - {@see \time()} (mockable time for timing tests).
 * - Consistent behavior matching PHP native functions for test reliability.
 * - State reset capability for test isolation and repeatability.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class MockerFunctions
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
     * Holds a mocked microtime value for testing purposes.
     *
     * If set, this value will be returned instead of the actual microtime.
     */
    private static float|null $mockedMicrotime = null;

    /**
     * Holds a mocked time value for testing purposes.
     *
     * If set, this value will be returned instead of the actual time.
     */
    private static int|null $mockedTime = null;

    /**
     * Tracks the number of times {@see \ob_end_clean()} was called.
     */
    private static int $obEndCleanCallCount = 0;
    /**
     * Indicates whether {@see \ob_end_clean()} should fail.
     */
    private static bool $obEndCleanShouldFail = false;

    /**
     * Tracks the HTTP response code.
     */
    private static int $responseCode = 200;

    /**
     * Controls whether stream_get_contents should fail.
     */
    private static bool $streamGetContentsShouldFail = false;

    public static function clearMockedMicrotime(): void
    {
        self::$mockedMicrotime = null;
    }

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

    public static function microtime(bool $as_float = false): float|string
    {
        if (self::$mockedMicrotime !== null) {
            return $as_float ? self::$mockedMicrotime : (string) self::$mockedMicrotime;
        }

        return \microtime($as_float);
    }

    public static function ob_end_clean(): bool
    {
        if (self::$obEndCleanShouldFail && self::$obEndCleanCallCount === 0) {
            self::$obEndCleanCallCount++;

            return false;
        }

        return @\ob_end_clean();
    }

    public static function reset(): void
    {
        self::$flushedTimes = 0;
        self::$headers = [];
        self::$headersSent = false;
        self::$headersSentFile = '';
        self::$headersSentLine = 0;
        self::$mockedTime = null;
        self::$obEndCleanCallCount = 0;
        self::$obEndCleanShouldFail = false;
        self::$responseCode = 200;
        self::$streamGetContentsShouldFail = false;

        self::clearMockedMicrotime();
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

    public static function setMockedMicrotime(float $time): void
    {
        self::$mockedMicrotime = $time;
    }

    public static function setMockedTime(int $time): void
    {
        self::$mockedTime = $time;
    }

    public static function setObEndCleanShouldFail(bool $shouldFail = true): void
    {
        self::$obEndCleanShouldFail = $shouldFail;
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

    public static function time(): int
    {
        if (self::$mockedTime !== null) {
            return self::$mockedTime;
        }

        return \time();
    }
}
