<?php

declare(strict_types=1);

namespace Unlyx\Http;

/** Default transport: ext-curl, HTTPS only, never follows redirects, always verifies TLS certificates. */
final class CurlTransport implements Transport
{
    /** curl errors that happen before a single request byte is written. */
    private const NOT_SENT = [
        5,  // CURLE_COULDNT_RESOLVE_PROXY
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        35, // CURLE_SSL_CONNECT_ERROR
        51, // CURLE_PEER_FAILED_VERIFICATION (older libcurl)
        58, // CURLE_SSL_CERTPROBLEM
        60, // CURLE_PEER_FAILED_VERIFICATION / SSL_CACERT
        77, // CURLE_SSL_CACERT_BADFILE
    ];

    /**
     * @param string|null $caBundle path to a CA bundle (cacert.pem) when PHP has none configured
     *                              (common on Windows: "unable to get local issuer certificate")
     */
    public function __construct(private readonly ?string $caBundle = null)
    {
    }

    public function send(Request $request): Response
    {
        $ch = curl_init();
        if ($this->caBundle !== null && $this->caBundle !== '') curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        $headers = [];
        foreach ($request->headers as $k => $v) $headers[] = $k . ': ' . $v;
        $headers[] = 'Expect:'; // no 100-continue round trip

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => (int) round($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($request->connectTimeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $respHeaders = []; // a new status line (e.g. after "100 Continue") starts a fresh header set
                }
                return strlen($line);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($request->body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            // HTTPS: no request byte leaves before the TLS handshake is done (APPCONNECT_TIME stays 0 until then).
            $tlsDone = (float) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) > 0.0;
            curl_close($ch);
            $sent = ! in_array($errno, self::NOT_SENT, true) && $tlsDone;
            throw new TransportException('Connection failed: ' . $error . ' (curl ' . $errno . ')', $sent);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, $respHeaders, (string) $body);
    }
}
