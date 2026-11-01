<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** One line of POST /api/v1/orders/bulk. Each line succeeds or fails on its own. */
final class BulkLine
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly int $line,
        /** The reference you sent for this line (it is the line's idempotency key). */
        public readonly ?string $reference,
        public readonly bool $ok,
        public readonly ?Order $order,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        $ok = Cast::bool($d, 'ok');
        $err = Cast::arr($d, 'error');
        return new self(
            Cast::int($d, 'line'), Cast::nstr($d, 'reference_in'), $ok, $ok ? Order::fromArray($d) : null,
            $ok ? null : Cast::nstr($err, 'code'), $ok ? null : Cast::nstr($err, 'message'), $d,
        );
    }
}
