<?php

declare(strict_types=1);

namespace Unlyx\Model;

final class BulkResult
{
    /** @param BulkLine[] $lines */
    public function __construct(
        public readonly array $lines,
        public readonly string $balance,
    ) {
    }

    /** @return BulkLine[] */
    public function succeeded(): array
    {
        return array_values(array_filter($this->lines, static fn (BulkLine $l) => $l->ok));
    }

    /** @return BulkLine[] */
    public function failed(): array
    {
        return array_values(array_filter($this->lines, static fn (BulkLine $l) => ! $l->ok));
    }
}
