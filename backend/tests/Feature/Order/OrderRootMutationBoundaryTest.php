<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\ConfirmOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Internal\EloquentOrderPersistence;
use App\Services\Order\OrderService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->organization->id]);
    $this->mutations = app(OrderMutationFacade::class);
});

it('binds the public order mutation facade to OrderService', function () {
    expect($this->mutations)->toBeInstanceOf(OrderService::class);
});

it('keeps HTTP order transports off the internal persistence class', function () {
    $transportFiles = array_merge(
        glob(app_path('Http/Controllers/Api/V1/Customer/*Order*.php')) ?: [],
        glob(app_path('Http/Controllers/Api/V1/Workstation/Order*.php')) ?: [],
        glob(app_path('Http/Controllers/Api/V1/Kiosk/*.php')) ?: [],
        glob(app_path('Http/Controllers/Api/V1/Kds/*.php')) ?: [],
        glob(app_path('Http/Controllers/Api/V1/Handy/*.php')) ?: [],
    );

    foreach ($transportFiles as $file) {
        expect(file_get_contents($file))->not->toContain(EloquentOrderPersistence::class);
    }
});

it('confirms a pending order through the typed facade', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Pending,
    ]);

    $context = new MutationContext(
        $this->organization->id,
        null,
        (string) Str::uuid(),
        (string) Str::uuid(),
        expectedVersion: 1,
    );

    $result = $this->mutations->confirm(new ConfirmOrderCommand($context, $order->id));

    expect($result->changed)->toBeTrue()
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('does not expose legacy array payloads on the facade class', function () {
    $facade = file_get_contents(app_path('Services/Order/OrderService.php'));

    expect($facade)->not->toContain('function __call', 'array $data');
});
