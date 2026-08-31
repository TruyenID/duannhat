<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailSuppression;
use App\Models\Organization;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/email-suppressions*` — admin
 * audit + manual control over the org-scoped email blocklist
 * (plan-023 M4 T4.8).
 *
 * List / filter / un-suppress are all scoped to the requesting brand's
 * organizations. Manual store lets an admin pre-block an address
 * (reason='manual') before the provider has emitted a webhook event.
 *
 * Un-suppress writes `un_suppressed_at` rather than deleting the row,
 * so the audit trail of past blocks survives. EmailChannel already
 * filters by `WHERE un_suppressed_at IS NULL`, so flipping that column
 * is enough to restore delivery.
 */
class NotificationEmailSuppressionAdminController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/email-suppressions',
        summary: 'List suppressed email addresses for this brand\'s organizations',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reason', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['hard_bounce', 'spam_complaint', 'subscription_change', 'manual'])),
            new OA\Parameter(name: 'active_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'), description: 'Filter by suppressed_at >= from (ISO 8601)'),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'), description: 'Filter by suppressed_at <= to (ISO 8601)'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSuppressions', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        $query = NotificationEmailSuppression::query()
            ->whereIn('organization_id', $orgIds)
            ->orderByDesc('suppressed_at');

        if ($reason = $request->query('reason')) {
            $query->where('reason', $reason);
        }
        if ($request->boolean('active_only', true)) {
            $query->whereNull('un_suppressed_at');
        }
        if ($from = $this->parseDate($request->query('from'))) {
            $query->where('suppressed_at', '>=', $from);
        }
        if ($to = $this->parseDate($request->query('to'))) {
            $query->where('suppressed_at', '<=', $to);
        }

        $paginator = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (NotificationEmailSuppression $s) => $this->toArray($s))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/email-suppressions',
        summary: 'Manually suppress an email address',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', maxLength: 254),
                    new OA\Property(property: 'reason', type: 'string', default: 'manual'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSuppressions', [Notification::class, $brand]);

        $data = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'reason' => ['nullable', 'string', 'in:hard_bounce,spam_complaint,subscription_change,manual'],
        ])->validate();

        $orgIds = $this->brandOrgIds($brand);
        $orgId = $orgIds[0] ?? null;
        abort_if($orgId === null, 422, 'no organization resolvable for this brand');

        $row = NotificationEmailSuppression::query()->updateOrCreate(
            [
                'organization_id' => $orgId,
                'email' => strtolower((string) $data['email']),
                'reason' => $data['reason'] ?? 'manual',
            ],
            [
                'id' => (string) Str::uuid(),
                'source_provider' => 'manual',
                'suppressed_at' => now(),
                'un_suppressed_at' => null,
            ],
        );

        return response()->json(['data' => $this->toArray($row->refresh())], 201);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/email-suppressions/{id}',
        summary: 'Un-suppress an address (writes un_suppressed_at; row preserved for audit)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Un-suppressed'),
            new OA\Response(response: 404, description: 'Not found in this brand\'s organizations'),
        ]
    )]
    public function destroy(Request $request, string $id): Response
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSuppressions', [Notification::class, $brand]);

        $orgIds = $this->brandOrgIds($brand);

        $row = NotificationEmailSuppression::query()
            ->whereIn('organization_id', $orgIds)
            ->findOrFail($id);

        if ($row->un_suppressed_at === null) {
            $row->update(['un_suppressed_at' => now()]);
        }

        return response()->noContent();
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/email-health/metrics',
        summary: 'Aggregate email delivery health metrics (sent / delivered / bounced / spam)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'), description: 'Window start (ISO 8601). Defaults to 30 days ago.'),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'), description: 'Window end (ISO 8601). Defaults to now.'),
        ],
        responses: [new OA\Response(response: 200, description: 'Aggregate counts and the resolved window')]
    )]
    public function metrics(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSuppressions', [Notification::class, $brand]);

        $to = $this->parseDate($request->query('to')) ?? now();
        $from = $this->parseDate($request->query('from')) ?? $to->copy()->subDays(30);

        $orgIds = $this->brandOrgIds($brand);

        // sent / delivered come from notification_deliveries (email channel).
        // We join via notification_recipients to filter by the brand's
        // organizations (deliveries themselves don't carry organization_id).
        $deliveryBase = NotificationDelivery::query()
            ->where('notification_deliveries.channel', 'email')
            ->whereExists(function ($q) use ($orgIds) {
                $q->select('notification_recipients.id')
                    ->from('notification_recipients')
                    ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
                    ->whereColumn('notification_recipients.id', 'notification_deliveries.notification_recipient_id')
                    ->whereIn('notifications.organization_id', $orgIds);
            });

        $sent = (clone $deliveryBase)
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$from, $to])
            ->count();

        $delivered = (clone $deliveryBase)
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from, $to])
            ->count();

        // bounced / spam come from notification_email_suppressions — these are
        // the post-send signals the provider feeds back. We only count rows
        // that are still active (un_suppressed_at IS NULL) so the tile stays
        // in sync with the suppression list it drills into; otherwise an
        // operator who un-suppresses a row sees the tile still showing the
        // event but the list empty, which reads as a bug.
        $suppressionBase = NotificationEmailSuppression::query()
            ->whereIn('organization_id', $orgIds)
            ->whereNull('un_suppressed_at')
            ->whereBetween('suppressed_at', [$from, $to]);

        $bounced = (clone $suppressionBase)->where('reason', 'hard_bounce')->count();
        $spam = (clone $suppressionBase)->where('reason', 'spam_complaint')->count();
        $unsubscribed = (clone $suppressionBase)->where('reason', 'subscription_change')->count();

        return response()->json([
            'data' => [
                'sent' => $sent,
                'delivered' => $delivered,
                'bounced' => $bounced,
                'spam' => $spam,
                'unsubscribed' => $unsubscribed,
            ],
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/email-health/metrics/timeseries',
        summary: 'Per-day breakdown of email health metrics for charting',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [new OA\Response(response: 200, description: 'Daily buckets of sent/delivered/bounced/spam')]
    )]
    public function timeseries(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageSuppressions', [Notification::class, $brand]);

        $to = $this->parseDate($request->query('to')) ?? now();
        $from = $this->parseDate($request->query('from')) ?? $to->copy()->subDays(30);

        $orgIds = $this->brandOrgIds($brand);

        // Build an empty bucket map keyed by YYYY-MM-DD so days with no
        // events still appear in the result — the chart needs zero points
        // to draw a continuous line / accurate bar trend.
        $buckets = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $buckets[$d->format('Y-m-d')] = [
                'date' => $d->format('Y-m-d'),
                'sent' => 0,
                'delivered' => 0,
                'bounced' => 0,
                'spam' => 0,
            ];
        }

        // sent / delivered from notification_deliveries.
        $deliveryRows = NotificationDelivery::query()
            ->where('notification_deliveries.channel', 'email')
            ->whereExists(function ($q) use ($orgIds) {
                $q->select('notification_recipients.id')
                    ->from('notification_recipients')
                    ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
                    ->whereColumn('notification_recipients.id', 'notification_deliveries.notification_recipient_id')
                    ->whereIn('notifications.organization_id', $orgIds);
            })
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sent_at', [$from, $to])
                    ->orWhereBetween('delivered_at', [$from, $to]);
            })
            ->get(['sent_at', 'delivered_at']);

        foreach ($deliveryRows as $row) {
            if ($row->sent_at !== null) {
                $day = Carbon::parse($row->sent_at)->format('Y-m-d');
                if (isset($buckets[$day])) {
                    $buckets[$day]['sent']++;
                }
            }
            if ($row->delivered_at !== null) {
                $day = Carbon::parse($row->delivered_at)->format('Y-m-d');
                if (isset($buckets[$day])) {
                    $buckets[$day]['delivered']++;
                }
            }
        }

        // bounced / spam from active suppressions (consistent with the
        // aggregate metrics endpoint — see the comment on that query for why).
        $suppressionRows = NotificationEmailSuppression::query()
            ->whereIn('organization_id', $orgIds)
            ->whereNull('un_suppressed_at')
            ->whereIn('reason', ['hard_bounce', 'spam_complaint'])
            ->whereBetween('suppressed_at', [$from, $to])
            ->get(['suppressed_at', 'reason']);

        foreach ($suppressionRows as $row) {
            $day = Carbon::parse($row->suppressed_at)->format('Y-m-d');
            if (! isset($buckets[$day])) {
                continue;
            }
            if ($row->reason === 'hard_bounce') {
                $buckets[$day]['bounced']++;
            } elseif ($row->reason === 'spam_complaint') {
                $buckets[$day]['spam']++;
            }
        }

        return response()->json([
            'data' => array_values($buckets),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
        ]);
    }

    /**
     * Best-effort ISO 8601 / date parser. Returns null for empty / invalid
     * input so callers can fall through to defaults instead of 422'ing —
     * the date-range picker on the frontend may emit a blank string while
     * the user is still composing the range.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function brandOrgIds(Brand $brand): array
    {
        return Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(NotificationEmailSuppression $s): array
    {
        return [
            'id' => $s->id,
            'organization_id' => $s->organization_id,
            'email' => $s->email,
            'reason' => $s->reason,
            'source_provider' => $s->source_provider,
            'suppressed_at' => $s->suppressed_at instanceof Carbon ? $s->suppressed_at->toIso8601String() : (string) $s->suppressed_at,
            'un_suppressed_at' => $s->un_suppressed_at instanceof Carbon ? $s->un_suppressed_at?->toIso8601String() : ($s->un_suppressed_at ? (string) $s->un_suppressed_at : null),
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
