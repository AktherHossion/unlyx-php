<?php

declare(strict_types=1);

namespace Unlyx;

/**
 * The one exception the SDK throws.
 *
 * - API errors carry the server's error code (e.g. "low_balance", "invalid_input", "not_found", "service_disabled", "idempotency_conflict", "limit", "temporary", "rate_limited", "unauthorized", "forbidden").
 * - SDK-side failures use: "connection_error", "invalid_response", "unexpected_redirect", "invalid_signature",
 *   "invalid_argument".
 */
class UnlyxException extends \RuntimeException
{
    /** @param array<string,mixed>|null $body */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 0,
        private readonly ?array $body = null,
        private readonly string $rawBody = '',
        private readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /** Machine-readable error code from the API ("error.code"), or one of the SDK codes above. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status, or 0 when no response was received. */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string,mixed>|null the decoded JSON body, when there was one */
    public function getBody(): ?array
    {
        return $this->body;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /** Short support reference the API attaches to every error ("error.reference"). Quote it to the shop. */
    public function getReference(): ?string
    {
        $ref = $this->body['error']['reference'] ?? null;
        return is_string($ref) ? $ref : null;
    }

    /** Seconds from the Retry-After header (429 responses). */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
