# unlyx/unlyx-php

Official PHP SDK for the **Unlyx REST API v1** — balance, services, orders (single and bulk), cancel, webhooks and usage for any Unlyx shop.

- PHP 8.1+, only `ext-curl` and `ext-json`. No other dependencies.
- HTTPS only, TLS verified, redirects never followed.
- Every POST carries an `Idempotency-Key`; retries re-send the same key, so a network hiccup can never charge an order twice.
- One exception type: `Unlyx\UnlyxException`.
- `Unlyx\Webhook::verify()` checks signed webhook deliveries.

## Install

```bash
composer require unlyx/unlyx-php
```

Until the package is on Packagist, install it from a copy of this folder:

```json
{
    "repositories": [{ "type": "path", "url": "../sdks/php" }],
    "require": { "unlyx/unlyx-php": "^1.0" }
}
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

$unlyx = new Unlyx\Client('https://yourshop.com', getenv('UNLYX_API_KEY'));

// Balance
$account = $unlyx->account();
echo "{$account->balance} {$account->currency}\n";

// Services this key may order, at its API price
foreach ($unlyx->services('imei') as $service) {
    echo "#{$service->id} {$service->name} — {$service->price} {$service->currency}\n";
}

// Place an order. Use your own order id as the idempotency key:
// running the same code twice gives back the first order, never a second charge.
$order = $unlyx->placeOrder([
    'service' => 42,                       // e.g. "iPhone Carrier + Blacklist Check"
    'imei'    => '356938035643809',
], 'my-order-1001');

echo "{$order->reference} {$order->status}\n";

// Later: check it (or let a webhook tell you)
$order = $unlyx->order($order->reference);
if ($order->isSuccess()) echo "Result: {$order->reply}\n";
```

Get a key in the shop: **Account → API**. Tick *Sandbox* to build without spending money — sandbox orders get a `SANDBOX-` reference and never reach a supplier.

## All methods

| Method | Endpoint | Returns |
|---|---|---|
| `account()` | `GET /account` | `Model\Account` |
| `usage(int $days = 7)` | `GET /usage` | `Model\Usage` |
| `services(?string $type)` | `GET /services` | `Model\Service[]` |
| `service(int $id)` | `GET /services/{id}` | `Model\Service` |
| `placeOrder(array $order, ?string $idempotencyKey)` | `POST /orders` | `Model\Order` (`replayed`, `balance` set) |
| `placeOrders(array $orders)` | `POST /orders/bulk` | `Model\BulkResult` |
| `orders(array $filters)` | `GET /orders` | `Model\OrderPage` |
| `order(string $reference)` | `GET /orders/{reference}` | `Model\Order` |
| `cancelOrder(string $reference)` | `POST /orders/{reference}/cancel` | `Model\Order` |
| `webhooks()` | `GET /webhooks` | `Model\Webhook[]` |
| `createWebhook(string $url, array $events, ?string $name)` | `POST /webhooks` | `Model\Webhook` (`secret` set once) |
| `deleteWebhook(int $id)` | `DELETE /webhooks/{id}` | `bool` |
| `request($method, $path, $query, $body, $headers)` | anything under `/api/v1` | decoded JSON envelope |

Order fields: `service` (required), `imei`, `quantity`, `fields` (`['serial' => 'R58M12ABCDE']`), `note`, `file` (`['name' => 'backup.bin', 'data' => base64_encode($bytes)]`).

`orders()` filters: `status`, `since` (string or `DateTimeInterface`), `imei`, `page`, `per_page` (1–200).

Bulk: up to 100 lines; each line succeeds or fails on its own. A line's `reference` is its idempotency key — lines without one get a random reference so the SDK's retries are safe.

```php
$result = $unlyx->placeOrders([
    ['reference' => 'batch7-1', 'service' => 42, 'imei' => '356938035643809'],
    ['reference' => 'batch7-2', 'service' => 42, 'imei' => '359070071234567'],
]);
foreach ($result->failed() as $line) echo "{$line->reference}: {$line->errorMessage}\n";
```

## Options

```php
$unlyx = new Unlyx\Client('https://yourshop.com', $key, [
    'timeout'        => 30,      // seconds for the whole call
    'connectTimeout' => 10,
    'maxRetries'     => 2,       // 0 turns retries off
    'maxRetryWait'   => 60,      // never sleep longer than this for a Retry-After
    'signingKeyId'   => null,    // optional request signing, see below
    'signingSecret'  => null,
    'caBundle'       => null,    // path to cacert.pem if PHP has no CA bundle (Windows)
    'userAgent'      => 'my-panel/2.3',
]);
```

### When the SDK retries

| Situation | GET | POST |
|---|---|---|
| Connection failed before anything was sent (DNS, refused, TLS) | retried | retried, same Idempotency-Key |
| HTTP 429 with `Retry-After` (≤ `maxRetryWait`) | retried after that wait | retried after that wait, same key |
| HTTP 5xx | retried with backoff | **not retried** (except below) |
| HTTP 503 with error code `temporary` (shop database busy, nothing placed) | retried | retried, same key |
| Timeout / connection lost after sending | retried | **not retried** — check the order, or repeat with the same key |

### Request signing (optional)

If the shop turned on signed requests for your key, pass `signingKeyId` and `signingSecret`. Every request then carries:

```
X-Key-Id:    <signingKeyId>
X-Timestamp: <unix seconds>
X-Signature: hex HMAC-SHA256( "{METHOD}\n{path}\n{timestamp}\n{rawBody}", signingSecret )
```

`path` is the URL path without the query string, e.g. `/api/v1/orders`; `rawBody` is the exact JSON sent (empty for GET).

## Errors

```php
try {
    $unlyx->placeOrder(['service' => 42, 'imei' => '356938035643809'], 'my-order-1002');
} catch (Unlyx\UnlyxException $e) {
    $e->getErrorCode();   // "low_balance", "invalid_input", "not_found", "service_disabled", "idempotency_conflict", "rate_limited", "connection_error" …
    $e->getHttpStatus();  // 402, 404, 409, 422, 429 … (0 = no response)
    $e->getMessage();     // readable text from the shop
    $e->getReference();   // short support reference — quote it to the shop
    $e->getRetryAfter();  // seconds, on 429
    $e->getBody();        // decoded JSON body
}
```

## Webhooks

```php
// webhook.php — read the RAW body before anything parses it
$payload = file_get_contents('php://input');
try {
    $event = Unlyx\Webhook::verify($payload, getallheaders(), getenv('UNLYX_WEBHOOK_SECRET'));
} catch (Unlyx\UnlyxException $e) {
    http_response_code(400);
    exit;
}
// $event['event'] = 'order.completed', $event['data']['reference'], $event['data']['reply'] …
// Use the X-Unlyx-Delivery header to ignore a delivery you already handled.
http_response_code(200);
```

`verify()` checks `X-Unlyx-Signature` (`t=<unix>,v1=<hex HMAC-SHA256 of "<unix>.<body>">`), refuses timestamps more than 5 minutes off (pass a different tolerance as the 4th argument), and accepts an array of secrets while you rotate.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The suite uses a mocked transport (`tests/MockTransport.php`) and never calls a real shop. Plug in your own HTTP stack by implementing `Unlyx\Http\Transport` and passing it as the `transport` option.

## License

MIT
