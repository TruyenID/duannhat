<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\Payment\Gateway\Exceptions\WebhookPayloadConflict;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\ProviderEvent\ProviderEventIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function __construct(
        private ProviderEventIntakeService $intake,
    ) {}

    /**
     * Handle incoming Stripe webhook events.
     *
     * Signature is verified before inbox persistence. Processing is async via
     * ProcessPaymentProviderEventJob; replays dedupe on provider event identity.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        try {
            $this->intake->intakeLegacyStripeWebhook($payload, $signature);
        } catch (WebhookPayloadConflict $e) {
            Log::warning('Stripe webhook payload conflict', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Event payload conflict.'], 409);
        } catch (WebhookVerificationFailed $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\InvalidArgumentException|\JsonException $e) {
            Log::warning('Stripe webhook rejected malformed input', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\Throwable $e) {
            // #1112 — internal faults after verification are 5xx (provider
            // retries), never 400 masquerading as a signature failure.
            Log::error('Stripe webhook intake failed internally', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook intake failed.'], 500);
        }

        // Plan-048 Gate 3 (T3.4): this route is a deprecated alias for
        // POST /api/v1/webhooks/payment/stripe. Register the new URL in the
        // Stripe Dashboard; the alias sunsets after 30 days of zero traffic
        // (ROLLOUT.md Stage C).
        return response()->json(['received' => true])
            ->header('Deprecation', 'true')
            ->header('Sunset', 'Tue, 01 Jun 2027 00:00:00 GMT')
            ->header('Link', '</api/v1/webhooks/payment/stripe>; rel="successor-version"');
    }
}
