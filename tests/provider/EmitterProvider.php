<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

use yii2\extensions\psrbridge\emitter\HttpNoBodyStatus;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\emitter\SapiEmitterTest} class.
 *
 * Designed to ensure the HTTP response emitter correctly handles various response formats, status codes, and content
 * scenarios while maintaining proper HTTP protocol compliance.
 *
 * The test data covers various output scenarios to validate emission integrity for different content sizes and formats,
 * ensuring responses are formatted according to HTTP standards.
 *
 * Key features.
 * - Body chunking with various buffer sizes and ranges.
 * - Content range extraction and validation.
 * - HTTP reason phrase handling with edge cases.
 * - No-body status code compliance ('100', '101', '102', '103', '204', '205', '304').
 * - Response streaming optimization tests.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class EmitterProvider
{
    /**
     * Data provider for testing response body chunking with various buffer sizes.
     *
     * Tests the emitter ability to correctly chunk and stream response bodies.
     * - Different buffer sizes for various content lengths.
     * - Empty content handling for zero-length responses.
     * - Partial content ranges for HTTP range requests.
     * - Response body streaming with default and custom buffer sizes.
     * - Whole versus segmented content transmission scenarios.
     *
     * Each test case provides content, expected chunks, buffer size, and optional range parameters to validate proper
     * response body chunking and range handling across different scenarios.
     *
     * {@see \yii2\extensions\psrbridge\tests\emitter\SapiEmitterTest::testEmitResponseWithVariousBodyContents()} for
     * the test case using this data.
     *
     * @phpstan-return array<array{string, array<string>, int|null, int|null, int|null}>
     */
    public static function body(): array
    {
        return [
            ['', [],  null, null, null],
            ['Contents', ['C', 'o', 'n', 't', 'e', 'n', 't', 's'], 1, 0, 8],
            ['Contents', ['C', 'o', 'n', 't', 'e', 'n', 't', 's'], 1, null, null],
            ['Contents', ['Co', 'nt', 'en', 'ts'], 2, null, null],
            ['Contents', ['Co', 'nt'], 2, 0, 3],
            ['Contents', ['Con', 'ten', 'ts'], 3, null, null],
            ['Contents', ['Con'], 8192, 0, 2],
            ['Contents', ['Content', 's'], 7, 0, 7],
            ['Contents', ['Content', 's'], 7, null, null],
            ['Contents', ['Contents'], 8192, 0, 8],
            ['Contents', ['Contents'], 8192, null, null],
            ['Contents', ['Contents'], null, null, null],
            ['Contents', ['nte', 'nt'], 3, 2, 6],
            ['Contents', ['ts'], 2, 6, 8],
        ];
    }

    /**
     * Data provider for testing HTTP status codes that mustn't include a response body.
     *
     * Tests emitter compliance with HTTP specifications regarding bodiless responses.
     * - '1xx' Informational responses ('100', '101', '102', '103').
     * - '204' No Content status code.
     * - '205' Reset Content status code.
     * - '304' Not Modified status code.
     *
     * Each test case provides a status code and its corresponding reason phrase to verify that the emitter correctly
     * handles these special status codes by not emitting any response body, regardless of whether one exists in the
     * response object.
     *
     * {@see \yii2\extensions\psrbridge\tests\emitter\SapiEmitterTest::testEmitResponseWithNoBodyStatusCodes()} for the
     * test case using this data.
     *
     * @phpstan-return array<array{int, string}>
     */
    public static function noBodyStatusCodes(): array
    {
        return array_map(
            static fn(HttpNoBodyStatus $status): array => [
                $status->value,
                match ($status) {
                    HttpNoBodyStatus::CONTINUE => 'Continue',
                    HttpNoBodyStatus::EARLY_HINTS => 'Early Hints',
                    HttpNoBodyStatus::NO_CONTENT => 'No Content',
                    HttpNoBodyStatus::NOT_MODIFIED => 'Not Modified',
                    HttpNoBodyStatus::PROCESSING => 'Processing',
                    HttpNoBodyStatus::RESET_CONTENT => 'Reset Content',
                    HttpNoBodyStatus::SWITCHING_PROTOCOLS => 'Switching Protocols',
                },
            ],
            HttpNoBodyStatus::cases(),
        );
    }

    /**
     * Data provider for testing HTTP status line formatting with various reason phrases.
     *
     * Tests the emitter formatting of the HTTP status line under different scenarios.
     * - Custom non-standard reason phrases (like "I'm a teapot").
     * - Empty reason phrases which should result in status code only.
     * - Standard reason phrases for common status codes ('200 OK', '404 Not Found').
     * - Whitespace-only reason phrases which should be preserved.
     *
     * Each test case provides a status code, reason phrase, and expected HTTP status line output to verify that the
     * emitter correctly formats the status line according to HTTP specifications while handling edge cases
     * appropriately.
     *
     * {@see \yii2\extensions\psrbridge\tests\emitter\SapiEmitterTest::testEmitResponseWithCustomReasonPhrase()} for the
     * test case using this data.
     *
     * @phpstan-return array<array{int, string, string}>
     */
    public static function reasonPhrase(): array
    {
        return [
            'custom_reason_phrase' => [599, 'I\'m a teapot', 'HTTP/1.1 599 I\'m a teapot'],
            'empty_reason_phrase' => [599, '', 'HTTP/1.1 599'],
            'standard_200' => [200, 'OK', 'HTTP/1.1 200 OK'],
            'standard_404' => [404, 'Not Found', 'HTTP/1.1 404 Not Found'],
            'whitespace_reason_phrase' => [599, ' ', 'HTTP/1.1 599  '],
        ];
    }
}
