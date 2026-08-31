<?php

use App\Events\WorkstationSyncPoke;
use App\Jobs\Catalog\RebuildCatalogRevisionJob;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CatalogRevision;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\User;
use App\Services\Catalog\CatalogRevisionService;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * #1174 (option C) — flushDirty() dispatches ONE queued RebuildCatalogRevisionJob
 * per deduped branch instead of rebuilding every affected snapshot inline in
 * the HTTP request; the job hash-dedups (BR-CR02), debounces via ShouldBeUnique,
 * and fires the #1175 WorkstationSyncPoke — which must NEVER be able to fail
 * the job (chaos pin below).
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rcr-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->branch1 = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->branch2 = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->branch3 = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->productA = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $this->productB = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $this->skuA = ProductSku::factory()->create([
        'product_id' => $this->productA->id, 'selling_price' => 1000, 'is_active' => true,
    ]);

    // ONE topping group shared by BOTH products (the #1174 reproduction shape).
    $this->group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'price_strategy' => 'flat',
        'min_select' => 0,
        'max_select' => null,
    ]);
    foreach ([$this->productA, $this->productB] as $product) {
        DB::table('product_topping_groups')->insert([
            'product_id' => $product->id,
            'topping_group_id' => $this->group->id,
            'sort_order' => 0,
        ]);
    }
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $this->productA->id,
        'is_default' => true,
        'sort_order' => 0,
    ]);
    $this->itemSku = ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $this->item->id,
        'extra_price' => 100,
    ]);

    // OVERLAPPING branch coverage: product A sells on branches 1+2, product B
    // on branches 1+3 → the naive per-product walk visits branch 1 twice; the
    // deduped set is exactly {1, 2, 3}.
    $carry = function (Product $product, Branch $branch): void {
        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $branch->id,
            'status' => 'Active',
        ]);
        MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true,
        ]);
    };
    $carry($this->productA, $this->branch1);
    $carry($this->productA, $this->branch2);
    $carry($this->productB, $this->branch1);
    $carry($this->productB, $this->branch3);

    // A priced line on branch 1 so a rebuild there has a catalog to version.
    MenuProductSku::factory()->create([
        'menu_product_id' => MenuProduct::query()
            ->where('product_id', $this->productA->id)
            ->whereIn('menu_id', Menu::query()->where('branch_id', $this->branch1->id)->select('id'))
            ->value('id'),
        'product_sku_id' => $this->skuA->id,
        'selling_price' => 1000,
        'is_price_overridden' => true,
        'is_active' => true,
    ]);

    $this->skuEndpoint = "/api/v1/hq/{$this->brand->slug}/topping-groups/{$this->group->id}"
        ."/items/{$this->item->id}/skus/{$this->itemSku->id}";
});

// =========================================================================
//  #1174 — the HTTP request does ZERO snapshot building inline
// =========================================================================

it('queues exactly one rebuild job per DEDUPED branch on a shared-topping price edit, with no inline mint', function () {
    Queue::fake();
    $revisionsBefore = CatalogRevision::query()->count();

    $this->actingAs($this->user)
        ->putJson($this->skuEndpoint, ['extra_price' => 250])
        ->assertOk();

    // Naive per-product marking would visit branch 1 twice (A and B both sell
    // there) → 4 marks. The dispatched set must be the deduped {1, 2, 3}.
    Queue::assertPushed(RebuildCatalogRevisionJob::class, 3);
    $pushedBranchIds = collect(Queue::pushed(RebuildCatalogRevisionJob::class))
        ->map(fn (RebuildCatalogRevisionJob $job) => $job->branchId)
        ->sort()->values()->all();
    expect($pushedBranchIds)->toBe(
        collect([$this->branch1->id, $this->branch2->id, $this->branch3->id])->sort()->values()->all()
    );

    // The request itself minted NOTHING — the snapshot work now lives on the
    // worker (this is the entire point of #1174).
    expect(CatalogRevision::query()->count())->toBe($revisionsBefore);
});

it('debounces duplicate dispatches for one branch via ShouldBeUnique (uniqueId = branchId, 10s window)', function () {
    Queue::fake();

    RebuildCatalogRevisionJob::dispatch($this->branch1->id);
    RebuildCatalogRevisionJob::dispatch($this->branch1->id); // dropped — unique lock held
    RebuildCatalogRevisionJob::dispatch($this->branch2->id); // different branch → its own lock

    Queue::assertPushed(RebuildCatalogRevisionJob::class, 2);

    $job = new RebuildCatalogRevisionJob($this->branch1->id);
    expect($job->uniqueId())->toBe($this->branch1->id)
        ->and($job->uniqueFor)->toBe(10);
});

// =========================================================================
//  The job itself — idempotent, hash-deduped, safe to rerun
// =========================================================================

it('mints the revision when the job runs, and a rerun mints nothing new (hash dedup)', function () {
    $baseline = CatalogRevision::query()->where('branch_id', $this->branch1->id)->count();

    Queue::fake(); // suppress the auto-dispatched job; we drive it by hand
    $this->itemSku->update(['extra_price' => 250]);
    expect(CatalogRevision::query()->where('branch_id', $this->branch1->id)->count())->toBe($baseline);

    (new RebuildCatalogRevisionJob($this->branch1->id))->handle(app(CatalogRevisionService::class));

    $revisions = app(CatalogRevisionService::class);
    $current = $revisions->currentFor($this->branch1->id);
    expect(CatalogRevision::query()->where('branch_id', $this->branch1->id)->count())->toBe($baseline + 1)
        ->and($current->snapshot['topping_prices'][$this->productA->id.'|'.$this->item->id.'|'.$this->skuA->id])
        ->toBe('250.00');

    // Rerun over an unchanged price map: BR-CR02 hash dedup → no new row.
    (new RebuildCatalogRevisionJob($this->branch1->id))->handle(app(CatalogRevisionService::class));
    expect(CatalogRevision::query()->where('branch_id', $this->branch1->id)->count())->toBe($baseline + 1)
        ->and($revisions->currentFor($this->branch1->id)->id)->toBe($current->id);
});

it('still lands the HQ edit in the revision end-to-end on the sync queue driver (test-suite contract)', function () {
    $this->actingAs($this->user)
        ->putJson($this->skuEndpoint, ['extra_price' => 300])
        ->assertOk();

    $current = app(CatalogRevisionService::class)->currentFor($this->branch1->id);
    expect($current->snapshot['topping_prices'][$this->productA->id.'|'.$this->item->id.'|'.$this->skuA->id])
        ->toBe('300.00');
});

// =========================================================================
//  #1175 — the poke rides the job, per branch
// =========================================================================

it('fires WorkstationSyncPoke for exactly the rebuilt branch', function () {
    Event::fake([WorkstationSyncPoke::class]);

    (new RebuildCatalogRevisionJob($this->branch1->id))->handle(app(CatalogRevisionService::class));
    (new RebuildCatalogRevisionJob($this->branch2->id))->handle(app(CatalogRevisionService::class));

    Event::assertDispatched(WorkstationSyncPoke::class, 2);
    Event::assertDispatched(WorkstationSyncPoke::class, fn (WorkstationSyncPoke $e) => $e->branchId === $this->branch1->id);
    Event::assertDispatched(WorkstationSyncPoke::class, fn (WorkstationSyncPoke $e) => $e->branchId === $this->branch2->id);
});

it('broadcasts as a contentless hint on the private per-branch workstation channel', function () {
    $event = new WorkstationSyncPoke('branch-x');

    expect((string) $event->broadcastOn()[0])->toBe('private-workstation.sync.branch-x')
        ->and($event->broadcastAs())->toBe('sync.poke')
        ->and($event->broadcastWith())->toBe([]);
});

// =========================================================================
//  CHAOS PIN (#1175 invariant): a dead broadcaster must never affect the job
// =========================================================================

it('chaos: a throwing broadcast driver never fails the job — revision still minted, failure logged', function () {
    Broadcast::extend('chaos', fn () => new class implements Broadcaster
    {
        public function auth($request)
        {
            return true;
        }

        public function validAuthenticationResponse($request, $result)
        {
            return [];
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('poke provider is down');
        }
    });
    config([
        'broadcasting.default' => 'chaos',
        'broadcasting.connections.chaos' => ['driver' => 'chaos'],
    ]);
    Log::spy();

    $baseline = CatalogRevision::query()->where('branch_id', $this->branch1->id)->count();

    // Real sync queue on purpose (no Queue::fake — a faked queue would also
    // swallow the BroadcastEvent and never reach the chaos driver): the edit
    // runs the rebuild job inline, whose poke hits the throwing broadcaster.
    $this->itemSku->update(['extra_price' => 999]);

    // The job survived AND did its real work.
    $current = app(CatalogRevisionService::class)->currentFor($this->branch1->id);
    expect(CatalogRevision::query()->where('branch_id', $this->branch1->id)->count())->toBe($baseline + 1)
        ->and($current->snapshot['topping_prices'][$this->productA->id.'|'.$this->item->id.'|'.$this->skuA->id])
        ->toBe('999.00');

    // …and the dead provider is VISIBLE, not silent.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => $message === 'workstation_sync_poke_failed')
        ->atLeast()->once();
});
