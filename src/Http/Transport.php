<?php

declare(strict_types=1);

namespace Unlyx\Http;

/** Sends one request. Implement this to plug in your own HTTP stack or a test double. */
interface Transport
{
    /**
     * Must NOT follow redirects. Any HTTP status (including 3xx/4xx/5xx) is a Response, not an exception.
     *
     * @throws TransportException when no complete response was received
     */
    public function send(Request $request): Response;
}
