<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** A service the key may order (GET /api/v1/services, GET /api/v1/services/{id}). Price is the key's own API price. */
final class Service
{
    /**
     * @param int[] $quantityOptions
     * @param ServiceField[] $fields
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        /** imei | server | file | remote */
        public readonly string $type,
        public readonly ?string $group,
        public readonly string $price,
        public readonly string $currency,
        public readonly ?string $deliveryTime,
        public readonly bool $quantityEnabled,
        public readonly int $quantityMin,
        public readonly int $quantityMax,
        public readonly array $quantityOptions,
        public readonly array $fields,
        public readonly bool $canCancel,
        /** Only filled by service($id). */
        public readonly ?string $description,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        $q = Cast::arr($d, 'quantity');
        return new self(
            Cast::int($d, 'id'), Cast::str($d, 'name'), Cast::str($d, 'type'), Cast::nstr($d, 'group'),
            Cast::money($d, 'price'), Cast::str($d, 'currency'), Cast::nstr($d, 'delivery_time'),
            Cast::bool($q, 'enabled'), Cast::int($q, 'min'), Cast::int($q, 'max'),
            array_values(array_map('intval', array_filter(Cast::arr($q, 'options'), 'is_numeric'))),
            array_values(array_map(static fn ($f) => ServiceField::fromArray(is_array($f) ? $f : []), Cast::arr($d, 'fields'))),
            Cast::bool($d, 'can_cancel'), Cast::nstr($d, 'description'), $d,
        );
    }

    public function field(string $key): ?ServiceField
    {
        foreach ($this->fields as $f) {
            if ($f->key === $key) return $f;
        }
        return null;
    }
}
