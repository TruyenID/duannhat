<?php

namespace App\Services\Payment\Gateway\PayPay;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Temporary Guzzle handler: envelope the outbound PayPay request through an
 * allowlisted hubsupport PHP proxy so PayPay sees that egress IP.
 *
 * Remove once PayPay allowlists Tempo prod (`85.131.214.6`).
 */
final class PayPayEgressProxyHandler
{
    public function __construct(
        private readonly string $proxyUrl,
        private readonly string $token,
    ) {}

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : 30.0;

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) === 'host') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        $body = (string) $request->getBody();
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }

        $client = new Client([
            'timeout' => $timeout,
            'http_errors' => false,
        ]);

        try {
            $proxyResponse = $client->post($this->proxyUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-PayPay-Proxy-Token' => $this->token,
                ],
                'json' => [
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                    'headers' => $headers,
                    'body' => $body,
                ],
            ]);
        } catch (\Throwable $e) {
            return Create::promiseFor(new Response(
                502,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'resultInfo' => [
                        'code' => 'PROXY_TRANSPORT_ERROR',
                        'message' => $e->getMessage(),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ));
        }

        $payload = json_decode((string) $proxyResponse->getBody(), true);
        if (! is_array($payload) || ! ($payload['ok'] ?? false) || ! isset($payload['status'])) {
            $error = is_array($payload) ? (string) ($payload['error'] ?? 'bad proxy response') : 'bad proxy response';

            return Create::promiseFor(new Response(
                502,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'resultInfo' => [
                        'code' => 'PROXY_BAD_RESPONSE',
                        'message' => $error,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ));
        }

        $upstreamHeaders = [];
        foreach (($payload['headers'] ?? []) as $name => $value) {
            $upstreamHeaders[(string) $name] = (string) $value;
        }

        return Create::promiseFor(new Response(
            (int) $payload['status'],
            $upstreamHeaders,
            (string) ($payload['body'] ?? ''),
        ));
    }
}
