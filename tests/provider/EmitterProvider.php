<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

use yii2\extensions\psrbridge\emitter\HttpNoBodyStatus;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\emitter\SapiEmitterTest} test cases.
 *
 * Provides representative input/output pairs for body chunking, no-body status handling, and reason phrase formatting.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class EmitterProvider
{
    /**
     * Provides test data for response body chunking with various buffer sizes.
     *
     * This provider supplies test cases for validating the emission of response body content in different chunk sizes,
     * buffer ranges, and offsets.
     *
     * Each test case includes the body content, the expected chunks after emission, the buffer size, the start offset,
     * and the end offset.
     *
     * @return array test data with body content, expected chunks, buffer sizes, start offsets, and end offsets.
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
     * Provides test data for HTTP status codes that must not include a response body.
     *
     * This provider supplies test cases for validating emission compliance with HTTP status codes that, according to
     * the protocol, must not include a response body.
     *
     * Each test case consists of the status code integer value and its corresponding reason phrase.
     *
     * @return array test data with status code integers and their reason phrases.
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
     * Provides test data for HTTP status line formatting with various reason phrases.
     *
     * This provider supplies test cases for validating the emission of HTTP status lines with custom, empty, standard,
     * and whitespace reason phrases.
     *
     * Each test case consists of the status code, the reason phrase, and the expected  formatted status line string.
     *
     * @return array test data with status codes, reason phrases, and expected status line strings.
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
