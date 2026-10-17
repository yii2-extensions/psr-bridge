<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\http\ErrorHandlerBoundaryTest} error responses.
 */
final class ErrorHandlerProvider
{
    /**
     * @return array<string, array{string}>
     */
    public static function obsoleteResponseHeaders(): array
    {
        return [
            'redirect-location' => ['Location'],
            'redirect-refresh' => ['Refresh'],
            'apache-sendfile' => ['X-Sendfile'],
            'lighttpd-sendfile' => ['X-Lighttpd-Send-File'],
            'nginx-redirect' => ['X-Accel-Redirect'],
            'nginx-buffering' => ['X-Accel-Buffering'],
            'nginx-charset' => ['X-Accel-Charset'],
            'nginx-expiration' => ['X-Accel-Expires'],
            'nginx-rate-limit' => ['X-Accel-Limit-Rate'],
        ];
    }

    /**
     * @return array<string, array{bool|int, bool, string}>
     */
    public static function renderingViews(): array
    {
        return [
            'debug-internal' => [true, false, 'exception-view'],
            'debug-user' => [true, true, 'error-view'],
            'production-internal' => [false, false, 'error-view'],
            'production-user' => [false, true, 'error-view'],
            'falsy-debug-internal' => [0, false, 'error-view'],
        ];
    }
}
