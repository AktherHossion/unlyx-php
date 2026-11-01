<?php

declare(strict_types=1);

namespace Unlyx\Tests;

use PHPUnit\Framework\TestCase;
use Unlyx\Client;
use Unlyx\Model\Order;
use Unlyx\UnlyxException;

final class ClientTest extends TestCase
{
    private MockTransport $http;
    /** @var float[] */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->http = new MockTransport();
        $this->slept = [];
    }

    private function client(array $options = []): Client
    {
        return new Client('https://yourshop.com', 'live_test_key_123', $options + [
            'transport' => $this->http,
            'sleeper' => function (float $s): void { $this->slept[] = $s; },
        ]);
    }

    private static function orderRow(array $over = []): array
    {
        return $over + [
            'reference' => 'UX-1001', 'status' => 'processing', 'dhru_status' => 1,
            'service' => ['id' => 42, 'name' => 'iPhone Carrier Check (GSX)', 'type' => 'imei'],
            'quantity' => 1, 'imei' => '356938035643809', 'fields' => [], 'price' => '1.25', 'currency' => 'USD',
            'reply' => null, 'placed_at' => '2026-11-01T10:00:00+00:00', 'completed_at' => null,
        ];
    }

    public function testRejectsPlainHttpBaseUrl(): void
    {
        $this->expectException(UnlyxException::class);
        new Client('http://yourshop.com', 'k');
    }

    public function testBaseUrlNormalisesApiSuffix(): void
    {
        $this->http->json(200, ['ok' => true, 'data' => ['email' => 'a@b.c', 'balance' => '10.00', 'currency' => 'USD', 'key' => []]]);
        (new Client('https://YourShop.com/api/v1/', 'k', ['transport' => $this->http]))->account();
        self::assertSame('https://yourshop.com/api/v1/account', $this->http->last()->url);
    }

    public function testAccountSendsBearerAndMapsFields(): void
    {
        $this->http->json(200, ['ok' => true, 'data' => [
            'email' => 'reseller@example.com', 'name' => 'North Unlock', 'username' => 'north', 'type' => 'reseller',
            'balance' => '250.40', 'currency' => 'USD',
            'key' => ['name' => 'Panel', 'scopes' => ['account.read', 'orders.place'], 'sandbox' => false, 'expires_at' => null, 'rate_limit_per_min' => 120, 'daily_order_limit' => 0],
        ]]);
        $a = $this->client()->account();
        $req = $this->http->last();
        self::assertSame('GET', $req->method);
        self::assertSame('Bearer live_test_key_123', $req->header('Authorization'));
        self::assertNull($req->body);
        self::assertSame('250.40', $a->balance);
        self::assertSame(120, $a->rateLimitPerMin);
        self::assertTrue($a->can('orders.place'));
        self::assertFalse($a->can('orders.cancel'));
    }

    public function testServicesWithTypeFilter(): void
    {
        $this->http->json(200, ['ok' => true, 'count' => 1, 'data' => [[
            'id' => 7, 'name' => 'Samsung FRP Remove (Server)', 'type' => 'server', 'group' => 'Samsung', 'price' => 3.5, 'currency' => 'USD',
            'delivery_time' => '1-30 min', 'quantity' => ['enabled' => true, 'min' => 1, 'max' => 10, 'options' => [1, 5, 10]],
            'fields' => [['key' => 'serial', 'label' => 'Serial number', 'type' => 'text', 'required' => true, 'help' => null, 'options' => []]],
            'can_cancel' => true,
        ]]]);
        $list = $this->client()->services('server');
        self::assertStringEndsWith('/api/v1/services?type=server', $this->http->last()->url);
        self::assertCount(1, $list);
        self::assertSame('3.5', $list[0]->price);
        self::assertSame([1, 5, 10], $list[0]->quantityOptions);
        self::assertTrue($list[0]->field('serial')->required);
    }

    public function testServicesRejectsUnknownType(): void
    {
        $this->expectException(UnlyxException::class);
        $this->client()->services('phone');
    }

    public function testPlaceOrderSendsJsonAndIdempotencyKey(): void
    {
        $this->http->json(201, ['ok' => true, 'data' => self::orderRow() + ['sandbox' => false], 'replayed' => false, 'balance' => '248.75']);
        $o = $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809'], 'shop-order-1001');
        $req = $this->http->last();
        self::assertSame('POST', $req->method);
        self::assertSame('https://yourshop.com/api/v1/orders', $req->url);
        self::assertSame('shop-order-1001', $req->header('Idempotency-Key'));
        self::assertSame('application/json', $req->header('Content-Type'));
        self::assertSame(['service' => 42, 'imei' => '356938035643809'], json_decode((string) $req->body, true));
        self::assertInstanceOf(Order::class, $o);
        self::assertSame('UX-1001', $o->reference);
        self::assertFalse($o->replayed);
        self::assertSame('248.75', $o->balance);
        self::assertFalse($o->isFinal());
    }

    public function testPlaceOrderGeneratesUuidKeyWhenNoneGiven(): void
    {
        $this->http->json(201, ['ok' => true, 'data' => self::orderRow(), 'replayed' => false, 'balance' => '1']);
        $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $this->http->last()->header('Idempotency-Key'));
    }

    public function testOrderPostRetriedOnlyWhenNotSentAndWithSameKey(): void
    {
        $this->http->fail(false, 'Could not resolve host')
            ->json(201, ['ok' => true, 'data' => self::orderRow(), 'replayed' => false, 'balance' => '1']);
        $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809']);
        self::assertCount(2, $this->http->requests);
        self::assertSame($this->http->requests[0]->header('Idempotency-Key'), $this->http->requests[1]->header('Idempotency-Key'));
    }

    public function testOrderPostNeverRetriedWhenItMayHaveBeenSent(): void
    {
        $this->http->fail(true, 'Operation timed out');
        try {
            $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809']);
            self::fail('expected exception');
        } catch (UnlyxException $e) {
            self::assertSame('connection_error', $e->getErrorCode());
            self::assertSame(0, $e->getHttpStatus());
            self::assertStringContainsString('SAME Idempotency-Key', $e->getMessage());
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testOrderPostNotRetriedOn5xx(): void
    {
        $this->http->json(502, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Bad gateway']]);
        $this->expectException(UnlyxException::class);
        try {
            $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809']);
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testOrderPostRetriedOnShopTemporary503WithSameKey(): void
    {
        $this->http->json(503, ['ok' => false, 'error' => ['code' => 'temporary', 'message' => 'Temporary error — please retry in a moment']])
            ->json(201, ['ok' => true, 'data' => self::orderRow(), 'replayed' => false, 'balance' => '1']);
        $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809'], 'k-503');
        self::assertCount(2, $this->http->requests);
        self::assertSame('k-503', $this->http->requests[1]->header('Idempotency-Key'));
    }

    public function testGetRetriedOn5xxAndLostResponse(): void
    {
        $this->http->json(503, ['ok' => false, 'error' => ['code' => 'unavailable', 'message' => 'x']])
            ->fail(true, 'reset')
            ->json(200, ['ok' => true, 'data' => self::orderRow(['status' => 'completed', 'dhru_status' => 4, 'reply' => 'CODE: 12345678'])]);
        $o = $this->client()->order('UX-1001');
        self::assertCount(3, $this->http->requests);
        self::assertCount(2, $this->slept);
        self::assertTrue($o->isSuccess());
        self::assertSame('CODE: 12345678', $o->reply);
    }

    public function testRetriesStopAtMaxRetries(): void
    {
        $this->http->json(500, [])->json(500, [])->json(500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'down']]);
        try {
            $this->client()->account();
            self::fail('expected exception');
        } catch (UnlyxException $e) {
            self::assertSame('server_error', $e->getErrorCode());
            self::assertSame(500, $e->getHttpStatus());
        }
        self::assertCount(3, $this->http->requests);
    }

    public function testHonours429RetryAfterOnPostWithSameKey(): void
    {
        $this->http->json(429, ['ok' => false, 'error' => ['code' => 'rate_limited', 'message' => 'Rate limit exceeded']], ['Retry-After' => '3'])
            ->json(201, ['ok' => true, 'data' => self::orderRow(), 'replayed' => false, 'balance' => '1']);
        $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809'], 'k-1');
        self::assertSame([3.0], $this->slept);
        self::assertSame('k-1', $this->http->requests[1]->header('Idempotency-Key'));
    }

    public function test429WithoutRetryAfterOrTooLongThrows(): void
    {
        $this->http->json(429, ['ok' => false, 'error' => ['code' => 'limit', 'message' => 'Daily API order limit reached for this account']]);
        try {
            $this->client()->placeOrder(['service' => 42]);
            self::fail('expected');
        } catch (UnlyxException $e) {
            self::assertSame('limit', $e->getErrorCode());
        }
        $this->http->json(429, ['ok' => false, 'error' => ['code' => 'rate_limited', 'message' => 'x']], ['Retry-After' => '600']);
        try {
            $this->client()->account();
            self::fail('expected');
        } catch (UnlyxException $e) {
            self::assertSame(600, $e->getRetryAfter());
        }
        self::assertSame([], $this->slept);
    }

    public function testErrorEnvelopeBecomesException(): void
    {
        $this->http->json(402, ['ok' => false, 'error' => ['code' => 'low_balance', 'message' => 'Insufficient Credit', 'reference' => 'a1b2c3d4']]);
        try {
            $this->client()->placeOrder(['service' => 42, 'imei' => '356938035643809']);
            self::fail('expected');
        } catch (UnlyxException $e) {
            self::assertSame('low_balance', $e->getErrorCode());
            self::assertSame(402, $e->getHttpStatus());
            self::assertSame('Insufficient Credit', $e->getMessage());
            self::assertSame('a1b2c3d4', $e->getReference());
            self::assertSame('low_balance', $e->getBody()['error']['code']);
        }
    }

    public function testRedirectIsNeverFollowed(): void
    {
        $this->http->raw(301, '', ['Location' => 'https://elsewhere.example/api/v1/account']);
        try {
            $this->client()->account();
            self::fail('expected');
        } catch (UnlyxException $e) {
            self::assertSame('unexpected_redirect', $e->getErrorCode());
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testNonJsonAnswer(): void
    {
        $this->http->raw(200, '<html>maintenance</html>');
        try {
            $this->client()->account();
            self::fail('expected');
        } catch (UnlyxException $e) {
            self::assertSame('invalid_response', $e->getErrorCode());
            self::assertSame('<html>maintenance</html>', $e->getRawBody());
        }
    }

    public function testBulkAddsReferencesAndMapsLines(): void
    {
        $this->http->json(200, ['ok' => true, 'balance' => '200.00', 'data' => [
            ['line' => 0, 'reference_in' => 'a-1', 'ok' => true] + self::orderRow(),
            ['line' => 1, 'reference_in' => 'gen', 'ok' => false, 'error' => ['code' => 'invalid_input', 'message' => 'IMEI must be 15 digits']],
        ]]);
        $r = $this->client(['idGenerator' => fn () => 'gen'])->placeOrders([
            ['service' => 42, 'imei' => '356938035643809', 'reference' => 'a-1'],
            ['service' => 42, 'imei' => '123'],
        ]);
        $sent = json_decode((string) $this->http->last()->body, true);
        self::assertStringEndsWith('/api/v1/orders/bulk', $this->http->last()->url);
        self::assertSame('a-1', $sent['orders'][0]['reference']);
        self::assertSame('gen', $sent['orders'][1]['reference']);
        self::assertCount(1, $r->succeeded());
        self::assertSame('invalid_input', $r->failed()[0]->errorCode);
        self::assertSame('UX-1001', $r->lines[0]->order->reference);
        self::assertSame('200.00', $r->balance);
    }

    public function testBulkLimits(): void
    {
        $this->expectException(UnlyxException::class);
        $this->client()->placeOrders(array_fill(0, 101, ['service' => 1]));
    }

    public function testOrdersListFiltersAndPaging(): void
    {
        $this->http->json(200, ['ok' => true, 'page' => 1, 'per_page' => 2, 'total' => 5, 'data' => [self::orderRow(), self::orderRow(['reference' => 'UX-1002'])]]);
        $page = $this->client()->orders(['status' => 'completed', 'since' => new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 'per_page' => 2]);
        self::assertSame('https://yourshop.com/api/v1/orders?status=completed&since=2026-10-01T00%3A00%3A00%2B00%3A00&per_page=2', $this->http->last()->url);
        self::assertCount(2, $page->orders);
        self::assertTrue($page->hasMore());
    }

    public function testOrdersRejectsUnknownFilter(): void
    {
        $this->expectException(UnlyxException::class);
        $this->client()->orders(['colour' => 'red']);
    }

    public function testOrderReferenceIsEncodedAndCancel(): void
    {
        $this->http->json(200, ['ok' => true, 'balance' => '10.00', 'data' => self::orderRow(['status' => 'cancelled', 'dhru_status' => 3])]);
        $o = $this->client()->cancelOrder('UX 1/2');
        self::assertSame('https://yourshop.com/api/v1/orders/UX%201%2F2/cancel', $this->http->last()->url);
        self::assertSame('POST', $this->http->last()->method);
        self::assertNotNull($this->http->last()->header('Idempotency-Key'));
        self::assertTrue($o->isRejected());
        self::assertSame('10.00', $o->balance);
    }

    public function testWebhooksCrud(): void
    {
        $this->http->json(201, ['ok' => true, 'data' => ['id' => 3, 'name' => 'Main', 'url' => 'https://panel.yourshop.com/hooks/unlyx', 'events' => ['order.completed'], 'active' => true, 'secret_preview' => 'whsec_ab…', 'last_status' => null, 'last_attempt_at' => null, 'secret' => 'whsec_abcdef']])
            ->json(200, ['ok' => true, 'data' => [['id' => 3, 'url' => 'https://panel.yourshop.com/hooks/unlyx', 'events' => ['order.completed'], 'active' => true]]])
            ->json(200, ['ok' => true, 'data' => ['deleted' => true]]);
        $c = $this->client();
        $w = $c->createWebhook('https://panel.yourshop.com/hooks/unlyx', ['order.completed'], 'Main');
        self::assertSame('whsec_abcdef', $w->secret);
        self::assertSame(['url' => 'https://panel.yourshop.com/hooks/unlyx', 'events' => ['order.completed'], 'name' => 'Main'], json_decode((string) $this->http->last()->body, true));
        self::assertSame(3, $c->webhooks()[0]->id);
        self::assertTrue($c->deleteWebhook(3));
        self::assertSame('DELETE', $this->http->last()->method);
        self::assertStringEndsWith('/webhooks/3', $this->http->last()->url);
    }

    public function testUsage(): void
    {
        $this->http->json(200, ['ok' => true, 'data' => ['since' => '2026-10-25T00:00:00+00:00', 'calls' => 120, 'errors' => 3, 'by_action' => ['v1:orders.store' => 40], 'recent' => [], 'rate_limit_per_min' => 60]]);
        $u = $this->client()->usage(500);
        self::assertStringEndsWith('/usage?days=90', $this->http->last()->url);
        self::assertSame(120, $u->calls);
        self::assertSame(['v1:orders.store' => 40], $u->byAction);
    }

    public function testRawRequestEscapeHatch(): void
    {
        $this->http->json(200, ['ok' => true, 'data' => [], 'count' => 0]);
        $env = $this->client()->request('get', 'services', ['type' => 'imei']);
        self::assertSame(0, $env['count']);
        self::assertStringEndsWith('/api/v1/services?type=imei', $this->http->last()->url);
    }

    public function testRawRequestRefusesAbsoluteUrlsAndTraversal(): void
    {
        foreach (['https://elsewhere.example/x', '/../admin', '/orders?x=1'] as $bad) {
            try {
                $this->client()->request('GET', $bad);
                self::fail('accepted ' . $bad);
            } catch (UnlyxException $e) {
                self::assertSame('invalid_argument', $e->getErrorCode());
            }
        }
        self::assertSame([], $this->http->requests);
    }

    public function testOptionalRequestSigning(): void
    {
        $this->http->json(201, ['ok' => true, 'data' => self::orderRow(), 'replayed' => false, 'balance' => '1']);
        $this->client(['signingKeyId' => 'kid_1', 'signingSecret' => 'sign-secret'])->placeOrder(['service' => 42, 'imei' => '356938035643809'], 'k-9');
        $req = $this->http->last();
        self::assertSame('kid_1', $req->header('X-Key-Id'));
        $ts = (string) $req->header('X-Timestamp');
        self::assertMatchesRegularExpression('/^\d{10}$/', $ts);
        $expected = hash_hmac('sha256', "POST\n/api/v1/orders\n" . $ts . "\n" . $req->body, 'sign-secret');
        self::assertSame($expected, $req->header('X-Signature'));
    }

    public function testNoSigningHeadersByDefault(): void
    {
        $this->http->json(200, ['ok' => true, 'data' => ['key' => []]]);
        $this->client()->account();
        self::assertNull($this->http->last()->header('X-Signature'));
    }

    public function testSigningNeedsBothParts(): void
    {
        $this->expectException(UnlyxException::class);
        new Client('https://yourshop.com', 'k', ['signingKeyId' => 'kid_1']);
    }
}
