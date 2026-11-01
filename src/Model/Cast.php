<?php

declare(strict_types=1);

namespace Unlyx\Model;

/** @internal Small, forgiving casts so a new or missing field in an API answer never breaks a caller. */
final class Cast
{
    /** @param array<string,mixed> $a */
    public static function str(array $a, string $k, string $default = ''): string
    {
        $v = $a[$k] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** @param array<string,mixed> $a */
    public static function nstr(array $a, string $k): ?string
    {
        $v = $a[$k] ?? null;
        return is_scalar($v) ? (string) $v : null;
    }

    /** @param array<string,mixed> $a */
    public static function int(array $a, string $k, int $default = 0): int
    {
        $v = $a[$k] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @param array<string,mixed> $a */
    public static function nint(array $a, string $k): ?int
    {
        $v = $a[$k] ?? null;
        return is_numeric($v) ? (int) $v : null;
    }

    /** @param array<string,mixed> $a */
    public static function bool(array $a, string $k, bool $default = false): bool
    {
        return array_key_exists($k, $a) && $a[$k] !== null ? (bool) $a[$k] : $default;
    }

    /**
     * @param array<string,mixed> $a
     * @return array<mixed>
     */
    public static function arr(array $a, string $k): array
    {
        $v = $a[$k] ?? null;
        return is_array($v) ? $v : [];
    }

    /** Money comes back as a string or number; keep it as an exact decimal string. */
    public static function money(array $a, string $k): string
    {
        $v = $a[$k] ?? null;
        if (is_string($v)) return $v;
        if (is_int($v)) return (string) $v;
        if (is_float($v)) return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.') ?: '0';
        return '0';
    }
}
