<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** GET /api/v1/account — the customer behind the key, the balance, and the key's own settings. */
final class Account
{
    /**
     * @param string[] $scopes
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly ?string $username,
        public readonly ?string $type,
        /** Exact decimal string, e.g. "125.50". */
        public readonly string $balance,
        public readonly string $currency,
        public readonly string $keyName,
        public readonly array $scopes,
        public readonly bool $sandbox,
        public readonly ?string $keyExpiresAt,
        public readonly int $rateLimitPerMin,
        public readonly int $dailyOrderLimit,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        $k = Cast::arr($d, 'key');
        return new self(
            Cast::str($d, 'email'), Cast::str($d, 'name'), Cast::nstr($d, 'username'), Cast::nstr($d, 'type'),
            Cast::money($d, 'balance'), Cast::str($d, 'currency'),
            Cast::str($k, 'name'), array_values(array_map('strval', Cast::arr($k, 'scopes'))), Cast::bool($k, 'sandbox'),
            Cast::nstr($k, 'expires_at'), Cast::int($k, 'rate_limit_per_min', 60), Cast::int($k, 'daily_order_limit'),
            $d,
        );
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
