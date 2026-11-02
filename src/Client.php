<?php

declare(strict_types=1);

namespace Unlyx;

use Unlyx\Http\CurlTransport;
use Unlyx\Http\Request;
use Unlyx\Http\Response;
use Unlyx\Http\Transport;
use Unlyx\Http\TransportException;
use Unlyx\Model\Account;
use Unlyx\Model\BulkLine;
use Unlyx\Model\BulkResult;
use Unlyx\Model\Order;
use Unlyx\Model\OrderPage;
use Unlyx\Model\Service;
use Unlyx\Model\Usage;
use Unlyx\Model\Webhook;

/**
 * Unlyx REST API v1 client.
 *
 *     $unlyx = new \Unlyx\Client('https://yourshop.com', getenv('UNLYX_API_KEY'));
 *     $order = $unlyx->placeOrder(['service' => 42, 'imei' => '356938035643809'], 'my-order-1001');
 *
 * Retries (maxRetries, default 2) are only made when they cannot cause a second order:
 *  - the connection failed before the request was sent (any method);
 *  - HTTP 429 with a Retry-After header (the request was refused before it was processed);
 *  - HTTP 5xx or a lost response, for GET only;
 *  - the shop's own 503 "temporary" answer (database busy, nothing placed) on a POST, with the same key.
 * Every POST carries an Idempotency-Key, and a retry re-sends the SAME key, so the shop answers
 * a repeated order with the first one instead of charging twice.
 */
final class Client
{
    public const VERSION = '1.0.2';

    private const MAX_BULK = 100;

    private string $apiBase;
    private Transport $transport;
    private float $timeout;
    private float $connectTimeout;
    private int $maxRetries;
    private int $maxRetryWait;
    private ?string $signingKeyId;
    private ?string $signingSecret;
    private string $userAgent;
    /** @var callable(float):void */
    private $sleeper;
    /** @var callable():string */
    private $idGenerator;

    /**
     * @param string $baseUrl your shop's address, e.g. "https://yourshop.com" ("/api/v1" is added for you)
     * @param string $apiKey  the key from Account → API
     * @param array{
     *     timeout?: float|int,
     *     connectTimeout?: float|int,
     *     maxRetries?: int,
     *     maxRetryWait?: int,
     *     signingKeyId?: string|null,
     *     signingSecret?: string|null,
     *     userAgent?: string,
     *     caBundle?: string,
     *     transport?: Transport,
     *     sleeper?: callable(float):void,
     *     idGenerator?: callable():string,
     * } $options
     */
    public function __construct(string $baseUrl, private readonly string $apiKey, array $options = [])
    {
        $this->apiBase = self::normaliseBaseUrl($baseUrl);
        if ($apiKey === '' || preg_match('/[\r\n\s]/', $apiKey)) {
            throw new UnlyxException('apiKey must be a non-empty string without spaces', 'invalid_argument');
        }
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->connectTimeout = (float) ($options['connectTimeout'] ?? 10);
        $this->maxRetries = max(0, (int) ($options['maxRetries'] ?? 2));
        $this->maxRetryWait = max(0, (int) ($options['maxRetryWait'] ?? 60));
        $this->signingKeyId = isset($options['signingKeyId']) && $options['signingKeyId'] !== '' ? (string) $options['signingKeyId'] : null;
        $this->signingSecret = isset($options['signingSecret']) && $options['signingSecret'] !== '' ? (string) $options['signingSecret'] : null;
        if (($this->signingKeyId === null) !== ($this->signingSecret === null)) {
            throw new UnlyxException('signingKeyId and signingSecret must be set together', 'invalid_argument');
        }
        if ($this->signingKeyId !== null && preg_match('/[\r\n]/', $this->signingKeyId)) {
            throw new UnlyxException('signingKeyId must not contain line breaks', 'invalid_argument');
        }
        $ua = 'unlyx-php/' . self::VERSION . ' (PHP ' . PHP_VERSION . ')';
        $this->userAgent = isset($options['userAgent']) ? trim(str_replace(["\r", "\n"], '', (string) $options['userAgent'])) . ' ' . $ua : $ua;
        $this->transport = $options['transport'] ?? new CurlTransport(isset($options['caBundle']) ? (string) $options['caBundle'] : null);
        $this->sleeper = $options['sleeper'] ?? static function (float $s): void { if ($s > 0) usleep((int) round($s * 1_000_000)); };
        $this->idGenerator = $options['idGenerator'] ?? [self::class, 'uuid'];
    }

    // ---------------------------------------------------------------- account

    /** GET /account — needs scope account.read. */
    public function account(): Account
    {
        return Account::fromArray($this->data($this->request('GET', '/account')));
    }

    /** GET /usage — calls, errors and recent requests for this key over the last $days (1–90). */
    public function usage(int $days = 7): Usage
    {
        return Usage::fromArray($this->data($this->request('GET', '/usage', ['days' => max(1, min(90, $days))])));
    }

    // ---------------------------------------------------------------- services

    /**
     * GET /services — every service this key may order, at its API price. Needs scope services.list.
     *
     * @param string|null $type imei | server | file | remote
     * @return Service[]
     */
    public function services(?string $type = null): array
    {
        if ($type !== null && ! in_array($type, ['imei', 'server', 'file', 'remote'], true)) {
            throw new UnlyxException('type must be imei, server, file or remote', 'invalid_argument');
        }
        $rows = $this->data($this->request('GET', '/services', $type !== null ? ['type' => $type] : []));
        return array_map(static fn ($r) => Service::fromArray(is_array($r) ? $r : []), array_values($rows));
    }

    /** GET /services/{id} — one service, with its description. */
    public function service(int $id): Service
    {
        return Service::fromArray($this->data($this->request('GET', '/services/' . $id)));
    }

    // ---------------------------------------------------------------- orders

    /**
     * POST /orders — place one order. Needs scope orders.place.
     *
     * $order keys: service (int, required), imei (string), quantity (int), fields (array key => value),
     * note (string), file (['name' => 'backup.bin', 'data' => base64]).
     *
     * Pass your own $idempotencyKey (for example your internal order id) so that re-running your code after
     * a crash can never charge twice. Without one a random key is made; it still protects the SDK's own retries.
     *
     * @param array<string,mixed> $order
     */
    public function placeOrder(array $order, ?string $idempotencyKey = null): Order
    {
        if (! isset($order['service']) && ! isset($order['service_id'])) {
            throw new UnlyxException('order needs a "service" id', 'invalid_argument');
        }
        $key = $idempotencyKey ?? ($this->idGenerator)();
        self::checkIdempotencyKey($key);
        $env = $this->request('POST', '/orders', [], $order, ['Idempotency-Key' => $key]);

        return Order::fromArray($this->data($env), (bool) ($env['replayed'] ?? false), isset($env['balance']) ? (string) $env['balance'] : null);
    }

    /**
     * POST /orders/bulk — up to 100 orders; each line answers on its own.
     *
     * Each line's "reference" is that line's idempotency key. Lines without one get a random reference,
     * so a retried call never doubles a line. Set your own references to make re-runs safe too.
     *
     * @param array<int,array<string,mixed>> $orders
     */
    public function placeOrders(array $orders): BulkResult
    {
        if ($orders === [] || count($orders) > self::MAX_BULK) {
            throw new UnlyxException('orders must hold 1 to ' . self::MAX_BULK . ' lines', 'invalid_argument');
        }
        $lines = [];
        foreach (array_values($orders) as $i => $line) {
            if (! is_array($line)) throw new UnlyxException('order line ' . $i . ' must be an array', 'invalid_argument');
            if (! isset($line['reference']) || $line['reference'] === '') $line['reference'] = ($this->idGenerator)();
            $lines[] = $line;
        }
        $env = $this->request('POST', '/orders/bulk', [], ['orders' => $lines]);
        $rows = array_map(static fn ($r) => BulkLine::fromArray(is_array($r) ? $r : []), array_values($this->data($env)));

        return new BulkResult($rows, (string) ($env['balance'] ?? '0'));
    }

    /**
     * GET /orders — newest first. Filters: status, since (date string or DateTimeInterface), imei, page, per_page (1–200).
     *
     * @param array<string,mixed> $filters
     */
    public function orders(array $filters = []): OrderPage
    {
        $query = [];
        foreach (['status', 'since', 'imei', 'page', 'per_page'] as $k) {
            if (! array_key_exists($k, $filters) || $filters[$k] === null || $filters[$k] === '') continue;
            $v = $filters[$k];
            $query[$k] = $v instanceof \DateTimeInterface ? $v->format(\DateTimeInterface::ATOM) : (string) $v;
        }
        $unknown = array_diff(array_keys($filters), ['status', 'since', 'imei', 'page', 'per_page']);
        if ($unknown !== []) throw new UnlyxException('Unknown order filter: ' . implode(', ', $unknown), 'invalid_argument');
        $env = $this->request('GET', '/orders', $query);
        $orders = array_map(static fn ($r) => Order::fromArray(is_array($r) ? $r : []), array_values($this->data($env)));

        return new OrderPage($orders, (int) ($env['page'] ?? 1), (int) ($env['per_page'] ?? count($orders)), (int) ($env['total'] ?? count($orders)));
    }

    /** GET /orders/{reference}. Needs scope orders.read. */
    public function order(string $reference): Order
    {
        return Order::fromArray($this->data($this->request('GET', '/orders/' . self::ref($reference))));
    }

    /** POST /orders/{reference}/cancel — only inside the service's cancel window. Needs scope orders.cancel. */
    public function cancelOrder(string $reference): Order
    {
        $env = $this->request('POST', '/orders/' . self::ref($reference) . '/cancel');

        return Order::fromArray($this->data($env), null, isset($env['balance']) ? (string) $env['balance'] : null);
    }

    // ---------------------------------------------------------------- webhooks

    /** @return Webhook[] GET /webhooks — needs scope webhooks.manage. */
    public function webhooks(): array
    {
        return array_map(static fn ($r) => Webhook::fromArray(is_array($r) ? $r : []), array_values($this->data($this->request('GET', '/webhooks'))));
    }

    /**
     * POST /webhooks — at most 5 per key. The returned Webhook's $secret is shown only now: store it,
     * then check every delivery with Webhook::verify().
     *
     * @param string[] $events e.g. ['order.completed', 'order.rejected'], 'order.*' or '*'
     */
    public function createWebhook(string $url, array $events = ['order.*'], ?string $name = null): Webhook
    {
        $body = ['url' => $url, 'events' => array_values($events)];
        if ($name !== null) $body['name'] = $name;

        return Webhook::fromArray($this->data($this->request('POST', '/webhooks', [], $body)));
    }

    /** DELETE /webhooks/{id}. */
    public function deleteWebhook(int $id): bool
    {
        return (bool) ($this->data($this->request('DELETE', '/webhooks/' . $id))['deleted'] ?? false);
    }

    // ---------------------------------------------------------------- raw

    /**
     * Escape hatch: call any v1 endpoint and get the whole decoded JSON envelope ({"ok":true,"data":…, …}).
     * $path is relative to /api/v1, e.g. "/orders". Errors still throw UnlyxException.
     *
     * @param array<string,scalar|null> $query
     * @param array<string,mixed>|null $body JSON body
     * @param array<string,string> $headers extra headers (e.g. your own Idempotency-Key)
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $headers = []): array
    {
        $method = strtoupper($method);
        if (! preg_match('/^[A-Z]+$/', $method)) throw new UnlyxException('Bad HTTP method', 'invalid_argument');
        if ($path === '' || $path[0] !== '/') $path = '/' . $path;
        if (str_contains($path, '://') || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#') || preg_match('/[\x00-\x20\x7f]/', $path)) {
            throw new UnlyxException('path must be a plain API path like "/orders" (put the query in $query)', 'invalid_argument');
        }
        foreach ($headers as $k => $v) {
            if (preg_match('/[\r\n]/', (string) $k . (string) $v)) throw new UnlyxException('Header values must not contain line breaks', 'invalid_argument');
        }
        $query = array_filter($query, static fn ($v) => $v !== null);
        // Signed requests cover the query too (server signs a canonical path+query), so build the URL from the same
        // canonical, sorted, RFC3986-encoded query string — then the bytes on the wire match what we sign.
        $cq = self::canonicalQuery($query);
        $url = $this->apiBase . $path . ($cq !== '' ? '?' . $cq : '');
        $raw = null;
        if ($body !== null) {
            try {
                $raw = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new UnlyxException('Body cannot be encoded as JSON: ' . $e->getMessage(), 'invalid_argument', 0, null, '', null, $e);
            }
        }
        if ($method === 'POST' && self::findHeader($headers, 'Idempotency-Key') === null) {
            $headers['Idempotency-Key'] = ($this->idGenerator)(); // made once; every retry below re-sends it
        }

        return $this->send($method, $url, $raw, $headers);
    }

    // ---------------------------------------------------------------- webhook helper (static)

    /**
     * Shortcut for Webhook::verify(): check an incoming delivery's signature and return the decoded event.
     *
     * @param array<string,string|string[]> $headers
     * @return array<string,mixed>
     */
    public static function verifyWebhook(string $payload, array $headers, string $secret, int $toleranceSeconds = 300): array
    {
        return Webhook::verify($payload, $headers, $secret, $toleranceSeconds);
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function send(string $method, string $url, ?string $raw, array $headers): array
    {
        $safe = in_array($method, ['GET', 'HEAD'], true);
        $attempt = 0;
        while (true) {
            $request = new Request($method, $url, $this->headersFor($method, $url, $raw, $headers), $raw, $this->timeout, $this->connectTimeout);
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $e) {
                // Not sent at all → safe for any method. Maybe processed → only a GET may be repeated.
                if ((! $e->sent || $safe) && $attempt < $this->maxRetries) {
                    ($this->sleeper)($this->backoff($attempt++));
                    continue;
                }
                $hint = $e->sent && ! $safe ? ' The request may have reached the shop: check the order (or repeat it with the SAME Idempotency-Key) before placing it again.' : '';
                throw new UnlyxException($e->getMessage() . $hint, 'connection_error', 0, null, '', null, $e);
            }

            $status = $response->status;
            if ($status >= 300 && $status < 400) {
                throw new UnlyxException('The API answered with a redirect (HTTP ' . $status . '). Check the base URL; redirects are never followed.', 'unexpected_redirect', $status, null, $response->body);
            }
            $retryAfter = self::retryAfter($response);
            if ($status === 429 && $retryAfter !== null && $retryAfter <= $this->maxRetryWait && $attempt < $this->maxRetries) {
                $attempt++;
                ($this->sleeper)((float) $retryAfter);
                continue;
            }
            if ($status >= 500 && $safe && $attempt < $this->maxRetries) {
                ($this->sleeper)($this->backoff($attempt++));
                continue;
            }
            // The shop's own "temporary" 503 (database busy) means nothing was placed; with the same
            // Idempotency-Key a repeat is answered with the first order if one did get through.
            if ($status === 503 && $method === 'POST' && self::findHeader($headers, 'Idempotency-Key') !== null
                && self::errorCodeOf($response) === 'temporary' && $attempt < $this->maxRetries) {
                ($this->sleeper)($this->backoff($attempt++));
                continue;
            }

            return $this->decode($response, $retryAfter);
        }
    }

    /**
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    private function headersFor(string $method, string $url, ?string $raw, array $extra): array
    {
        $h = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($raw !== null) $h['Content-Type'] = 'application/json';
        if ($this->signingKeyId !== null && $this->signingSecret !== null) {
            $ts = (string) time(); // fresh on every attempt
            $path = (string) parse_url($url, PHP_URL_PATH);
            $q = (string) parse_url($url, PHP_URL_QUERY); // already canonical (sorted, RFC3986) from request()
            if ($q !== '') $path .= '?' . $q;
            $h['X-Key-Id'] = $this->signingKeyId;
            $h['X-Timestamp'] = $ts;
            $h['X-Signature'] = self::requestSignature($this->signingSecret, $method, $path, $ts, $raw ?? '');
        }
        foreach ($extra as $k => $v) $h[(string) $k] = (string) $v;

        return $h;
    }

    /**
     * HMAC-SHA256 (hex) over "{METHOD}\n{path}\n{timestamp}\n{rawBody}". Since 1.0.1 $path is the canonical request
     * target: the URL path, plus "?" and the canonical query string when there are query params (see canonicalQuery).
     */
    public static function requestSignature(string $secret, string $method, string $path, string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $rawBody, $secret);
    }

    /** Canonical query for signing: params sorted by key, each key and value RFC3986-encoded, joined by "&". */
    public static function canonicalQuery(array $query): string
    {
        $query = array_filter($query, static fn ($v) => $v !== null);
        if ($query === []) return '';
        ksort($query);
        $parts = [];
        foreach ($query as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $vv) $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $vv);
        }
        return implode('&', $parts);
    }

    /** @return array<string,mixed> */
    private function decode(Response $response, ?int $retryAfter): array
    {
        $status = $response->status;
        $decoded = null;
        if ($response->body !== '') {
            try {
                $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                $decoded = null;
            }
        }
        if (! is_array($decoded)) {
            if ($status >= 400) {
                throw new UnlyxException('HTTP ' . $status . ' from the API (no JSON body)', $status === 429 ? 'rate_limited' : 'http_' . $status, $status, null, $response->body, $retryAfter);
            }
            throw new UnlyxException('The API answered with something that is not JSON (HTTP ' . $status . ')', 'invalid_response', $status, null, $response->body);
        }
        if ($status >= 400 || ($decoded['ok'] ?? null) === false) {
            $err = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = is_string($err['code'] ?? null) && $err['code'] !== '' ? $err['code'] : ($status === 429 ? 'rate_limited' : 'http_' . $status);
            $message = is_string($err['message'] ?? null) && $err['message'] !== '' ? $err['message'] : 'HTTP ' . $status;
            throw new UnlyxException($message, $code, $status, $decoded, $response->body, $retryAfter);
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $env
     * @return array<mixed>
     */
    private function data(array $env): array
    {
        $d = $env['data'] ?? null;
        if (! is_array($d)) throw new UnlyxException('The API answer has no "data"', 'invalid_response', 200, $env);

        return $d;
    }

    private static function errorCodeOf(Response $r): ?string
    {
        $d = json_decode($r->body, true);
        $code = is_array($d) && is_array($d['error'] ?? null) ? ($d['error']['code'] ?? null) : null;

        return is_string($code) ? strtolower($code) : null;
    }

    private function backoff(int $attempt): float
    {
        return min(8.0, 0.5 * (2 ** $attempt)) + mt_rand(0, 250) / 1000;
    }

    private static function retryAfter(Response $r): ?int
    {
        $v = $r->header('Retry-After');
        if ($v === null || trim($v) === '') return null;
        $v = trim($v);
        if (ctype_digit($v)) return (int) $v;
        $t = strtotime($v);

        return $t === false ? null : max(0, $t - time());
    }

    /** @param array<string,string> $headers */
    private static function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) return (string) $v;
        }

        return null;
    }

    private static function ref(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 120) throw new UnlyxException('reference must be 1–120 characters', 'invalid_argument');

        return rawurlencode($reference);
    }

    private static function checkIdempotencyKey(string $key): void
    {
        if ($key === '' || strlen($key) > 120 || preg_match('/[\r\n]/', $key)) {
            throw new UnlyxException('Idempotency-Key must be 1–120 characters on one line', 'invalid_argument');
        }
    }

    private static function normaliseBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $p = parse_url($baseUrl);
        if ($p === false || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            throw new UnlyxException('baseUrl must be an https:// address, e.g. https://yourshop.com', 'invalid_argument');
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new UnlyxException('baseUrl must not contain credentials, a query or a fragment', 'invalid_argument');
        }
        $path = rtrim($p['path'] ?? '', '/');
        if (str_ends_with(strtolower($path), '/api/v1')) $path = substr($path, 0, -7);
        $host = str_contains($p['host'], ':') && $p['host'][0] !== '[' ? '[' . $p['host'] . ']' : $p['host'];

        return 'https://' . strtolower($host) . (isset($p['port']) ? ':' . $p['port'] : '') . $path . '/api/v1';
    }

    /** RFC 4122 version-4 UUID. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
