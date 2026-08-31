<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->deviceToken = Str::random(32);

    $this->device = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->qrMethod = PaymentMethod::factory()->create([
        'code' => 'qr',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    $this->order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);
});

it('persists equal split metadata on kiosk payment', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 1000,
            'metadata' => [
                'split_mode' => 'even',
                'bill_index' => 0,
                'total_bills' => 3,
            ],
        ])
        ->assertCreated();

    $row = OrderPayment::find($response->json('data.payment_id'));

    // metadata carries ONLY the caller's split-bill data now; the channel is a
    // server-owned column, not folded into the blob (#1058/#1059).
    expect($row->metadata)->toBe([
        'split_mode' => 'even',
        'bill_index' => 0,
        'total_bills' => 3,
    ]);
    expect($row->channel)->toBe('kiosk');
});

it('persists split_label in metadata for kiosk split payment', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 1500,
            'metadata' => [
                'split_mode' => 'even',
                'bill_index' => 0,
                'total_bills' => 2,
                'label' => '1/2',
            ],
        ])
        ->assertCreated();

    $row = OrderPayment::find($response->json('data.payment_id'));

    expect($row->metadata)->toMatchArray([
        'split_mode' => 'even',
        'label' => '1/2',
    ]);
});

it('rejects invalid split_mode from kiosk', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 1000,
            'metadata' => [
                'split_mode' => 'invalid_mode',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.split_mode']);
});

it('accepts null metadata from kiosk and stamps orchestrator channel', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 3000,
        ])
        ->assertCreated();

    $row = OrderPayment::find($response->json('data.payment_id'));

    // No split data → metadata stays NULL (the caller-owned contract). The
    // channel rides its own server-owned column (#1058/#1059).
    expect($row->metadata)->toBeNull();
    expect($row->channel)->toBe('kiosk');
});

it('#2865 kiosk CŨ gửi tên cũ vẫn được nhận, và LƯU bằng canonical', function () {
    // Kiosk là app native trên tablet, không tự cập nhật — nên "bản cũ còn gửi
    // `equal`" là trạng thái mặc định sau deploy, không phải ngoại lệ. Docblock
    // của `OrderSplitMode` nêu đích danh kiosk là một nguồn tên cũ.
    //
    // Đo QUA ENDPOINT chứ không qua service: `$request->validate()` strip mọi
    // khoá không có rule, nên luật `in:` và normalizer là HAI thứ phải cùng
    // đúng — và `KioskController` có validator RIÊNG, chính là tập thứ ba mà
    // rào #2860 bắt được.
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->postJson('/api/v1/kiosk/payments', [
            'order_id' => $this->order->id,
            'method' => 'qr',
            'amount' => 1000,
            'metadata' => ['split_mode' => 'equal', 'bill_index' => 0, 'total_bills' => 2],
        ])
        ->assertCreated();

    // Nhận tên cũ ở biên, nhưng cột chỉ chứa canonical — nếu không, migration
    // đã chạy rồi mà dữ liệu mới vẫn nhỏ giọt tên cũ vào DB.
    expect(OrderPayment::find($response->json('data.payment_id'))->metadata['split_mode'])
        ->toBe('even');
});
