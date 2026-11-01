<?php

declare(strict_types=1);

namespace Unlyx\Tests;

use PHPUnit\Framework\TestCase;
use Unlyx\UnlyxException;
use Unlyx\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_0123456789';
    private const BODY = '{"event":"order.completed","tenant_id":1,"created_at":"2026-11-01T10:00:00+00:00","data":{"reference":"UX-1001","status":"completed","reply":"CODE: 12345678"}}';

    /** Same formula as the server (WebhookDispatcher::timedSignature). */
    private static function serverHeader(string $secret, int $ts, string $body): string
    {
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    public function testValidDeliveryDecodes(): void
    {
        $ts = 1_793_000_000;
        $event = Webhook::verify(self::BODY, ['x-unlyx-signature' => self::serverHeader(self::SECRET, $ts, self::BODY)], self::SECRET, 300, $ts + 10);
        self::assertSame('order.completed', $event['event']);
        self::assertSame('UX-1001', $event['data']['reference']);
    }

    public function testHeaderShapes(): void
    {
        $ts = 1_793_000_000;
        $h = self::serverHeader(self::SECRET, $ts, self::BODY);
        self::assertTrue(Webhook::isValid(self::BODY, ['X-Unlyx-Signature' => [$h]], self::SECRET, 300, $ts));
        self::assertTrue(Webhook::isValid(self::BODY, ['HTTP_X_UNLYX_SIGNATURE' => $h], self::SECRET, 300, $ts));
    }

    public function testTamperedBodyFails(): void
    {
        $ts = 1_793_000_000;
        $h = self::serverHeader(self::SECRET, $ts, self::BODY);
        $this->expectException(UnlyxException::class);
        Webhook::verify(str_replace('12345678', '00000000', self::BODY), ['X-Unlyx-Signature' => $h], self::SECRET, 300, $ts);
    }

    public function testWrongSecretFails(): void
    {
        $ts = 1_793_000_000;
        self::assertFalse(Webhook::isValid(self::BODY, ['X-Unlyx-Signature' => self::serverHeader('whsec_other', $ts, self::BODY)], self::SECRET, 300, $ts));
    }

    public function testOldTimestampRejected(): void
    {
        $ts = 1_793_000_000;
        try {
            Webhook::verify(self::BODY, ['X-Unlyx-Signature' => self::serverHeader(self::SECRET, $ts, self::BODY)], self::SECRET, 300, $ts + 301);
            self::fail('expected');
        } catch (UnlyxException $e) {
            self::assertSame('invalid_signature', $e->getErrorCode());
        }
        // tolerance 0 = check off
        self::assertTrue(Webhook::isValid(self::BODY, ['X-Unlyx-Signature' => self::serverHeader(self::SECRET, $ts, self::BODY)], self::SECRET, 0, $ts + 99999));
    }

    public function testMissingOrMalformedHeader(): void
    {
        self::assertFalse(Webhook::isValid(self::BODY, [], self::SECRET));
        self::assertFalse(Webhook::isValid(self::BODY, ['X-Unlyx-Signature' => 'garbage'], self::SECRET));
        self::assertFalse(Webhook::isValid(self::BODY, ['X-Unlyx-Signature' => 'v1=abc'], self::SECRET));
    }

    public function testSecretRotationAcceptsAnyListedSecret(): void
    {
        $ts = 1_793_000_000;
        $h = self::serverHeader(self::SECRET, $ts, self::BODY);
        self::assertTrue(Webhook::isValid(self::BODY, ['X-Unlyx-Signature' => $h], ['whsec_new', self::SECRET], 300, $ts));
    }

    public function testSignatureHeaderHelperMatchesServer(): void
    {
        self::assertSame(self::serverHeader(self::SECRET, 1700000000, self::BODY), Webhook::signatureHeader(self::SECRET, 1700000000, self::BODY));
    }
}
