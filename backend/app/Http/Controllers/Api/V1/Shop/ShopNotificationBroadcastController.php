<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Exceptions\NotificationException;
use App\Http\Controllers\Api\V1\Shop\Concerns\ShopBoundController;
use App\Http\Controllers\Controller;
use App\Models\NotificationAudience;
use App\Models\NotificationTemplate;
use App\Services\Notification\NotificationService;
use App\Services\Notification\ShopScopedAudienceResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/**
 * `POST /api/v1/shops/{shopSlug}/notifications/broadcast` —
 * shop-level broadcast composer (plan-023 M6 T6.9).
 *
 * Distinct from the HQ broadcast endpoint in two ways:
 *   1. Audience picker is restricted to `branch_id = current_shop`
 *      OR `branch_id IS NULL` (brand-default audiences). A shop
 *      admin cannot pick another shop's saved audience or a brand-
 *      only audience that wasn't explicitly opened to shops (the
 *      filter is the rule, not validation — picking outside it
 *      returns 422 audience_out_of_scope).
 *   2. Even when the chosen audience is brand-wide (branch_id NULL),
 *      the resolved recipient set is intersected with the shop's
 *      memberships via ShopScopedAudienceResolver (T6.2). This is
 *      the structural guard against a shop admin fanning a broadcast
 *      cross-shop.
 */
class ShopNotificationBroadcastController extends Controller
{
    use AuthorizesRequests, ShopBoundController;

    public function __construct(
        private readonly NotificationService $service,
        private readonly ShopScopedAudienceResolver $resolver,
    ) {}

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/notifications/broadcast',
        summary: 'Shop-scoped broadcast (audience intersected with shop members)',
        tags: ['Shop - Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['audience_id', 'template_id', 'channels'],
                properties: [
                    new OA\Property(property: 'audience_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'template_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'urgent']),
                    new OA\Property(property: 'params', type: 'object'),
                    new OA\Property(property: 'scheduled_for', type: 'string', format: 'date-time', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Broadcast dispatched'),
            new OA\Response(response: 422, description: 'audience_out_of_scope / audience_empty / template_out_of_scope'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $shop = $this->requireShop($request);
        $this->authorize('shop.notifications.compose', $shop);

        $data = Validator::make($request->all(), [
            'audience_id' => ['required', 'uuid'],
            'template_id' => ['required', 'uuid'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:in_app,realtime,email,push'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'params' => ['nullable', 'array'],
            'scheduled_for' => ['nullable', 'date'],
        ])->validate();

        // Audience scope guard: must be branch-scoped to current shop OR
        // brand-default (branch_id IS NULL).
        $audience = NotificationAudience::query()
            ->where('id', $data['audience_id'])
            ->where(function ($q) use ($shop) {
                $q->where('branch_id', $shop->id)->orWhereNull('branch_id');
            })
            ->first();
        abort_if($audience === null, 422, 'audience_out_of_scope');

        // Template scope guard: shop override OR brand-wide row visible
        // to this shop's brand.
        $brand = $this->requireBrandForShop($shop);
        $template = NotificationTemplate::query()
            ->where('id', $data['template_id'])
            ->where(function ($q) use ($shop, $brand) {
                $q->where('branch_id', $shop->id)
                    ->orWhere(function ($q2) use ($brand) {
                        $q2->whereNull('branch_id');
                        if ($brand !== null) {
                            $q2->where(fn ($x) => $x->whereNull('brand_id')->orWhere('brand_id', $brand->id));
                        }
                    });
            })
            ->first();
        abort_if($template === null, 422, 'template_out_of_scope');

        $orgIds = $this->shopOrgIds($shop);
        $orgId = $orgIds[0] ?? null;
        abort_if($orgId === null, 422, 'Shop has no local organization.');

        $recipients = $this->resolver->resolveForShop($audience, $brand ?? abort(422, 'brand_unresolved'), $shop);
        if ($recipients->isEmpty()) {
            abort(response()->json(['message' => 'audience_empty'], 422));
        }

        try {
            $notification = $this->service->dispatch([
                'type' => $template->key,
                'template_key' => $template->key,
                'organization_id' => $orgId,
                'brand' => $brand,
                'recipients' => $recipients,
                'params' => (array) $data['params'] ?? [],
                'priority' => $data['priority'] ?? null,
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'audience_id' => $audience->id,
                'channels' => $data['channels'],
                // Stamp shop on the aggregation key so the audit list
                // (T6.10) picks it up via the LIKE pattern.
                'aggregation_key' => "shop.broadcast:branch:{$shop->id}:template:{$template->key}",
            ]);
        } catch (NotificationException $e) {
            abort(response()->json(['message' => $e->getMessage()], $e->statusCode ?: 422));
        }

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'type' => $notification->type,
                'scheduled_for' => $notification->scheduled_for?->toIso8601String(),
            ],
        ], 201);
    }
}
