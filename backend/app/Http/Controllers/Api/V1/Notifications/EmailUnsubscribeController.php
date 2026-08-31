<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * One-click email unsubscribe endpoint (issue #173 part A).
 *
 * Mounted as a PUBLIC, signed route — Gmail / Yahoo / Apple Mail post here
 * directly from the inbox UI when the user clicks the inbox-level unsubscribe
 * link, without ever bouncing through the app login. RFC 8058 / RFC 2369:
 *
 *   List-Unsubscribe: <https://api.example/v1/notifications/email/unsubscribe?...>
 *   List-Unsubscribe-Post: List-Unsubscribe=One-Click
 *
 * Auth: Laravel signed URLs (HMAC over the full URL with the app key). No
 * token table needed — the signature itself is the token. Tokens carry the
 * user_id + notification type and expire after 60 days, matching typical
 * email retention windows. Tampering invalidates the signature.
 *
 * Action: flips `NotificationPreference.enabled = false` for that
 * (user × type × email) tuple. Other channels for the same type are
 * left alone — only email got unsubscribed.
 */
class EmailUnsubscribeController extends Controller
{
    #[OA\Get(
        path: '/api/v1/notifications/email/unsubscribe',
        summary: 'One-click email unsubscribe (RFC 8058)',
        tags: ['Notifications - Public'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'expires', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Unsubscribed'),
            new OA\Response(response: 403, description: 'Invalid or expired signature'),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // Signed-route middleware handles HMAC + expiry verification — by the
        // time we reach this controller the signature was valid.
        $userId = (string) $request->query('user', '');
        $type = (string) $request->query('type', '');

        if ($userId === '' || $type === '') {
            return response()->json(['message' => 'missing_params'], 422);
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            // Don't leak whether the user exists — return the same opaque
            // success any list-mailer would send. (Their inbox will soon
            // confirm anyway when no further mail arrives.)
            return response()->json(['message' => 'unsubscribed'], 200);
        }

        // Idempotent upsert — find or create the (user, type, email) row and
        // flip enabled to false. We deliberately scope to the email channel
        // only; users who unsubscribe from email may still want in-app /
        // push for the same type.
        NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'type' => $type,
                'channel' => NotificationChannelEnum::Email->value,
            ],
            [
                'enabled' => false,
            ],
        );

        return response()->json(['message' => 'unsubscribed', 'type' => $type], 200);
    }
}
