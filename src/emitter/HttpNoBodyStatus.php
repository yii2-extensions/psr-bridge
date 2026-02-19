<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\emitter;

/**
 * Defines HTTP status codes that do not allow a response body.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7231 RFC 7231.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
enum HttpNoBodyStatus: int
{
    /**
     * '100' Continue.
     *
     * Indicates that the initial part of a request has been received and the client should continue with the rest of
     * the request or ignore if already finished.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7231#section-6.2.1
     */
    case CONTINUE = 100;

    /**
     * '103' Early Hints.
     *
     * Used primarily with the Link header to allow the user agent to start preloading resources while the server
     * prepares a response.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc8297
     */
    case EARLY_HINTS = 103;

    /**
     * '204' No Content.
     *
     * The server has successfully fulfilled the request, and there is no additional content to send in the response
     * payload body.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7231#section-6.3.5
     */
    case NO_CONTENT = 204;

    /**
     * '304' Not Modified.
     *
     * Indicates that the resource has not been modified since the version specified by the request headers.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7232#section-4.1
     */
    case NOT_MODIFIED = 304;

    /**
     * '102' Processing (WebDAV).
     *
     * Indicates that the server has received and is processing the request, but no response is available yet.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2518#section-10.1
     */
    case PROCESSING = 102;

    /**
     * '205' Reset Content.
     *
     * The server has fulfilled the request, and the user agent should reset the document view which caused the request
     * to be sent.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7231#section-6.3.6
     */
    case RESET_CONTENT = 205;

    /**
     * '101' Switching Protocols.
     *
     * Sent in response to an Upgrade request header from the client and indicates the protocol the server is switching
     * to.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7231#section-6.2.2
     */
    case SWITCHING_PROTOCOLS = 101;

    /**
     * Returns whether the status code should not include a body.
     *
     * @param int $statusCode HTTP status code to check.
     */
    public static function shouldHaveNoBody(int $statusCode): bool
    {
        return match ($statusCode) {
            self::CONTINUE->value,
            self::SWITCHING_PROTOCOLS->value,
            self::PROCESSING->value,
            self::EARLY_HINTS->value,
            self::NO_CONTENT->value,
            self::RESET_CONTENT->value,
            self::NOT_MODIFIED->value => true,
            default => false,
        };
    }
}
