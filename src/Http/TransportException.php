<?php

declare(strict_types=1);

namespace Unlyx\Http;

/**
 * No complete HTTP response was received. $sent tells the client whether the request may have reached the server:
 *  - false: it certainly did not (DNS failure, connection refused, TLS handshake failed) — any request is safe to retry;
 *  - true:  it may have been processed (timeout while waiting, connection reset mid-response).
 */
final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $sent, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
