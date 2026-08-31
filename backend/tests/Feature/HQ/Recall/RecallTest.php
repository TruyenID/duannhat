<?php

/**
 * Plan-017 Tier 1.A — recall execution end-to-end.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\Recall;
use App\Models\RecallAffectedOrder;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Str;
use Mockery\MockInterface;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rc-'.Str::random(4),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/recalls";

    // Two-level genealogy: root → child → grandchild
    $this->root = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
    $this->child = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'source' => 'production',
    ]);
    GenealogyLink::create([
        'parent_lot_id' => $this->root->id,
        'child_lot_id' => $this->child->id,
        'qty_consumed' => 50,
        'unit' => 'kg',
        'consumed_at' => now()->subDay(),
        'source_event_type' => 'material_batch',
        'source_event_id' => (string) Str::uuid(),
    ]);

    // One sales edge from the child lot to a fake customer order.
    $this->orderId = (string) Str::uuid();
    GenealogyLink::create([
        'parent_lot_id' => $this->child->id,
        'child_lot_id' => null,
        'customer_order_id' => $this->orderId,
        'qty_consumed' => 5,
        'unit' => 'kg',
        'consumed_at' => now(),
        'source_event_type' => 'customer_order',
        'source_event_id' => (string) Str::uuid(),
    ]);
});

it('previews affected counts without writing anything', function () {
    $response = $this->postJson("{$this->baseUrl}/preview", [
        'root_lot_id' => $this->root->id,
    ])->assertOk();

    expect($response->json('affected_lots_count'))->toBe(2)
        ->and($response->json('affected_orders_count'))->toBe(1);

    expect(Recall::count())->toBe(0)
        ->and($this->root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

it('initiates a recall: auto-quarantines + snapshots affected orders', function () {
    $response = $this->postJson($this->baseUrl, [
        'root_lot_id' => $this->root->id,
        'reason' => 'Salmonella positive on supplier QA test',
    ])->assertCreated();

    $recallId = $response->json('data.id');
    $recall = Recall::find($recallId);
    expect($recall)->not->toBeNull()
        ->and($recall->status->value)->toBe('active')
        ->and($recall->affected_lots_count)->toBe(2)
        ->and($recall->affected_orders_count)->toBe(1);

    expect($this->root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value)
        ->and($this->child->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value);

    $rao = RecallAffectedOrder::where('recall_id', $recallId)->first();
    expect($rao)->not->toBeNull()
        ->and((string) $rao->customer_order_id)->toBe($this->orderId);
});

it('cancels a recall and releases auto-quarantined lots back to active', function () {
    $created = $this->postJson($this->baseUrl, [
        'root_lot_id' => $this->root->id,
        'reason' => 'Salmonella positive',
    ])->assertCreated();
    $recallId = $created->json('data.id');

    expect($this->root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value);

    $this->postJson("{$this->baseUrl}/{$recallId}/cancel", [
        'cancellation_reason' => 'False alarm — supplier retest came back negative.',
    ])->assertOk();

    expect(Recall::find($recallId)->status->value)->toBe('cancelled')
        ->and($this->root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value)
        ->and($this->child->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

it('marks a recall completed (lots stay quarantined)', function () {
    $created = $this->postJson($this->baseUrl, [
        'root_lot_id' => $this->root->id,
        'reason' => 'Salmonella positive',
    ])->assertCreated();
    $recallId = $created->json('data.id');

    $this->postJson("{$this->baseUrl}/{$recallId}/complete")->assertOk();

    expect(Recall::find($recallId)->status->value)->toBe('completed')
        ->and($this->root->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value);
});

it('returns an FSMA-204-shaped report', function () {
    $created = $this->postJson($this->baseUrl, [
        'root_lot_id' => $this->root->id,
        'reason' => 'Salmonella positive',
    ])->assertCreated();
    $recallId = $created->json('data.id');

    $report = $this->getJson("{$this->baseUrl}/{$recallId}/report")->assertOk();

    expect($report->json('data.recall_code'))->toStartWith('RC-')
        ->and($report->json('data.counts.lots'))->toBe(2)
        ->and($report->json('data.counts.orders'))->toBe(1)
        ->and($report->json('data.root_lot.id'))->toBe($this->root->id);
});

it('W3: 404s a cross-brand-same-org root lot (initiator brand authz)', function () {
    // A SECOND brand in the SAME org as the acting user. A brand_admin acting via
    // brand A's slug must not be able to recall a lot that belongs to brand B.
    $brandB = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rcb-'.Str::random(4),
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $brandB->id,
    ]);
    $warehouseB = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $branchB->id,
    ]);
    $materialB = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $brandB->id,
    ]);
    $brandBLot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $brandB->id,
        'material_id' => $materialB->id,
        'warehouse_id' => $warehouseB->id,
    ]);

    // $this->baseUrl is scoped to brand A's slug; the root lot belongs to brand B.
    $this->postJson("{$this->baseUrl}/preview", ['root_lot_id' => $brandBLot->id])
        ->assertNotFound();

    $this->postJson($this->baseUrl, [
        'root_lot_id' => $brandBLot->id,
        'reason' => 'cross-brand attempt',
    ])->assertNotFound();

    expect(Recall::count())->toBe(0)
        ->and($brandBLot->fresh()->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

it('blocks shop-staff from initiating a recall', function () {
    $role = Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 10]);
    $staffUser = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $staffUser->assignRole($role, $this->orgId);

    $this->actingAs($staffUser)
        ->postJson($this->baseUrl, [
            'root_lot_id' => $this->root->id,
            'reason' => 'attempt',
        ])
        ->assertForbidden();
});

// =========================================================================
//  Notify — dispatches to affected CUSTOMERS, stamps notification_id
// =========================================================================

describe('notify', function () {
    // Rebuild the sales edge so it points at a REAL customer order owned by a
    // real customer, then initiate a recall over it.
    function initiateRecallWithRealCustomerOrder(object $ctx, ?string $customerId): array
    {
        // Drop the fake sales edge from beforeEach and add a real one.
        GenealogyLink::where('customer_order_id', $ctx->orderId)->delete();

        $order = CustomerOrder::factory()->create([
            'organization_id' => $ctx->orgId,
            'brand_id' => $ctx->brand->id,
            'branch_id' => $ctx->branch->id,
            'customer_id' => $customerId,
        ]);

        GenealogyLink::create([
            'parent_lot_id' => $ctx->child->id,
            'child_lot_id' => null,
            'customer_order_id' => $order->id,
            'qty_consumed' => 5,
            'unit' => 'kg',
            'consumed_at' => now(),
            'source_event_type' => 'customer_order',
            'source_event_id' => (string) Str::uuid(),
        ]);

        $recallId = test()->postJson($ctx->baseUrl, [
            'root_lot_id' => $ctx->root->id,
            'reason' => 'Salmonella positive',
        ])->assertCreated()->json('data.id');

        return [$recallId, $order];
    }

    it('notifies the affected customer (not org staff) and stamps notification_id', function () {
        $customer = Customer::factory()->create();
        [$recallId, $order] = initiateRecallWithRealCustomerOrder($this, $customer->id);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertOk();

        // Exactly one notification, delivered to the CUSTOMER — not any org user.
        expect(Notification::count())->toBe(1);
        $notification = Notification::first();

        $recipients = NotificationRecipient::where('notification_id', $notification->id)->get();
        expect($recipients)->toHaveCount(1)
            ->and($recipients->first()->recipient_type)->toBe($customer->getMorphClass())
            ->and((string) $recipients->first()->recipient_id)->toBe((string) $customer->id);

        // notification_id is written back onto the affected-order pivot row.
        $rao = RecallAffectedOrder::where('recall_id', $recallId)->first();
        expect($rao->notified_at)->not->toBeNull()
            ->and($rao->notification_channel)->toBe('in_app')
            ->and((string) $rao->notification_id)->toBe((string) $notification->id);
    });

    it('marks a guest order processed with a null notification_id (nobody to notify)', function () {
        [$recallId] = initiateRecallWithRealCustomerOrder($this, null);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertOk();

        // No customer → no notification dispatched at all.
        expect(Notification::count())->toBe(0);

        $rao = RecallAffectedOrder::where('recall_id', $recallId)->first();
        expect($rao->notified_at)->not->toBeNull()
            ->and($rao->notification_id)->toBeNull();
    });

    it('propagates dispatch failure and rolls back (no false-positive success)', function () {
        $customer = Customer::factory()->create();
        [$recallId, $order] = initiateRecallWithRealCustomerOrder($this, $customer->id);

        // Force the notification platform to blow up mid-dispatch.
        $this->mock(NotificationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('platform down'));
        });

        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertStatus(500);

        // The txn rolled back — the pivot row was NOT marked notified.
        $rao = RecallAffectedOrder::where('recall_id', $recallId)->first();
        expect($rao->notified_at)->toBeNull()
            ->and($rao->notification_id)->toBeNull();
    });

    // plan-017 Tier 1.A — attach N sales edges (one per given customer) off the
    // recall's child lot, then initiate the recall so its snapshot captures every
    // order. Returns [recallId, orders[]] in the same order as $customerIds.
    function initiateRecallWithOrders(object $ctx, array $customerIds): array
    {
        GenealogyLink::where('customer_order_id', $ctx->orderId)->delete();

        $orders = [];
        foreach ($customerIds as $customerId) {
            $order = CustomerOrder::factory()->create([
                'organization_id' => $ctx->orgId,
                'brand_id' => $ctx->brand->id,
                'branch_id' => $ctx->branch->id,
                'customer_id' => $customerId,
            ]);
            GenealogyLink::create([
                'parent_lot_id' => $ctx->child->id,
                'child_lot_id' => null,
                'customer_order_id' => $order->id,
                'qty_consumed' => 5,
                'unit' => 'kg',
                'consumed_at' => now(),
                'source_event_type' => 'customer_order',
                'source_event_id' => (string) Str::uuid(),
            ]);
            $orders[] = $order;
        }

        $recallId = test()->postJson($ctx->baseUrl, [
            'root_lot_id' => $ctx->root->id,
            'reason' => 'Salmonella positive',
        ])->assertCreated()->json('data.id');

        return [$recallId, $orders];
    }

    it('stamps notification_channel=email when channel=email is requested', function () {
        $customer = Customer::factory()->create();
        [$recallId] = initiateRecallWithRealCustomerOrder($this, $customer->id);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify", ['channel' => 'email'])
            ->assertOk();

        $rao = RecallAffectedOrder::where('recall_id', $recallId)->first();
        expect($rao->notified_at)->not->toBeNull()
            ->and($rao->notification_channel)->toBe('email')
            ->and($rao->notification_id)->not->toBeNull();
    });

    it('rejects an unsupported notification channel', function () {
        $customer = Customer::factory()->create();
        [$recallId] = initiateRecallWithRealCustomerOrder($this, $customer->id);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify", ['channel' => 'carrier_pigeon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');

        // Rejected before any dispatch — nothing was sent or stamped.
        expect(Notification::count())->toBe(0);
        expect(RecallAffectedOrder::where('recall_id', $recallId)->first()->notified_at)->toBeNull();
    });

    it('is idempotent: a second full notify dispatches nothing new', function () {
        $customer = Customer::factory()->create();
        [$recallId] = initiateRecallWithRealCustomerOrder($this, $customer->id);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertOk();
        expect(Notification::count())->toBe(1);
        $firstNotificationId = (string) Notification::first()->id;
        $stampedAt = RecallAffectedOrder::where('recall_id', $recallId)->first()
            ->notified_at->toDateTimeString();

        // Every affected-order row already has notified_at → the whereNull filter
        // matches nothing, so no new notification is dispatched and no re-stamp.
        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertOk();

        expect(Notification::count())->toBe(1);
        $rao = RecallAffectedOrder::where('recall_id', $recallId)->first();
        expect((string) $rao->notification_id)->toBe($firstNotificationId)
            ->and($rao->notified_at->toDateTimeString())->toBe($stampedAt);
    });

    it('re-notify after partial completion only sends to the remaining un-notified rows', function () {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        [$recallId, $orders] = initiateRecallWithOrders($this, [$customerA->id, $customerB->id]);
        [$orderA, $orderB] = $orders;

        // Simulate a partial completion: order A was already notified out-of-band.
        $raoA = RecallAffectedOrder::where('recall_id', $recallId)
            ->where('customer_order_id', $orderA->id)->first();
        $priorNotificationId = (string) Str::uuid();
        $raoA->update([
            'notified_at' => now()->subHour(),
            'notification_channel' => 'email',
            'notification_id' => $priorNotificationId,
        ]);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertOk();

        // Exactly one fresh notification, delivered only to customer B.
        expect(Notification::count())->toBe(1);
        $recipients = NotificationRecipient::all();
        expect($recipients)->toHaveCount(1)
            ->and((string) $recipients->first()->recipient_id)->toBe((string) $customerB->id);

        // Order A's pre-existing stamp is untouched (not re-dispatched).
        $raoA->refresh();
        expect((string) $raoA->notification_id)->toBe($priorNotificationId);

        // Order B is now stamped with the new notification.
        $raoB = RecallAffectedOrder::where('recall_id', $recallId)
            ->where('customer_order_id', $orderB->id)->first();
        expect($raoB->notified_at)->not->toBeNull()
            ->and((string) $raoB->notification_id)->toBe((string) Notification::first()->id);
    });

    it('contacts a customer once even when several of their orders are affected', function () {
        $customer = Customer::factory()->create();
        [$recallId] = initiateRecallWithOrders($this, [$customer->id, $customer->id]);

        expect(RecallAffectedOrder::where('recall_id', $recallId)->count())->toBe(2);

        $this->postJson("{$this->baseUrl}/{$recallId}/notify")->assertOk();

        // One notification, ONE deduped recipient — but BOTH pivot rows stamped.
        expect(Notification::count())->toBe(1)
            ->and(NotificationRecipient::count())->toBe(1);
        $notificationId = (string) Notification::first()->id;

        $raos = RecallAffectedOrder::where('recall_id', $recallId)->get();
        expect($raos)->toHaveCount(2);
        foreach ($raos as $rao) {
            expect($rao->notified_at)->not->toBeNull()
                ->and((string) $rao->notification_id)->toBe($notificationId);
        }
    });

    it('blocks shop-staff from dispatching recall notifications', function () {
        $customer = Customer::factory()->create();
        [$recallId] = initiateRecallWithRealCustomerOrder($this, $customer->id);

        $role = Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 10]);
        $staff = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        $staff->assignRole($role, $this->orgId);

        $this->actingAs($staff)
            ->postJson("{$this->baseUrl}/{$recallId}/notify")
            ->assertForbidden();

        // Authorization failed before any dispatch.
        expect(Notification::count())->toBe(0);
        expect(RecallAffectedOrder::where('recall_id', $recallId)->first()->notified_at)->toBeNull();
    });

    it('blocks notifying a recall that belongs to another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $foreignRecall = Recall::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $this->brand->id,
            'status' => 'active',
        ]);

        // Acting as org A's admin, via org A's brand slug, targeting org B's recall.
        $this->postJson("{$this->baseUrl}/{$foreignRecall->id}/notify")
            ->assertForbidden();

        expect(Notification::count())->toBe(0);
    });
});

// =========================================================================
//  Index — listing, pagination envelope, status filter, search
// =========================================================================

describe('index', function () {
    it('returns a paginated {data, meta} envelope the frontend can read', function () {
        Recall::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'root_lot_id' => $this->root->id,
        ]);

        $this->getJson("{$this->baseUrl}?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1);
    });

    it('serves the requested page', function () {
        Recall::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'root_lot_id' => $this->root->id,
        ]);

        $this->getJson("{$this->baseUrl}?per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    });

    it('filters by status', function () {
        Recall::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'root_lot_id' => $this->root->id,
            'status' => 'active',
        ]);
        Recall::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'root_lot_id' => $this->root->id,
            'status' => 'completed',
        ]);

        $this->getJson("{$this->baseUrl}?status=active")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("{$this->baseUrl}?status=completed")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('searches by recall_code and reason', function () {
        Recall::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'root_lot_id' => $this->root->id,
            'recall_code' => 'FINDME01',
            'reason' => 'salmonella positive',
        ]);
        Recall::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'root_lot_id' => $this->root->id,
            'recall_code' => 'OTHER999',
            'reason' => 'listeria positive',
        ]);

        $this->getJson("{$this->baseUrl}?search=FINDME")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recall_code', 'FINDME01');

        $this->getJson("{$this->baseUrl}?search=salmonella")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recall_code', 'FINDME01');
    });
});
