<?php

declare(strict_types=1);

namespace Unlyx;

/**
 * Checks webhook deliveries from an Unlyx shop.
 *
 * Every delivery is a POST with a JSON body and these headers:
 *   X-Unlyx-Event      order.completed, order.rejected, …
 *   X-Unlyx-Delivery   delivery id (a retry keeps its id — use it to ignore duplicates)
 *   X-Unlyx-Timestamp  unix time the attempt was signed
 *   X-Unlyx-Signature  t=<unix>,v1=<hex HMAC-SHA256 of "<unix>.<raw body>" keyed with your whsec_ secret>
 *
 *     $event = \Unlyx\Webhook::verify(file_get_contents('php://input'), getallheaders(), $secret);
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Unlyx-Signature';
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify the signature on the RAW body (before any JSON parsing) and return the decoded event:
     * ['event' => 'order.completed', 'tenant_id' => …, 'created_at' => '…', 'data' => [...]].
     *
     * @param array<string,string|string[]> $headers request headers, any letter case (getallheaders(), PSR-7 getHeaders(), $_SERVER)
     * @param string|string[] $secret one secret, or several while you rotate
     * @param int $toleranceSeconds reject signatures older/newer than this (replay protection); 0 turns the check off
     * @return array<string,mixed>
     * @throws UnlyxException code "invalid_signature" on any failure
     */
    public static function verify(string $payload, array $headers, string|array $secret, int $toleranceSeconds = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        $header = self::header($headers, self::SIGNATURE_HEADER);
        if ($header === null || $header === '') throw self::fail('Missing ' . self::SIGNATURE_HEADER . ' header');

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't' && ctype_digit($kv[1])) $timestamp = (int) $kv[1];
            if ($kv[0] === 'v1' && $kv[1] !== '') $signatures[] = strtolower($kv[1]);
        }
        if ($timestamp === null || $signatures === []) throw self::fail('Malformed ' . self::SIGNATURE_HEADER . ' header');
        if ($toleranceSeconds > 0 && abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            throw self::fail('Signature timestamp is outside the allowed window (possible replay or clock drift)');
        }

        $secrets = array_values(array_filter(is_array($secret) ? $secret : [$secret], static fn ($s) => is_string($s) && $s !== ''));
        if ($secrets === []) throw new UnlyxException('A webhook secret is required', 'invalid_argument');
        $valid = false;
        foreach ($secrets as $s) {
            $expected = self::sign($s, $timestamp, $payload);
            foreach ($signatures as $sig) {
                if (hash_equals($expected, $sig)) $valid = true; // no early exit: constant work per delivery
            }
        }
        if (! $valid) throw self::fail('Signature does not match');

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw self::fail('Signed body is not valid JSON');
        }
        if (! is_array($event)) throw self::fail('Signed body is not a JSON object');

        return $event;
    }

    /** Same check as verify(), as a boolean. */
    public static function isValid(string $payload, array $headers, string|array $secret, int $toleranceSeconds = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        try {
            self::verify($payload, $headers, $secret, $toleranceSeconds, $now);
            return true;
        } catch (UnlyxException) {
            return false;
        }
    }

    /** hex HMAC-SHA256 of "<timestamp>.<payload>" — the v1 value. */
    public static function sign(string $secret, int $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /** Build a full header value, e.g. for tests of your own receiver. */
    public static function signatureHeader(string $secret, int $timestamp, string $payload): string
    {
        return 't=' . $timestamp . ',v1=' . self::sign($secret, $timestamp, $payload);
    }

    /** @param array<string,string|string[]> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name)); // $_SERVER style
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0 || strcasecmp((string) $k, $server) === 0) {
                if (is_array($v)) $v = reset($v);
                return is_string($v) ? trim($v) : null;
            }
        }

        return null;
    }

    private static function fail(string $message): UnlyxException
    {
        return new UnlyxException($message, 'invalid_signature', 400);
    }
}
