<?php

declare(strict_types=1);

namespace Unlyx\Http;

/** One outgoing HTTP request, exactly as it will be sent. */
final class Request
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
        public readonly float $connectTimeout,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) return $v;
        }
        return null;
    }
}
