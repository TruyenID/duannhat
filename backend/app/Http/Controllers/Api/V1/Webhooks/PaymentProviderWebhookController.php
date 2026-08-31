<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Exceptions\WebhookPayloadConflict;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\ProviderEvent\ProviderEventIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Plan-048 Gate 3 (T3.2) — generic provider webhook intake.
 *
 * POST /api/v1/webhooks/payment/{provider}[?connection={uuid}]
 *
 * Signature is verified per connection BEFORE any persistence; processing is
 * async via the provider-event inbox. The legacy Stripe route
 * (/customer/stripe/webhook) stays as a deprecated alias into the same
 * pipeline until the Dashboard URLs are migrated (ROLLOUT.md Stage C).
 */
class PaymentProviderWebhookController extends Controller
{
    /** Signature material headers forwarded to the adapters. */
    private const SIGNATURE_HEADERS = ['Stripe-Signature', 'PayPay-Signature', 'Authorization'];

    public function __construct(
        private readonly ProviderEventIntakeService $intake,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $providerCode = PaymentGatewayProviderCodeEnum::tryFrom($provider);

        // `internal` is enum-legal but has no webhook surface by definition.
        if ($providerCode === null || $providerCode === PaymentGatewayProviderCodeEnum::Internal) {
            return response()->json(['message' => 'Unknown payment provider.'], 404);
        }

        $headers = [];
        foreach (self::SIGNATURE_HEADERS as $name) {
            $value = (string) $request->header($name, '');
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        $connectionHint = $request->query('connection');

        try {
            $this->intake->intakeProviderWebhook(
                $providerCode,
                $request->getContent(),
                $headers,
                is_string($connectionHint) && $connectionHint !== '' ? $connectionHint : null,
                $request->ip(),
            );
        } catch (WebhookPayloadConflict $e) {
            Log::warning('Provider webhook payload conflict', [
                'provider' => $providerCode->value,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Event payload conflict.'], 409);
        } catch (WebhookVerificationFailed $e) {
            // #2445 — name WHY it failed, not just that it did.
            //
            // PayPay Live has been delivering to this endpoint since at least
            // 2026-08-10 and every delivery was rejected here, but the line as
            // written could not say which of two very different causes it was:
            // the source IP is missing from `services.paypay.webhook_source_ips`,
            // or the payload carries no `notification_type` so a Live
            // connection with no HMAC secret fails closed. The two need
            // opposite fixes and produced byte-identical log lines, so the
            // outage stayed un-diagnosable while the sweep quietly papered over
            // it minutes later.
            //
            // Neither field is a secret: the IP is the peer we already trust or
            // reject on, and this records only the PRESENCE of a payload key,
            // never a value, so nothing signed or personal reaches the log.
            Log::warning('Provider webhook signature verification failed', [
                'provider' => $providerCode->value,
                'error' => $e->getMessage(),
                'client_ip' => $request->ip(),
                'has_notification_type' => $this->payloadHasNotificationType($request),
                // #2453 — `client_ip` alone cannot say WHERE it went wrong.
                //
                // Through CloudFront it reports a different edge address on
                // every request, so the PayPay source-IP allowlist can never
                // match. `trustProxies(at: '*')` is configured and the
                // middleware is in the stack, yet an X-Forwarded-For sent by
                // hand is ignored even straight at the origin — which means the
                // header is being dropped BELOW PHP, not mishandled above it.
                //
                // These two settle it on the next delivery: raw header present
                // and `client_ip` still equal to `remote_addr` = the header
                // never arrived; header absent = whoever sits in front is not
                // sending it.
                'x_forwarded_for' => $request->headers->get('X-Forwarded-For'),
                'remote_addr' => $request->server->get('REMOTE_ADDR'),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\InvalidArgumentException|\JsonException $e) {
            // Malformed input (empty body, broken JSON, bad headers) — client
            // garbage, same bucket as a bad signature.
            Log::warning('Provider webhook intake rejected malformed input', [
                'provider' => $providerCode->value,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\Throwable $e) {
            // #1112 — an internal fault AFTER verification (DB down during
            // inbox persist, queue dispatch failure) must surface as 5xx so
            // the provider retries and dashboards distinguish it from forged
            // traffic. Never answer 400 for our own faults.
            Log::error('Provider webhook intake failed internally', [
                'provider' => $providerCode->value,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook intake failed.'], 500);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Was `notification_type` present in the body? PRESENCE only — the value is
     * never logged.
     *
     * That single key is what routes a PayPay webhook down the OPA source-IP
     * branch instead of the fail-closed one, so it is the first thing anyone
     * debugging a rejected delivery needs to know. Any parse failure answers
     * `false` rather than throwing: this runs inside a catch block whose job is
     * to report a rejection, and it must not become a second failure.
     */
    private function payloadHasNotificationType(Request $request): bool
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($payload)
            && is_string($payload['notification_type'] ?? null)
            && trim($payload['notification_type']) !== '';
    }
}
