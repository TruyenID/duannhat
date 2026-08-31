<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\RoleUserPivot;
use App\Models\ToppingGroupItem;
use App\Services\Product\MenuService;
use App\Services\Product\ValueObjects\MenuAvailabilityActor;
use App\Services\Topping\ShopMenuToppingOverrideService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * plan-056 — device-token twin of the POS availability writes.
 *
 * The workstation applies a LAN toggle to its own SQLite immediately (so the
 * shop keeps working with no internet), queues the op, and replays it here
 * when Cloud is reachable. Same door `POST /workstation/tables/{table}/status`
 * uses, and built on the same three rules that door paid for:
 *
 * ## 1. LENIENT on everything except identity and shape
 *
 * Validate the body's types and prove the row belongs to this device's branch.
 * Nothing else. A business rule rejected here is not "one failed request": the
 * op sits at the HEAD of `sync_queue` and blocks every op behind it until a
 * human notices, so a 422 for a rule the POS already enforced client-side
 * costs the shop its whole sync pipeline.
 *
 * ## 2. SET, not toggle
 *
 * Delivery is at-least-once. `is_active: false` replayed five times is still
 * off; "flip it" replayed twice is back on sale.
 *
 * ## 3. The token proves the TERMINAL, never the person
 *
 * `acted_by_user_id` arrives in the BODY — the workstation knows which cashier
 * was signed into pos-web, Cloud does not. It is vetted against the branch's
 * organization before being stored, and silently dropped to null when vetting
 * fails. Dropped, not rejected: an unrecognised staff id must not strand a real
 * "we are out of this dish" behind rule 1. `actor_name` is kept either way, so
 * the shop still reads who the terminal said it was without Cloud asserting an
 * identity it could not verify.
 */
class MenuAvailabilityController extends Controller
{
    public function __construct(
        private readonly MenuService $service,
        private readonly ShopMenuToppingOverrideService $toppings,
    ) {}

    #[OA\Post(
        path: '/api/v1/workstation/menu-products/{menuProduct}/availability',
        summary: 'Sync UP a LAN dish on/off toggle',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'acted_by_user_id', type: 'string', nullable: true, description: 'Vetted against the branch org; dropped to null when it does not check out.'),
                new OA\Property(property: 'actor_name', type: 'string', nullable: true),
                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true, description: 'When the operator tapped — hours before now for an offline shop.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied'),
            new OA\Response(response: 401, description: 'Missing/invalid device token'),
            new OA\Response(response: 404, description: 'Not in this device branch'),
            new OA\Response(response: 422, description: 'Malformed body'),
        ],
    )]
    public function setProductAvailability(Request $request, string $menuProduct): JsonResponse
    {
        $branchId = $this->branchId($request);
        $validated = $this->validateBody($request);

        $row = MenuProduct::query()
            ->whereIn('menu_id', Menu::query()->where('branch_id', $branchId)->select('id'))
            ->with('menu')
            ->findOrFail($menuProduct);

        $updated = $this->service->setProductActiveForShop(
            $row,
            (bool) $validated['is_active'],
            $this->actor($request, $branchId),
            $validated['reason'] ?? null,
            $this->occurredAt($validated),
        );

        return response()->json(['data' => [
            'id' => (string) $updated->id,
            'is_active' => (bool) $updated->is_active,
            'disabled_reason' => $updated->disabled_reason,
            'disabled_at' => $updated->disabled_at?->toIso8601String(),
            'disabled_by_name' => $updated->disabled_by_name,
        ]]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/menu-product-skus/{menuProductSku}/availability',
        summary: 'Sync UP a LAN variant on/off toggle',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [new OA\Parameter(name: 'menuProductSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'acted_by_user_id', type: 'string', nullable: true),
                new OA\Property(property: 'actor_name', type: 'string', nullable: true),
                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied'),
            new OA\Response(response: 404, description: 'Not in this device branch'),
            new OA\Response(response: 422, description: 'Malformed body'),
        ],
    )]
    public function setSkuAvailability(Request $request, string $menuProductSku): JsonResponse
    {
        $branchId = $this->branchId($request);
        $validated = $this->validateBody($request);

        $row = MenuProductSku::query()
            ->whereHas('menuProduct.menu', fn ($q) => $q->where('branch_id', $branchId))
            ->with('menuProduct.menu')
            ->findOrFail($menuProductSku);

        $updated = $this->service->setSkuActiveForShop(
            $row,
            (bool) $validated['is_active'],
            $this->actor($request, $branchId),
            $validated['reason'] ?? null,
            $this->occurredAt($validated),
        );

        return response()->json(['data' => [
            'id' => (string) $updated->id,
            'is_active' => (bool) $updated->is_active,
            'disabled_reason' => $updated->disabled_reason,
            'disabled_at' => $updated->disabled_at?->toIso8601String(),
            'disabled_by_name' => $updated->disabled_by_name,
        ]]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/menu-availability/bulk',
        summary: 'Sync UP a LAN section-wide toggle, as an EXPLICIT id list',
        description: 'The workstation expands "whole section" into the ids that were on screen and sends those. Replaying a section NAME could reach dishes HQ added while the shop was offline — dishes the operator never saw.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['menu_id', 'menu_product_ids', 'is_active'],
            properties: [
                new OA\Property(property: 'menu_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'menu_product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'acted_by_user_id', type: 'string', nullable: true),
                new OA\Property(property: 'actor_name', type: 'string', nullable: true),
                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied', content: new OA\JsonContent(properties: [new OA\Property(property: 'updated', type: 'integer')])),
            new OA\Response(response: 404, description: 'Menu not in this device branch'),
            new OA\Response(response: 422, description: 'Malformed body'),
        ],
    )]
    public function bulk(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);

        $validated = $request->validate([
            'menu_id' => ['required', 'string'],
            'menu_product_ids' => ['required', 'array'],
            'menu_product_ids.*' => ['string'],
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'acted_by_user_id' => ['nullable', 'string'],
            'actor_name' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $menu = Menu::query()
            ->where('branch_id', $branchId)
            ->findOrFail((string) $validated['menu_id']);

        $updated = $this->service->setMenuProductsActiveForShop(
            $menu,
            array_values(array_map(strval(...), $validated['menu_product_ids'])),
            (bool) $validated['is_active'],
            $this->actor($request, $branchId),
            $validated['reason'] ?? null,
            $this->occurredAt($validated),
        );

        return response()->json(['updated' => $updated]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/menu-availability/skus/bulk',
        summary: 'Sync UP a LAN option-value toggle',
        description: 'Backs "turn off size Lon for this dish". Takes the EXPLICIT menu_product_sku id list the workstation resolved when the switch was pressed — an option value has no branch-scoped row, so the write lands on the variant rows that carry it.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['menu_id', 'menu_product_sku_ids', 'is_active'],
            properties: [
                new OA\Property(property: 'menu_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'menu_product_sku_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
            ],
        )),
        responses: [new OA\Response(response: 200, description: 'Applied')],
    )]
    public function bulkSkus(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);

        $validated = $request->validate([
            'menu_id' => ['required', 'string'],
            'menu_product_sku_ids' => ['required', 'array'],
            'menu_product_sku_ids.*' => ['string'],
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'acted_by_user_id' => ['nullable', 'string'],
            'actor_name' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $menu = Menu::query()
            ->where('branch_id', $branchId)
            ->findOrFail((string) $validated['menu_id']);

        $updated = $this->service->setMenuProductSkusActiveForShop(
            $menu,
            array_values(array_map(strval(...), $validated['menu_product_sku_ids'])),
            (bool) $validated['is_active'],
            $this->actor($request, $branchId),
            $validated['reason'] ?? null,
            $this->occurredAt($validated),
        );

        return response()->json(['updated' => $updated]);
    }

    #[OA\Post(
        path: '/api/v1/workstation/menu-products/{menuProduct}/toppings/{toppingItem}/availability',
        summary: 'Sync UP a LAN topping hide/show',
        description: 'Writes ONE shop override row. The admin sync endpoint replaces every override of the group and would wipe the shop topping prices as a side effect.',
        tags: ['Workstation'],
        security: [['device_token' => []]],
        parameters: [
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'toppingItem', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_hidden'],
            properties: [
                new OA\Property(property: 'is_hidden', type: 'boolean', description: 'The STORED sense. The LAN wire speaks is_active; the workstation inverts at its own boundary.'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'acted_by_user_id', type: 'string', nullable: true),
                new OA\Property(property: 'actor_name', type: 'string', nullable: true),
                new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied'),
            new OA\Response(response: 404, description: 'Not in this device branch'),
            new OA\Response(response: 422, description: 'Malformed body'),
        ],
    )]
    public function setToppingAvailability(Request $request, string $menuProduct, string $toppingItem): JsonResponse
    {
        $branchId = $this->branchId($request);

        $validated = $request->validate([
            'is_hidden' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'acted_by_user_id' => ['nullable', 'string'],
            'actor_name' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $row = MenuProduct::query()
            ->whereIn('menu_id', Menu::query()->where('branch_id', $branchId)->select('id'))
            ->with('menu')
            ->findOrFail($menuProduct);

        $item = ToppingGroupItem::query()->findOrFail($toppingItem);

        $hidden = (bool) $validated['is_hidden'];
        $changed = $this->toppings->setItemHiddenForShop($row, $item, $hidden);

        // Only a REAL change is logged — a replayed op is not an event.
        if ($changed) {
            $this->service->recordToppingAvailabilityEvent(
                menuProduct: $row,
                toppingGroupItemId: (string) $item->id,
                isActive: ! $hidden,
                actor: $this->actor($request, $branchId),
                reason: $validated['reason'] ?? null,
                occurredAt: $this->occurredAt($validated),
            );
        }

        return response()->json(['data' => [
            'menu_product_id' => (string) $row->id,
            'topping_group_item_id' => (string) $item->id,
            'is_hidden' => $hidden,
        ]]);
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    private function branchId(Request $request): string
    {
        $device = $request->attributes->get('device');
        if (! $device || ! $device->branch_id) {
            abort(422, 'Device is not associated with an active branch.');
        }

        return (string) $device->branch_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBody(Request $request): array
    {
        return $request->validate([
            'is_active' => ['required', 'boolean'],
            // No `min:` — see the POS-side controller. A reason the shop typed
            // is never worth failing a stock change over.
            'reason' => ['nullable', 'string', 'max:255'],
            'acted_by_user_id' => ['nullable', 'string'],
            'actor_name' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ]);
    }

    private function actor(Request $request, string $branchId): MenuAvailabilityActor
    {
        return MenuAvailabilityActor::fromWorkstation(
            $this->vetUserId($request->input('acted_by_user_id'), $branchId),
            $request->input('actor_name'),
        );
    }

    /**
     * Does this user actually hold a role in the branch's organization?
     *
     * The device token says nothing about who is standing at the terminal, so a
     * body-supplied user id is a CLAIM. Storing it unchecked would put a
     * foreign user on an audit row that reads like Cloud verified it.
     *
     * Returns null on any doubt. That is the safe direction: an event with no
     * actor is incomplete, an event with the WRONG actor is misleading, and
     * `actor_name` still carries what the terminal reported.
     */
    private function vetUserId(mixed $claimed, string $branchId): ?string
    {
        if (! is_string($claimed) || trim($claimed) === '') {
            return null;
        }

        $organizationId = Menu::query()
            ->where('branch_id', $branchId)
            ->value('organization_id');

        if ($organizationId === null) {
            return null;
        }

        $holdsRole = RoleUserPivot::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $claimed)
            ->exists();

        return $holdsRole ? $claimed : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function occurredAt(array $validated): ?CarbonImmutable
    {
        $raw = $validated['occurred_at'] ?? null;

        return is_string($raw) && $raw !== '' ? CarbonImmutable::parse($raw) : null;
    }
}
