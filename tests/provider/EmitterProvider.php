<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

use yii2\extensions\psrbridge\emitter\HttpNoBodyStatus;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\emitter\SapiEmitterTest} test cases.
 */
final class EmitterProvider
{
    /**
     * @return array<array{string, array<string>, int, int|null, int|null}>
     */
    public static function body(): array
    {
        return [
            ['', [], 8192, null, null],
            ['Contents', ['C', 'o', 'n', 't', 'e', 'n', 't', 's'], 1, 0, 7],
            ['Contents', ['C', 'o', 'n', 't', 'e', 'n', 't', 's'], 1, null, null],
            ['Contents', ['Co', 'nt', 'en', 'ts'], 2, null, null],
            ['Cont', ['Co', 'nt'], 2, 0, 3],
            ['Contents', ['Con', 'ten', 'ts'], 3, null, null],
            ['Con', ['Con'], 8192, 0, 2],
            ['Contents', ['Content', 's'], 7, 0, 7],
            ['Contents', ['Content', 's'], 7, null, null],
            ['Contents', ['Contents'], 8192, 0, 7],
            ['Contents', ['Contents'], 8192, null, null],
            ['Contents', ['Contents'], 8192, null, null],
            ['ntent', ['nte', 'nt'], 3, 2, 6],
            ['ts', ['ts'], 2, 6, 7],
        ];
    }

    /**
     * @return array<array{int, string}>
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
     * @return array<array{int, string, string}>
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
