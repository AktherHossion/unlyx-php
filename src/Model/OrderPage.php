<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** One page of GET /api/v1/orders. */
final class OrderPage
{
    /** @param Order[] $orders */
    public function __construct(
        public readonly array $orders,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }
}
