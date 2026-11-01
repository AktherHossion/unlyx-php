<?php

declare(strict_types=1);

namespace Unlyx\Http;

/** A raw HTTP response. Header names are stored lower-case. */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
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
