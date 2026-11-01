<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** A webhook endpoint owned by the key. It only receives events about this key's own orders. */
final class Webhook
{
    /**
     * @param string[] $events
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly string $url,
        public readonly array $events,
        public readonly bool $active,
        public readonly ?string $secretPreview,
        public readonly ?int $lastStatus,
        public readonly ?string $lastAttemptAt,
        /** The signing secret ("whsec_..."). Only returned ONCE, by createWebhook(). Store it. */
        public readonly ?string $secret,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            Cast::int($d, 'id'), Cast::nstr($d, 'name'), Cast::str($d, 'url'),
            array_values(array_map('strval', array_filter(Cast::arr($d, 'events'), 'is_scalar'))),
            Cast::bool($d, 'active'), Cast::nstr($d, 'secret_preview'), Cast::nint($d, 'last_status'),
            Cast::nstr($d, 'last_attempt_at'), Cast::nstr($d, 'secret'), $d,
        );
    }
}
