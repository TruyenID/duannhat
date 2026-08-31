<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Me\Notification\UpdateMasterMuteRequest;
use App\Http\Requests\Me\Notification\UpdatePreferenceRequest;
use App\Http\Requests\Me\Notification\UpdateQuietHoursRequest;
use App\Models\Notification;
use App\Models\NotificationDigestPreference;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Omnify\Enums\NotificationChannelEnum;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/me/notification-preferences/*` — the authenticated user's
 * per-(type × channel) opt-outs, master mute and quiet hours (plan-012 T3.10).
 *
 * Storage layout mirrors the contract in plan-008 DESIGN.md §Phase C:
 *   - per-(type, channel) rows hold `enabled`
 *   - a single "master" row keyed by `(type='*', channel='*')` holds
 *     `master_mute` + `quiet_from` / `quiet_to` / `quiet_timezone`
 */
class NotificationPreferenceController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/me/notification-preferences',
        summary: "Get the authenticated user's preferences + master mute + quiet hours",
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Preference matrix + master settings'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $prefs = NotificationPreference::query()->where('user_id', $user->id)->get();

        $master = $prefs->firstWhere(fn (NotificationPreference $p) => $p->type === '*' && $p->channel === '*');

        $matrix = $prefs
            ->filter(fn (NotificationPreference $p) => $p->type !== '*' && $p->channel !== '*')
            ->map(fn (NotificationPreference $p) => [
                'type' => $p->type,
                'channel' => $p->channel,
                'enabled' => (bool) $p->enabled,
            ])
            ->values();

        return response()->json([
            'data' => [
                'preferences' => $matrix,
                'master_mute' => (bool) ($master?->master_mute ?? false),
                'quiet_hours' => [
                    'from' => $master?->quiet_from,
                    'to' => $master?->quiet_to,
                    'tz' => $master?->quiet_timezone,
                ],
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/me/notification-preferences/{type}/{channel}',
        summary: 'Toggle a per-(type × channel) opt-out',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['in_app', 'realtime', 'email', 'push'])),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['enabled'],
                properties: [new OA\Property(property: 'enabled', type: 'boolean')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Upserted preference'),
            new OA\Response(response: 422, description: 'Invalid input'),
        ]
    )]
    public function upsertPreference(UpdatePreferenceRequest $request, string $type, string $channel): JsonResponse
    {
        $user = $request->user();

        if (NotificationChannelEnum::tryFrom($channel) === null) {
            abort(422, "Unknown channel: {$channel}");
        }

        $pref = NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'type' => $type,
                'channel' => $channel,
            ],
            [
                'enabled' => (bool) $request->boolean('enabled'),
                'master_mute' => false,
            ],
        );

        return response()->json([
            'data' => [
                'type' => $pref->type,
                'channel' => $pref->channel,
                'enabled' => (bool) $pref->enabled,
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/me/notification-preferences/master-mute',
        summary: "Toggle the master mute on the authenticated user's master pref row",
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['master_mute'],
                properties: [new OA\Property(property: 'master_mute', type: 'boolean')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Master row updated'),
            new OA\Response(response: 422, description: 'Invalid input'),
        ]
    )]
    public function updateMasterMute(UpdateMasterMuteRequest $request): JsonResponse
    {
        $user = $request->user();

        $master = NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'type' => '*',
                'channel' => '*',
            ],
            [
                'master_mute' => (bool) $request->boolean('master_mute'),
                'enabled' => true,
            ],
        );

        return response()->json([
            'data' => [
                'master_mute' => (bool) $master->master_mute,
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/me/notification-preferences/quiet-hours',
        summary: 'Update quiet-hours window on the master pref row',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['from', 'to', 'tz'],
                properties: [
                    new OA\Property(property: 'from', type: 'string', example: '22:00'),
                    new OA\Property(property: 'to', type: 'string', example: '07:00'),
                    new OA\Property(property: 'tz', type: 'string', example: 'Asia/Tokyo'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Quiet hours updated'),
            new OA\Response(response: 422, description: 'Invalid input'),
        ]
    )]
    public function updateQuietHours(UpdateQuietHoursRequest $request): JsonResponse
    {
        $user = $request->user();

        $master = NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'type' => '*',
                'channel' => '*',
            ],
            [
                'enabled' => true,
                'quiet_from' => $request->input('from'),
                'quiet_to' => $request->input('to'),
                'quiet_timezone' => $request->input('tz'),
            ],
        );

        return response()->json([
            'data' => [
                'from' => $master->quiet_from,
                'to' => $master->quiet_to,
                'tz' => $master->quiet_timezone,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/me/notification-preferences/digest',
        summary: 'Read the digest preference row for the authenticated user',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Digest preference')]
    )]
    public function showDigest(Request $request): JsonResponse
    {
        $user = $request->user();
        $pref = NotificationDigestPreference::query()
            ->where('user_id', $user->id)
            ->first();

        return response()->json(['data' => $this->serializeDigest($pref)]);
    }

    #[OA\Patch(
        path: '/api/v1/me/notification-preferences/digest',
        summary: 'Update the digest preference (cadence / time / weekday / priorities)',
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'cadence', type: 'string', enum: ['off', 'daily', 'weekly']),
                    new OA\Property(property: 'delivery_time', type: 'string', pattern: '^\\d{2}:\\d{2}$'),
                    new OA\Property(property: 'timezone', type: 'string'),
                    new OA\Property(property: 'weekday', type: 'integer', minimum: 0, maximum: 6, nullable: true),
                    new OA\Property(
                        property: 'include_priorities',
                        type: 'array',
                        items: new OA\Items(type: 'string', enum: ['low', 'normal', 'high', 'urgent']),
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function updateDigest(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'cadence' => ['required', 'string', Rule::in(['off', 'daily', 'weekly'])],
            'delivery_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => ['required', 'string', 'max:64'],
            'weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'include_priorities' => ['nullable', 'array'],
            'include_priorities.*' => ['string', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        if (! in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            abort(422, "Timezone [{$data['timezone']}] is not a valid IANA name.");
        }

        if ($data['cadence'] === 'weekly' && ! isset($data['weekday'])) {
            abort(422, 'weekday is required when cadence=weekly');
        }

        $pref = NotificationDigestPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'cadence' => $data['cadence'],
                'delivery_time' => $data['delivery_time'],
                'timezone' => $data['timezone'],
                'weekday' => $data['cadence'] === 'weekly' ? ($data['weekday'] ?? 1) : null,
                'include_priorities' => $data['include_priorities']
                    ?? ['urgent', 'high', 'normal', 'low'],
            ],
        );

        return response()->json(['data' => $this->serializeDigest($pref->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDigest(?NotificationDigestPreference $pref): array
    {
        if ($pref === null) {
            return [
                'cadence' => 'off',
                'delivery_time' => '08:00',
                'timezone' => config('app.default_branch_timezone', 'Asia/Tokyo'),
                'weekday' => null,
                'include_priorities' => ['urgent', 'high', 'normal', 'low'],
                'last_sent_at' => null,
            ];
        }

        return [
            'cadence' => $pref->cadence,
            'delivery_time' => $pref->delivery_time,
            'timezone' => $pref->timezone,
            'weekday' => $pref->weekday,
            'include_priorities' => $pref->include_priorities ?? ['urgent', 'high', 'normal', 'low'],
            'last_sent_at' => $pref->last_sent_at?->toIso8601String(),
        ];
    }

    #[OA\Get(
        path: '/api/v1/me/notifications/types',
        summary: "Distinct types present in the authenticated user's inbox (for prefs matrix grouping)",
        tags: ['Me - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Array of type strings'),
        ]
    )]
    public function types(Request $request): JsonResponse
    {
        $user = $request->user();

        $types = NotificationRecipient::query()
            ->forRecipient($user)
            ->join('notifications', 'notification_recipients.notification_id', '=', 'notifications.id')
            ->select('notifications.type')
            ->distinct()
            ->orderBy('notifications.type')
            ->pluck('type')
            ->all();

        return response()->json(['data' => $types]);
    }
}
