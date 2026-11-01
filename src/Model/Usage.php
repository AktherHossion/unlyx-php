<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** GET /api/v1/usage — this key's calls over the last N days. */
final class Usage
{
    /**
     * @param array<string,int> $byAction
     * @param array<int,array<string,mixed>> $recent  each: at, protocol, action, status, ok, ms, error
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $since,
        public readonly int $calls,
        public readonly int $errors,
        public readonly array $byAction,
        public readonly array $recent,
        public readonly int $rateLimitPerMin,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            Cast::nstr($d, 'since'), Cast::int($d, 'calls'), Cast::int($d, 'errors'),
            array_map('intval', array_filter(Cast::arr($d, 'by_action'), 'is_numeric')),
            array_values(array_filter(Cast::arr($d, 'recent'), 'is_array')),
            Cast::int($d, 'rate_limit_per_min', 60), $d,
        );
    }
}
