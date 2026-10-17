<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\tests\provider;

/**
 * Data provider for {@see \yii2\extensions\psrbridge\tests\http\WorkerRegressionTest} worker contracts.
 */
final class WorkerRegressionProvider
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function workerContracts(): array
    {
        return [
            'session-handover' => [
                'session-handover',
                [
                    'next' => [
                        'flash' => 'A',
                    ],
                    'later' => [
                        'flash' => null,
                    ],
                ],
            ],
            'yii-overrides' => [
                'yii-overrides',
                [
                    'handler' => true,
                    'secure' => false,
                ],
            ],
            'auth-eager' => [
                'auth-eager',
                [
                    'returning' => [
                        'guest' => false,
                    ],
                    'missing' => [
                        'guest' => true,
                    ],
                    'tampered' => [
                        'guest' => true,
                    ],
                ],
            ],
            'auth-lazy' => [
                'auth-lazy',
                [
                    'returning' => [
                        'guest' => false,
                    ],
                    'missing' => [
                        'guest' => true,
                    ],
                    'tampered' => [
                        'guest' => true,
                    ],
                ],
            ],
            'flash-eager' => [
                'flash-eager',
                [
                    'next' => [
                        'flash' => 'Saved',
                    ],
                    'later' => [
                        'flash' => null,
                    ],
                ],
            ],
            'flash-lazy' => [
                'flash-lazy',
                [
                    'next' => [
                        'flash' => 'Saved',
                    ],
                    'later' => [
                        'flash' => null,
                    ],
                ],
            ],
            'bootstrap-session' => [
                'bootstrap-session',
                [
                    'hasCookie' => true,
                    'nextCount' => 2,
                    'activeInAction' => false,
                ],
            ],
            'cookie-lifetime' => [
                'cookie-lifetime',
                [
                    'expires' => true,
                    'maxAge' => true,
                ],
            ],
            'persistent-events' => [
                'persistent-events',
                [
                    'configured' => 3,
                    'temporary' => 1,
                ],
            ],
            'event-senders' => [
                'event-senders',
                [
                    'alive' => 0,
                ],
            ],
            'error-headers' => [
                'error-headers',
                [
                    'status' => 403,
                    'cors' => 'https://app.example',
                    'cookie' => true,
                ],
            ],
            'request-setters' => [
                'request-setters',
                [
                    'same' => [
                        'scriptUrl' => '/index.php',
                        'header' => 'yes',
                        'body' => [
                            'override' => 'yes',
                        ],
                    ],
                    'nextHeader' => null,
                    'nextBody' => [],
                ],
            ],
            'form-put' => [
                'form-put',
                [
                    'parsed' => [
                        'a' => '1',
                        'b' => '2',
                    ],
                    'preparsed' => [
                        'kept' => 'yes',
                    ],
                ],
            ],
            'form-patch' => [
                'form-patch',
                [
                    'parsed' => [
                        'a' => '1',
                        'b' => '2',
                    ],
                    'preparsed' => [
                        'kept' => 'yes',
                    ],
                ],
            ],
            'form-delete' => [
                'form-delete',
                [
                    'parsed' => [
                        'a' => '1',
                        'b' => '2',
                    ],
                    'preparsed' => [
                        'kept' => 'yes',
                    ],
                ],
            ],
            'creator-headers' => [
                'creator-headers',
                [
                    'contentType' => 'application/json',
                    'contentLength' => '2',
                    'parsedBody' => null,
                ],
            ],
            'https-current' => [
                'https-current',
                [
                    'secure' => true,
                ],
            ],
            'https-stale' => [
                'https-stale',
                [
                    'secure' => false,
                ],
            ],
            'empty-download' => [
                'empty-download',
                [
                    'status' => 200,
                    'length' => '0',
                    'body' => '',
                ],
            ],
            'pipe-download' => [
                'pipe-download',
                [
                    'status' => 200,
                    'length' => '6',
                    'body' => 'abcdef',
                ],
            ],
            'upload-subclass' => [
                'upload-subclass',
                [
                    'single' => true,
                    'list' => true,
                ],
            ],
        ];
    }
}
