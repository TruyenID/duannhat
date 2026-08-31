<?php

use App\Exceptions\KdsRuleViolation;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\OrderItemTopping;
use App\Services\Kds\KdsBusinessRules;
use App\Services\Order\ValueObjects\ActingDeviceTenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->rules = new KdsBusinessRules;
});

// ---------------------------------------------------------------------------
// assertOrderActive
// ---------------------------------------------------------------------------

it('assertOrderActive passes for open order', function () {
    $order = CustomerOrder::factory()->open()->create();

    expect(fn () => $this->rules->assertOrderActive($order))->not->toThrow(KdsRuleViolation::class);
});

it('assertOrderActive throws KDS_E001 for voided order', function () {
    $order = CustomerOrder::factory()->voided()->create();

    try {
        $this->rules->assertOrderActive($order);
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E001');
        expect($e->context)->toHaveKey('order_id');
        expect($e->context)->toHaveKey('order_status');
    }
});

it('assertOrderActive throws KDS_E001 for closed order', function () {
    $order = CustomerOrder::factory()->closed()->create();

    try {
        $this->rules->assertOrderActive($order);
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E001');
        expect($e->context['order_status'])->toBe('closed');
    }
});

// ---------------------------------------------------------------------------
// assertMarkServedAllowed
// ---------------------------------------------------------------------------

it('assertMarkServedAllowed throws KDS_E002 when item not ready', function () {
    $item = CustomerOrderItem::factory()->create(['ready_at' => null]);

    try {
        $this->rules->assertMarkServedAllowed($item);
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E002');
        expect($e->context)->toHaveKey('item_id');
    }
});

it('assertMarkServedAllowed throws KDS_E003 when ready less than 30s ago', function () {
    $item = CustomerOrderItem::factory()->create(['ready_at' => now()->subSeconds(15)]);

    try {
        $this->rules->assertMarkServedAllowed($item);
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E003');
        expect($e->context['elapsed_seconds'])->toBeLessThan(30);
        expect($e->context)->toHaveKey('ready_at');
    }
});

it('assertMarkServedAllowed passes when ready 30s or more ago', function () {
    $item = CustomerOrderItem::factory()->create(['ready_at' => now()->subSeconds(60)]);

    expect(fn () => $this->rules->assertMarkServedAllowed($item))->not->toThrow(KdsRuleViolation::class);
});

// ---------------------------------------------------------------------------
// assertToppingsParentReady
// ---------------------------------------------------------------------------

it('assertToppingsParentReady passes for item with no toppings', function () {
    $item = CustomerOrderItem::factory()->create();
    $item->setRelation('orderItemToppings', collect());

    expect(fn () => $this->rules->assertToppingsParentReady($item))->not->toThrow(KdsRuleViolation::class);
});

it('assertToppingsParentReady throws KDS_E004 when any topping not ready', function () {
    $item = CustomerOrderItem::factory()->create();
    $topping = OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'pending',
    ]);
    $item->setRelation('orderItemToppings', collect([$topping]));

    try {
        $this->rules->assertToppingsParentReady($item);
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E004');
        expect($e->context['unready_topping_count'])->toBe(1);
        expect($e->context)->toHaveKey('unready_topping_ids');
    }
});

it('assertToppingsParentReady passes when all toppings ready', function () {
    $item = CustomerOrderItem::factory()->create();
    $toppings = OrderItemTopping::factory()->count(2)->create([
        'customer_order_item_id' => $item->id,
        'status' => 'ready',
    ]);
    $item->setRelation('orderItemToppings', $toppings);

    expect(fn () => $this->rules->assertToppingsParentReady($item))->not->toThrow(KdsRuleViolation::class);
});

// ---------------------------------------------------------------------------
// assertNotThrottled
// ---------------------------------------------------------------------------

it('assertNotThrottled passes on first call', function () {
    Cache::clear();
    $item = CustomerOrderItem::factory()->create();
    $deviceId = 'device-abc';

    expect(fn () => $this->rules->assertNotThrottled($item, $deviceId))->not->toThrow(KdsRuleViolation::class);
    expect(Cache::has("kds:throttle:{$item->id}:{$deviceId}"))->toBeTrue();
});

it('assertNotThrottled throws KDS_E005 on second call within TTL', function () {
    Cache::clear();
    $item = CustomerOrderItem::factory()->create();
    $deviceId = 'device-abc';

    $this->rules->assertNotThrottled($item, $deviceId);

    try {
        $this->rules->assertNotThrottled($item, $deviceId);
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E005');
        expect($e->context['retry_after_seconds'])->toBe(3);
    }
});

it('assertNotThrottled passes after TTL expires', function () {
    Cache::clear();
    $item = CustomerOrderItem::factory()->create();
    $deviceId = 'device-xyz';

    $this->rules->assertNotThrottled($item, $deviceId);

    Cache::clear();

    expect(fn () => $this->rules->assertNotThrottled($item, $deviceId))->not->toThrow(KdsRuleViolation::class);
});

// ---------------------------------------------------------------------------
// assertBranchOwnership
// ---------------------------------------------------------------------------

it('assertBranchOwnership passes when same branch', function () {
    $branch = Branch::factory()->create();
    $order = CustomerOrder::factory()->create(['branch_id' => $branch->id]);
    $device = Device::factory()->create(['branch_id' => $branch->id]);

    expect(fn () => $this->rules->assertBranchOwnership($order, ActingDeviceTenancy::of($device)))->not->toThrow(KdsRuleViolation::class);
});

it('assertBranchOwnership throws KDS_E006 when different branch', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $order = CustomerOrder::factory()->create(['branch_id' => $branchA->id]);
    $device = Device::factory()->create(['branch_id' => $branchB->id]);

    try {
        $this->rules->assertBranchOwnership($order, ActingDeviceTenancy::of($device));
        $this->fail('Expected KdsRuleViolation to be thrown');
    } catch (KdsRuleViolation $e) {
        expect($e->errorCode)->toBe('KDS_E006');
        expect($e->context)->toHaveKey('order_branch_id');
        expect($e->context)->toHaveKey('device_branch_id');
    }
});
