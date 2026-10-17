<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

/**
 * Provides mutable body cases for {@see \yii2\extensions\psrbridge\tests\http\RequestBoundaryTest}.
 */
final class RequestBoundaryProvider
{
    /**
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>, string}>
     */
    public static function mutableBodyOverrides(): array
    {
        return [
            'default method with nested application data' => [
                '_method',
                ['_method' => 'patch', 'title' => 'updated', 'nested' => ['_method' => 'keep']],
                ['title' => 'updated', 'nested' => ['_method' => 'keep']],
                'PATCH',
            ],
            'custom method parameter preserves other keys' => [
                'verb',
                ['verb' => 'PUT', '_method' => 'application data', 'title' => 'updated'],
                ['_method' => 'application data', 'title' => 'updated'],
                'PUT',
            ],
            'rejected safe method falls back to the header' => [
                '_method',
                ['_method' => 'GET', 'title' => 'updated'],
                ['title' => 'updated'],
                'DELETE',
            ],
            'non-string method remains transport-only data' => [
                '_method',
                ['_method' => ['PATCH'], 'title' => 'updated'],
                ['title' => 'updated'],
                'DELETE',
            ],
            'null method matches the attached adapter contract' => [
                '_method',
                ['_method' => null, 'title' => 'updated'],
                ['_method' => null, 'title' => 'updated'],
                'DELETE',
            ],
            'body without a method parameter' => [
                '_method',
                ['title' => 'updated'],
                ['title' => 'updated'],
                'DELETE',
            ],
            'empty replacement suppresses the original parsed body' => [
                '_method',
                [],
                [],
                'DELETE',
            ],
        ];
    }
}
