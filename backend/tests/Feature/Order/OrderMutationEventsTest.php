<?php

use App\Events\Order\OrderMutated;
use App\Events\OrderPaid;
use App\Events\OrderVoided;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\OrderService;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * The order domain's extension point.
 *
 * Before this, a plugin had nothing to hook: the seven Order* events are
 * WebSocket transport with thin payloads and no MutationContext, several
 * lifecycle transitions had no event at all (create, refund, offline replay),
 * and the container port a decorator would bind — OrderPersistencePort — is not
 * what OrderService actually injects, so decorating it did nothing.
 *
 * OrderMutated closes that. These tests pin the three properties a plugin
 * author has to be able to rely on: it fires for EVERY command, it carries the
 * context, and it never arrives for a mutation that rolled back.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'timezone' => 'UTC',
    ]);

    $taxType = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $taxType->id,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id, 'tax_type_id' => $taxType->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);
});

it('emits for EVERY command on the facade — a new command cannot ship silently', function () {
    // Walks the interface rather than a hand-kept list: adding a 49th method
    // without an emit() fails here, which is the whole point of a stream a
    // plugin is allowed to depend on.
    $interface = new ReflectionClass(OrderMutationFacade::class);
    $service = file_get_contents(app_path('Services/Order/OrderService.php'));

    $missing = [];
    foreach ($interface->getMethods() as $method) {
        if (! str_contains((string) $service, "\$this->emit('{$method->getName()}',")) {
            $missing[] = $method->getName();
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'These facade commands complete without announcing themselves, so any',
        'listener depending on the order lifecycle silently misses them:',
        ...$missing,
    ]));

    // And nothing emits under a name the interface does not have (a rename that
    // updated the method but not its event would break every subscriber).
    preg_match_all("/\\\$this->emit\('(\w+)'/", (string) $service, $m);
    $declared = array_map(fn (ReflectionMethod $x): string => $x->getName(), $interface->getMethods());
    expect(array_diff(array_unique($m[1]), $declared))->toBe([]);
});

it('emits from the LEGACY facade too — half the traffic would otherwise be invisible', function () {
    // The two facades are disjoint: CustomerOrderService and OrderService each
    // wrap EloquentOrderPersistence directly and neither calls the other. Kiosk
    // and customer-web still go through the legacy one, so emitting only from
    // the typed facade would give a plugin a stream that silently covers part
    // of the traffic — and no way to tell which part.
    $legacy = new ReflectionClass(CustomerOrderService::class);
    $source = (string) file_get_contents(app_path('Services/Customer/CustomerOrderService.php'));

    // Read-only accessors have nothing to announce.
    $readOnly = ['list', 'aggregate', 'findById', 'splitByItemsPreview', '__construct'];

    $silent = [];
    foreach ($legacy->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->class !== CustomerOrderService::class || in_array($method->getName(), $readOnly, true)) {
            continue;
        }
        if (! str_contains($source, "\$this->emitTransportMutation('{$method->getName()}',")) {
            $silent[] = $method->getName();
        }
    }

    expect($silent)->toBe([], implode("\n", [
        'These legacy order mutators complete without announcing themselves:',
        ...$silent,
    ]));
});

it('carries the command name, the order id and the mutation context', function () {
    Event::fake([OrderMutated::class]);

    $order = app(CustomerOrderService::class)->create([
        'order_type' => 'dine_in', 'status' => 'open',
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id, 'quantity' => 1,
    ]]]);

    Event::assertDispatched(OrderMutated::class, function (OrderMutated $e) use ($order): bool {
        // The context is the part the WebSocket events never had: without a
        // correlation id a plugin cannot tie its own work back to the request
        // that caused it, and without an actor it cannot attribute anything.
        return $e->orderId === (string) $order->id
            && $e->context->organizationId === $this->orgId
            && $e->context->correlationId !== ''
            && $e->context->idempotencyKeyHash !== '';
    });
});

it('does not reach a listener when the surrounding transaction rolls back', function () {
    // The property that makes a plugin safe to write. The dominant dispatch
    // sites run inside long transactions, so an inline event would let a
    // listener act on a sale that never happened — refund it, print it, bill it.
    $seen = [];
    Event::listen(OrderMutated::class, function (OrderMutated $e) use (&$seen): void {
        $seen[] = $e->command;
    });

    try {
        DB::transaction(function (): void {
            app(CustomerOrderService::class)->create([
                'order_type' => 'dine_in', 'status' => 'open',
                'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
            ]);

            throw new RuntimeException('something later in the request failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($seen)->toBe([], 'a listener was told about an order the database rolled back');
});

it('reaches the listener once the transaction commits', function () {
    $seen = [];
    Event::listen(OrderMutated::class, function (OrderMutated $e) use (&$seen): void {
        $seen[] = $e->command;
    });

    DB::transaction(function (): void {
        app(CustomerOrderService::class)->create([
            'order_type' => 'dine_in', 'status' => 'open',
            'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        ]);
    });

    expect($seen)->toContain('create');
});

it('is declared after-commit, so the guarantee above survives a refactor', function () {
    // Asserting the behaviour is not enough: a future dispatch site outside a
    // transaction would make the rollback test pass for the wrong reason.
    expect(is_subclass_of(OrderMutated::class, ShouldDispatchAfterCommit::class))->toBeTrue();
});

it('leaves the existing broadcast events alone', function () {
    // OrderMutated is an INTERNAL contract. The Order* events remain what they
    // were — a nudge for the browser to refetch — and are deliberately not
    // repurposed as a plugin API.
    expect(is_subclass_of(OrderMutated::class, ShouldBroadcast::class))
        ->toBeFalse('OrderMutated must not be broadcast: it carries actor and idempotency data');

    expect(class_exists(OrderPaid::class))->toBeTrue()
        ->and(class_exists(OrderVoided::class))->toBeTrue();
});

it('binds the facade to the emitting service, so no caller can route around it', function () {
    expect(app(OrderMutationFacade::class))->toBeInstanceOf(OrderService::class);
});
