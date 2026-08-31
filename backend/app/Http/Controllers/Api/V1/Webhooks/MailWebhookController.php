<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\WebhookVerificationException;
use App\Http\Controllers\Controller;
use App\Jobs\Notification\ApplyEmailEventJob;
use App\Services\Notification\MailWebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * Plan-023 M4 T4.5 — public inbound webhook endpoint for mail providers.
 *
 * Route: `POST /api/v1/webhooks/mail/{provider}` (no auth — provider
 * signature is the auth). Resolved through MailWebhookManager so adding
 * a new provider is a service-provider registration, not a new route.
 *
 * Returns:
 *   - 202 + `{accepted: N}` on success — N is the number of events
 *     dispatched as ApplyEmailEventJob. Unknown event types contribute
 *     0 (logged, not rejected) so the provider stops retrying.
 *   - 401 on signature mismatch / replay (via
 *     WebhookVerificationException::render()).
 *
 * Throttle middleware: 120/min ceiling — well above Postmark's expected
 * peak burst (~50/min for our volume).
 */
class MailWebhookController extends Controller
{
    public function __construct(private readonly MailWebhookManager $manager) {}

    #[OA\Post(
        path: '/api/v1/webhooks/mail/{provider}',
        summary: 'Receive a mail provider webhook (bounce / complaint / delivery / unsubscribe)',
        description: 'Public endpoint, provider signature is the auth. Returns 202 with the count of events queued for async processing. Unknown event types are logged and counted 0 so the provider stops retrying.',
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['postmark'])),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Provider-specific JSON payload',
            content: new OA\JsonContent(type: 'object', additionalProperties: true)
        ),
        responses: [
            new OA\Response(response: 202, description: 'Accepted'),
            new OA\Response(response: 401, description: 'Signature verification failed'),
        ]
    )]
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        try {
            $handler = $this->manager->driver($provider);
        } catch (\InvalidArgumentException $e) {
            Log::warning('mail-webhook.unknown-provider', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "Mail webhook provider [{$provider}] is not registered.",
                'error' => 'webhook_unknown_provider',
            ], 404);
        }

        try {
            $handler->verifySignature($request);
        } catch (WebhookVerificationException $e) {
            Log::warning('mail-webhook.verification-failed', [
                'provider' => $provider,
                'reason' => $e->reason,
            ]);

            return $e->render();
        }

        try {
            $events = $handler->parseEvents($request);
        } catch (\InvalidArgumentException $e) {
            // Unknown event type — log + 202 with 0 accepted so the
            // provider stops retrying. NOT a 4xx because the signature
            // was valid; rejecting would just produce noise.
            Log::warning('mail-webhook.unknown-event', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['accepted' => 0, 'note' => 'unknown_event_type'], 202);
        } catch (Throwable $e) {
            Log::warning('mail-webhook.parse-failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['accepted' => 0, 'note' => 'parse_failed'], 202);
        }

        foreach ($events as $event) {
            ApplyEmailEventJob::dispatch($event);
        }

        return response()->json(['accepted' => count($events)], 202);
    }
}
