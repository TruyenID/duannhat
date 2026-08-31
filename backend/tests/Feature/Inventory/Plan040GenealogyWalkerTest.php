<?php

/**
 * plan-040 Cluster G — unified Trace/Recall/Genealogy walker.
 *
 * Scenarios: C7, H7, M11, NEW-CON-1/2/3/4/5/7, H12 + the single-walker arch test.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\GenealogyLinkService;
use App\Services\Inventory\GenealogyWalker;
use App\Services\Inventory\RecallDrillService;
use App\Services\Inventory\RecallService;
use App\Services\Inventory\TraceService;
use Illuminate\Support\Str;

/**
 * Build an isolated org/brand/warehouse/material context for genealogy tests.
 *
 * @return array{org_id: string, brand: Brand, warehouse: Warehouse, material: Material}
 */
function makeGenealogyFixture(?string $slugPrefix = null): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
        'slug' => ($slugPrefix ?? 'gw').'-'.Str::random(5),
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->id,
    ]);
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
    ]);
    $material = Material::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
    ]);

    return ['org_id' => $orgId, 'brand' => $brand, 'warehouse' => $warehouse, 'material' => $material];
}

/**
 * Mint a lot inside a fixture context.
 *
 * @param  array{org_id: string, brand: Brand, warehouse: Warehouse, material: Material}  $ctx
 */
function makeGenealogyLot(array $ctx, array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => $ctx['org_id'],
        'brand_id' => $ctx['brand']->id,
        'material_id' => $ctx['material']->id,
        'warehouse_id' => $ctx['warehouse']->id,
        'source' => 'production',
        'status' => MaterialLotStatusEnum::Active->value,
    ], $overrides));
}

function makeGenealogyEdge(
    string $parentId,
    ?string $childId,
    string $type = 'material_batch',
    ?string $customerOrderId = null,
): GenealogyLink {
    return GenealogyLink::create([
        'parent_lot_id' => $parentId,
        'child_lot_id' => $childId,
        'customer_order_id' => $customerOrderId,
        'qty_consumed' => 10,
        'unit' => 'kg',
        'consumed_at' => now()->subDay(),
        'source_event_type' => $type,
        'source_event_id' => (string) Str::uuid(),
    ]);
}

// =========================================================================
//  C7 — reversed batch excluded from recall blast radius
// =========================================================================

it('C7: excludes reversal-edge children from recall blast radius', function () {
    $ctx = makeGenealogyFixture();
    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $normalChild = makeGenealogyLot($ctx);
    $reversalChild = makeGenealogyLot($ctx);

    makeGenealogyEdge($root->id, $normalChild->id, 'material_batch');
    makeGenealogyEdge($root->id, $reversalChild->id, 'reversal');

    $preview = app(RecallService::class)->preview($root->id);

    expect($preview['affected_lot_ids'])->toContain($root->id)
        ->toContain($normalChild->id)
        ->not->toContain($reversalChild->id);
});

// =========================================================================
//  H7 — recall scoped to caller org (cross-org → 404)
// =========================================================================

it('H7: recall preview/initiate 404 on a root lot owned by another org', function () {
    $orgA = makeGenealogyFixture('org-a');
    $orgB = makeGenealogyFixture('org-b');

    // Caller belongs to org A, with the org-admin role.
    $user = User::factory()->create([
        'console_organization_id' => $orgA['org_id'],
    ]);
    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $user->assignRole($role, $orgA['org_id']);
    $this->actingAs($user);

    // Root lot lives in org B.
    $foreignLot = makeGenealogyLot($orgB, ['source' => 'inbound']);

    $base = "/api/v1/hq/{$orgA['brand']->slug}/recalls";

    $this->postJson("{$base}/preview", ['root_lot_id' => $foreignLot->id])
        ->assertNotFound();

    $this->postJson($base, [
        'root_lot_id' => $foreignLot->id,
        'reason' => 'cross-org attempt',
    ])->assertNotFound();
});

// =========================================================================
//  M11 — cancel releases from the INITIATE snapshot, not a live re-walk
// =========================================================================

it('M11: cancel releases lots from the initiate snapshot even after graph drift', function () {
    $ctx = makeGenealogyFixture();
    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $child = makeGenealogyLot($ctx);
    $edge = makeGenealogyEdge($root->id, $child->id, 'material_batch');

    $service = app(RecallService::class);
    $recall = $service->initiate([
        'root_lot_id' => $root->id,
        'organization_id' => $ctx['org_id'],
        'reason' => 'snapshot test',
        'initiated_by_id' => null,
    ]);

    expect($root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value)
        ->and($child->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value);

    // The live graph drifts AFTER initiate: the root→child edge is removed, so a
    // re-walk would no longer reach `child` and would strand it quarantined.
    GenealogyLink::whereKey($edge->id)->delete();

    $service->cancel($recall->fresh(), 'lab cleared');

    expect($root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value)
        ->and($child->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

// =========================================================================
//  NEW-CON-1 — diamond ancestry not collapsed as a cycle
// =========================================================================

it('NEW-CON-1: a diamond ancestry shows both branches (no false cycle)', function () {
    // G → A → L  and  G → B → L  (L has two parents, both fed by the same G).
    $ctx = makeGenealogyFixture();
    $g = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $a = makeGenealogyLot($ctx);
    $b = makeGenealogyLot($ctx);
    $l = makeGenealogyLot($ctx);

    makeGenealogyEdge($g->id, $a->id);
    makeGenealogyEdge($g->id, $b->id);
    makeGenealogyEdge($a->id, $l->id);
    makeGenealogyEdge($b->id, $l->id);

    $tree = app(GenealogyWalker::class)->traceLot($l->id, 'backward', 10);

    expect($tree['parents'])->toHaveCount(2);

    // BOTH parents (A and B) must expand G as a real node — not one real + one
    // {cycle:true} stub (the pre-fix shared-visited bug).
    $grandparentIds = collect($tree['parents'])
        ->flatMap(fn ($p) => collect($p['parents'] ?? []))
        ->map(fn ($gp) => $gp['lot_id'] ?? null)
        ->all();
    $cycleFlags = collect($tree['parents'])
        ->flatMap(fn ($p) => collect($p['parents'] ?? []))
        ->map(fn ($gp) => $gp['cycle'] ?? false)
        ->all();

    expect($grandparentIds)->toBe([$g->id, $g->id])
        ->and($cycleFlags)->toBe([false, false]);
});

// =========================================================================
//  NEW-CON-2 — both-direction walks use independent visited sets
// =========================================================================

it('NEW-CON-2: traceLot(both) uses independent visited per direction', function () {
    // A → P  (A is a parent of pivot P);  P → B → A  (A is also a descendant of P).
    // With a SHARED visited set, the backward walk marks A visited and the
    // forward walk then collapses A into a false cycle. Independent sets fix it.
    $ctx = makeGenealogyFixture();
    $a = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $p = makeGenealogyLot($ctx);
    $b = makeGenealogyLot($ctx);

    makeGenealogyEdge($a->id, $p->id);
    makeGenealogyEdge($p->id, $b->id);
    makeGenealogyEdge($b->id, $a->id);

    $tree = app(GenealogyWalker::class)->traceLot($p->id, 'both', 10);

    // Backward: A is the single parent.
    expect($tree['parents'])->toHaveCount(1)
        ->and($tree['parents'][0]['lot_id'])->toBe($a->id);

    // Forward: P → B → A, and A must be a FULL node (lot_code present), not a
    // cycle stub polluted by the backward walk.
    expect($tree['children'])->toHaveCount(1)
        ->and($tree['children'][0]['lot_id'])->toBe($b->id);

    $bChildren = $tree['children'][0]['children'];
    expect($bChildren)->toHaveCount(1)
        ->and($bChildren[0]['lot_id'])->toBe($a->id)
        ->and($bChildren[0]['cycle'] ?? false)->toBeFalse()
        ->and($bChildren[0])->toHaveKey('lot_code');
});

// =========================================================================
//  NEW-CON-3 — drill blast radius identical to recall preview
// =========================================================================

it('NEW-CON-3: RecallDrill filters reversal/customer_order edges identically to RecallService', function () {
    $ctx = makeGenealogyFixture();
    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $child = makeGenealogyLot($ctx);
    $reversalChild = makeGenealogyLot($ctx);
    $order = CustomerOrder::factory()->create(['brand_id' => $ctx['brand']->id]);

    makeGenealogyEdge($root->id, $child->id, 'material_batch');
    makeGenealogyEdge($root->id, $reversalChild->id, 'reversal');
    makeGenealogyEdge($child->id, null, 'customer_order', $order->id);
    // A non-sales edge with an order id that must NOT count as an affected order.
    makeGenealogyEdge($child->id, null, 'manual_adjustment', (string) Str::uuid());

    $preview = app(RecallService::class)->preview($root->id);
    $drill = app(RecallDrillService::class)->run($ctx['brand']->id, $ctx['org_id'], (string) Str::uuid(), $root->id);

    // Same lot set (root + the one non-reversal child) and same order count.
    $previewLots = collect($preview['affected_lot_ids'])->sort()->values()->all();
    expect($previewLots)->toBe(collect([$root->id, $child->id])->sort()->values()->all())
        ->and($drill->affected_lots_count)->toBe(count($previewLots))
        ->and($drill->affected_orders_count)->toBe(count($preview['affected_order_ids']))
        ->and($drill->affected_orders_count)->toBe(1);
});

// =========================================================================
//  NEW-CON-4 — walk is brand-scoped (never crosses into another org/brand)
// =========================================================================

it('NEW-CON-4: the walk is brand-scoped and never traverses a foreign-brand lot', function () {
    $ctx = makeGenealogyFixture('brand-a');
    $foreign = makeGenealogyFixture('brand-b');

    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $sameBrandChild = makeGenealogyLot($ctx);
    $foreignChild = makeGenealogyLot($foreign); // different org + brand

    makeGenealogyEdge($root->id, $sameBrandChild->id, 'material_batch');
    makeGenealogyEdge($root->id, $foreignChild->id, 'material_batch');

    $affected = app(GenealogyWalker::class)->descendantLotIds($root->id);

    expect($affected)->toContain($sameBrandChild->id)
        ->not->toContain($foreignChild->id);
});

// =========================================================================
//  NEW-CON-5 — descendants minted before the locked walk are quarantined
// =========================================================================

it('NEW-CON-5: initiate quarantines the full reachable set under a lock', function () {
    // The walk + quarantine happen inside the locked transaction, so any child
    // reachable from the root at initiate time (a batch minting a child of the
    // recalled lot that committed before the lock) is caught and quarantined.
    $ctx = makeGenealogyFixture();
    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $child = makeGenealogyLot($ctx);
    $grandchild = makeGenealogyLot($ctx);

    makeGenealogyEdge($root->id, $child->id);
    makeGenealogyEdge($child->id, $grandchild->id);

    app(RecallService::class)->initiate([
        'root_lot_id' => $root->id,
        'organization_id' => $ctx['org_id'],
        'reason' => 'concurrency',
        'initiated_by_id' => null,
    ]);

    expect($root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value)
        ->and($child->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value)
        ->and($grandchild->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value);
});

// =========================================================================
//  NEW-CON-7 — report reads lots + orders from the same snapshot instant
// =========================================================================

it('NEW-CON-7: report counts come from the same initiate snapshot despite graph drift', function () {
    $ctx = makeGenealogyFixture();
    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $child = makeGenealogyLot($ctx);
    $order = CustomerOrder::factory()->create(['brand_id' => $ctx['brand']->id]);

    makeGenealogyEdge($root->id, $child->id, 'material_batch');
    makeGenealogyEdge($child->id, null, 'customer_order', $order->id);

    $service = app(RecallService::class);
    $recall = $service->initiate([
        'root_lot_id' => $root->id,
        'organization_id' => $ctx['org_id'],
        'reason' => 'snapshot report',
        'initiated_by_id' => null,
    ]);

    $before = $service->generateReport($recall->fresh());
    expect($before['counts']['lots'])->toBe(2)
        ->and($before['counts']['orders'])->toBe(1);

    // The live graph drifts: a new downstream grandchild + a new sales edge land
    // AFTER initiate. The report must still reflect the initiate snapshot.
    $grandchild = makeGenealogyLot($ctx);
    $order2 = CustomerOrder::factory()->create(['brand_id' => $ctx['brand']->id]);
    makeGenealogyEdge($child->id, $grandchild->id, 'material_batch');
    makeGenealogyEdge($grandchild->id, null, 'customer_order', $order2->id);

    $after = $service->generateReport($recall->fresh());
    expect($after['counts']['lots'])->toBe(2)
        ->and($after['counts']['orders'])->toBe(1);
});

// =========================================================================
//  W5 — recall walk is ORG-only (follows material across brands in the org)
// =========================================================================

it('W5: recall walk follows a same-org cross-brand descendant (org-only, not brand-scoped)', function () {
    $ctx = makeGenealogyFixture('brand-a');

    // A SECOND brand in the SAME org, with its own branch/warehouse/material.
    $brandB = Brand::factory()->create([
        'console_organization_id' => $ctx['org_id'],
        'slug' => 'brand-b-'.Str::random(5),
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $ctx['org_id'],
        'console_brand_id' => $brandB->id,
    ]);
    $warehouseB = Warehouse::factory()->create([
        'organization_id' => $ctx['org_id'],
        'branch_id' => $branchB->id,
    ]);
    $materialB = Material::factory()->create([
        'organization_id' => $ctx['org_id'],
        'brand_id' => $brandB->id,
    ]);

    $root = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $crossBrandChild = MaterialLot::factory()->create([
        'organization_id' => $ctx['org_id'],
        'brand_id' => $brandB->id, // SAME org, DIFFERENT brand
        'material_id' => $materialB->id,
        'warehouse_id' => $warehouseB->id,
        'source' => 'production',
        'status' => MaterialLotStatusEnum::Active->value,
    ]);

    // A genealogy edge crossing brand within the org (e.g. central-kitchen brand
    // → retail brand transfer). A food-safety recall must follow it.
    makeGenealogyEdge($root->id, $crossBrandChild->id, 'material_batch');

    // Recall path (org-only, the default) MUST include the cross-brand descendant
    // — brand-scoping the walk would under-report the blast radius (W5).
    $recallWalk = app(GenealogyWalker::class)->descendantLotIds($root->id);
    expect($recallWalk)->toContain($crossBrandChild->id);

    // The Trace path (brand-scoped, opt-in) still prunes it for tenant isolation.
    $traceWalk = app(GenealogyWalker::class)->descendantLotIds($root->id, withBrandScope: true);
    expect($traceWalk)->not->toContain($crossBrandChild->id);
});

// =========================================================================
//  W6 — overlapping recalls: cancel does not release a still-covered lot
// =========================================================================

it('W6: cancelling one of two overlapping recalls keeps a shared lot quarantined', function () {
    $ctx = makeGenealogyFixture();
    $root1 = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $root2 = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $shared = makeGenealogyLot($ctx);

    // `shared` is a descendant of BOTH roots.
    makeGenealogyEdge($root1->id, $shared->id);
    makeGenealogyEdge($root2->id, $shared->id);

    $service = app(RecallService::class);

    $recall1 = $service->initiate([
        'root_lot_id' => $root1->id,
        'organization_id' => $ctx['org_id'],
        'reason' => 'recall one',
        'initiated_by_id' => null,
    ]);

    // recall2 fires while `shared` is already quarantined by recall1 — initiate
    // only re-tags Active lots, so `shared` keeps the recall1 tag while recall2's
    // snapshot still covers it.
    $recall2 = $service->initiate([
        'root_lot_id' => $root2->id,
        'organization_id' => $ctx['org_id'],
        'reason' => 'recall two',
        'initiated_by_id' => null,
    ]);

    expect($shared->fresh()->quarantine_reason)->toBe('recall:'.$recall1->id);

    // Cancel recall1: `root1` releases, but `shared` is still covered by recall2,
    // so it stays quarantined and is handed off (re-tagged) to recall2.
    $service->cancel($recall1->fresh(), 'false alarm');

    expect($root1->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value)
        ->and($shared->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value)
        ->and($shared->fresh()->quarantine_reason)->toBe('recall:'.$recall2->id);

    // Cancelling recall2 (the last remaining cover) finally releases `shared`.
    $service->cancel($recall2->fresh(), 'also clear');
    expect($shared->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

// =========================================================================
//  H12 — record* is idempotent (firstOrCreate backstop to uq_genealogy_edge)
// =========================================================================

it('H12: recording the same genealogy edge twice is a no-op', function () {
    $ctx = makeGenealogyFixture();
    $parent = makeGenealogyLot($ctx, ['source' => 'inbound']);
    $childLot = makeGenealogyLot($ctx);
    $batchId = (string) Str::uuid();

    $service = app(GenealogyLinkService::class);

    $first = $service->recordProductionConsumption($parent, $childLot, 30, 'kg', $batchId);
    $second = $service->recordProductionConsumption($parent, $childLot, 30, 'kg', $batchId);

    expect($second->id)->toBe($first->id)
        ->and(GenealogyLink::where('parent_lot_id', $parent->id)
            ->where('child_lot_id', $childLot->id)
            ->where('source_event_type', 'material_batch')
            ->where('source_event_id', $batchId)
            ->count())->toBe(1);

    // Same for a child-less sales edge.
    $orderId = (string) Str::uuid();
    $txId = (string) Str::uuid();
    $s1 = $service->recordSalesConsumption($parent, $orderId, 5, 'kg', $txId);
    $s2 = $service->recordSalesConsumption($parent, $orderId, 5, 'kg', $txId);

    expect($s2->id)->toBe($s1->id)
        ->and(GenealogyLink::where('parent_lot_id', $parent->id)
            ->where('source_event_type', 'customer_order')
            ->where('source_event_id', $txId)
            ->count())->toBe(1);
});

// =========================================================================
//  Arch (G) — exactly one genealogy walker; Trace/Recall/Drill all use it
// =========================================================================

it('Arch: exactly one genealogy walker exists and the three services depend on it', function () {
    // Exactly one *Walker class in app/Services/Inventory.
    $walkerFiles = glob(app_path('Services/Inventory/*Walker.php'));
    expect($walkerFiles)->toHaveCount(1)
        ->and(basename($walkerFiles[0]))->toBe('GenealogyWalker.php');

    // Trace / Recall / RecallDrill each inject GenealogyWalker.
    foreach ([TraceService::class, RecallService::class, RecallDrillService::class] as $serviceClass) {
        $ctor = (new ReflectionClass($serviceClass))->getConstructor();
        $types = collect($ctor->getParameters())
            ->map(fn ($p) => $p->getType()?->getName())
            ->all();
        expect($types)->toContain(GenealogyWalker::class);
    }

    // The divergent per-service walkers are gone (single implementation).
    expect(method_exists(RecallService::class, 'walkDownstreamLots'))->toBeFalse()
        ->and(method_exists(RecallDrillService::class, 'walkGenealogyTree'))->toBeFalse()
        ->and(method_exists(TraceService::class, 'walkParents'))->toBeFalse();
});
