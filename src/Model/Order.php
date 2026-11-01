<?php

declare(strict_types=1);

namespace Unlyx\Model;

/**
 * An order. `status` is the shop's own state (new, processing, waiting_supplier, completed, replied, rejected,
 * cancelled, refunded, ...); `dhruStatus` is the classic Dhru code: 0 new, 1 in process, 3 rejected (refunded), 4 done.
 */
final class Order
{
    /**
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly int $dhruStatus,
        public readonly ?int $serviceId,
        public readonly ?string $serviceName,
        public readonly ?string $serviceType,
        public readonly int $quantity,
        public readonly ?string $imei,
        public readonly array $fields,
        public readonly string $price,
        public readonly ?string $currency,
        /** The result text (unlock code, reply) once the order is done. */
        public readonly ?string $reply,
        public readonly ?string $placedAt,
        public readonly ?string $completedAt,
        public readonly bool $sandbox,
        /** placeOrder() only: true when the Idempotency-Key matched an earlier order (no second charge). */
        public readonly ?bool $replayed,
        /** placeOrder() / cancelOrder() only: balance after the call. */
        public readonly ?string $balance,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d, ?bool $replayed = null, ?string $balance = null): self
    {
        $s = Cast::arr($d, 'service');
        return new self(
            Cast::str($d, 'reference'), Cast::str($d, 'status'), Cast::int($d, 'dhru_status'),
            Cast::nint($s, 'id'), Cast::nstr($s, 'name'), Cast::nstr($s, 'type'),
            Cast::int($d, 'quantity', 1), Cast::nstr($d, 'imei'), Cast::arr($d, 'fields'),
            Cast::money($d, 'price'), Cast::nstr($d, 'currency'), Cast::nstr($d, 'reply'),
            Cast::nstr($d, 'placed_at'), Cast::nstr($d, 'completed_at'), Cast::bool($d, 'sandbox'),
            $replayed, $balance, $d,
        );
    }

    /** Done one way or the other (dhruStatus 3 or 4) — stop polling. */
    public function isFinal(): bool
    {
        return in_array($this->dhruStatus, [3, 4], true);
    }

    /** Delivered: completed / replied (dhruStatus 4). Read the result from $reply. */
    public function isSuccess(): bool
    {
        return $this->dhruStatus === 4;
    }

    /** Rejected, cancelled or refunded (dhruStatus 3) — the money went back to the balance. */
    public function isRejected(): bool
    {
        return $this->dhruStatus === 3;
    }
}
