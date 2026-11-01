<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** One input a service asks for (send it in `fields` under its key). */
final class ServiceField
{
    /** @param string[] $options */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required,
        public readonly ?string $help,
        public readonly array $options,
    ) {
    }

    /** @param array<string,mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            Cast::str($d, 'key'), Cast::str($d, 'label'), Cast::str($d, 'type', 'text'), Cast::bool($d, 'required'),
            Cast::nstr($d, 'help'), array_values(array_map('strval', array_filter(Cast::arr($d, 'options'), 'is_scalar'))),
        );
    }
}
