<?php

use App\Exceptions\TakeawayContactRequiredException;
use App\Mail\OrderPlacedMail;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Services\Customer\CustomerTakeawayOrderService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Plan-047 thin-controller/fat-service — CustomerTakeawayOrderService::place was
 * extracted from CustomerOrderController::createBranchOrder. The HTTP surface
 * (guards, response shape, idempotency lock/cache) stays covered by the
 * controller tests; these hit the service directly.
 */
beforeEach(function () {
    $orgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create([
        'id' => $orgId, 'console_organization_id' => $orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::with('brand')->find(Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ])->id);
    $this->sku = ProductSku::factory()->create(['selling_price' => 1000]);

    $this->service = app(CustomerTakeawayOrderService::class);

    $this->payload = fn (array $overrides = []): array => array_merge([
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ], $overrides);
});

it('throws a TakeawayContactRequiredException (422) for a guest with no phone or email', function () {
    Mail::fake();

    try {
        $this->service->place($this->branch, $this->organization, ($this->payload)(['customer_takeaway_name' => '田中']), null, null, 'ja');
        $this->fail('Expected TakeawayContactRequiredException');
    } catch (TakeawayContactRequiredException $e) {
        expect($e->render()->getStatusCode())->toBe(422)
            ->and($e->render()->getData(true)['error'])->toBe('TAKEAWAY_CONTACT_REQUIRED');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('creates a takeaway order with items for a guest who supplies a phone', function () {
    Mail::fake();

    $order = $this->service->place(
        $this->branch,
        $this->organization,
        ($this->payload)(['customer_takeaway_phone' => '090-1234-5678']),
        null,
        null,
        'ja',
    );

    expect($order->wasRecentlyCreated)->toBeTrue()
        ->and($order->order_type->value)->toBe('takeaway')
        ->and($order->customer_takeaway_phone)->toBe('090-1234-5678')
        ->and($order->customer_locale)->toBe('ja')
        ->and($order->fresh()->items()->count())->toBe(1)
        ->and((float) $order->fresh()->subtotal)->toBe(1000.0);
});

it('queues the order-placed mail when the guest leaves an email', function () {
    Mail::fake();

    $this->service->place(
        $this->branch,
        $this->organization,
        ($this->payload)(['customer_takeaway_email' => 'guest@example.com']),
        null,
        null,
        'ja',
    );

    Mail::assertQueued(OrderPlacedMail::class);
});

it('is durably idempotent — a replay with the same client_order_id returns the same order without double items', function () {
    Mail::fake();

    $durableId = (string) Str::uuid();
    $first = $this->service->place($this->branch, $this->organization, ($this->payload)(['customer_takeaway_phone' => '090-1234-5678']), null, $durableId, 'ja');

    // Same durable id (a retried submit after a cache flush) resolves to the
    // SAME row via unique(client_order_id) — no second order, no doubled items.
    $second = $this->service->place($this->branch, $this->organization, ($this->payload)(['customer_takeaway_phone' => '090-1234-5678']), null, $durableId, 'ja');

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(CustomerOrder::withTrashed()->where('client_order_id', $durableId)->count())->toBe(1)
        ->and($first->fresh()->items()->count())->toBe(1); // not doubled
});

it('exempts a logged-in customer from the contact guard', function () {
    Mail::fake();

    $account = Customer::factory()->selfRegistered()->create(['phone' => '080-0000-0000']);

    // #962 — `place()` nhận ID khách, không nhận model `Customer`. Chữ ký cũ
    // không có type hint nên model lọt qua và được ép chuỗi thành JSON; giờ nó
    // là `?string` nên đây là thứ call site thật phải truyền.
    $order = $this->service->place($this->branch, $this->organization, ($this->payload)(), $account->id, null, 'ja');

    expect($order->wasRecentlyCreated)->toBeTrue()
        ->and($order->customer_id)->toBe($account->id)
        ->and($order->customer_takeaway_phone)->toBeNull();
});
