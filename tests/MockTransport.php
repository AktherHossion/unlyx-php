<?php

declare(strict_types=1);

namespace Unlyx\Tests;

use Unlyx\Http\Request;
use Unlyx\Http\Response;
use Unlyx\Http\Transport;
use Unlyx\Http\TransportException;

/** Plays back queued responses (or transport failures) and records every request it was given. */
final class MockTransport implements Transport
{
    /** @var array<int,Response|TransportException> */
    private array $queue = [];
    /** @var Request[] */
    public array $requests = [];

    /** @param array<string,mixed> $json */
    public function json(int $status, array $json, array $headers = []): self
    {
        $this->queue[] = new Response($status, array_change_key_case($headers, CASE_LOWER), json_encode($json, JSON_UNESCAPED_SLASHES));
        return $this;
    }

    public function raw(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new Response($status, array_change_key_case($headers, CASE_LOWER), $body);
        return $this;
    }

    public function fail(bool $sent, string $message = 'boom'): self
    {
        $this->queue[] = new TransportException($message, $sent);
        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        if ($this->queue === []) throw new \LogicException('MockTransport: no response queued for ' . $request->method . ' ' . $request->url);
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) throw $next;

        return $next;
    }

    public function last(): Request
    {
        return $this->requests[count($this->requests) - 1];
    }
}
