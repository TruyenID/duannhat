<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Carbon\Carbon;
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

    $this->wsToken = Str::random(64);

    $this->wsDevice = Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns orders changed after updated_since cursor', function () {
    $old = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'created_at' => Carbon::parse('2026-05-25 10:00:00'),
        'updated_at' => Carbon::parse('2026-05-25 10:00:00'),
    ]);
    $recent = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'created_at' => Carbon::parse('2026-05-25 10:00:00'),
        'updated_at' => Carbon::parse('2026-05-26 14:30:00'),
    ]);

    $cursor = '2026-05-26T00:00:00Z';

    $response = $this->withHeader('Authorization', "Bearer {$this->wsToken}")
        ->getJson('/api/v1/workstation/orders?updated_since='.urlencode($cursor));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($recent->id)->not->toContain($old->id);
});

it('rejects passing both since and updated_since', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->wsToken}")
        ->getJson('/api/v1/workstation/orders?since=2026-05-25&updated_since=2026-05-26');

    $response->assertStatus(422);
});

it('always returns since key in response for backward compat', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_ws_resp_shape',
        'branch_id' => $branch->id,
    ]);

    // mode 1: no params (default 30d)
    $this->withHeader('Authorization', 'Bearer tok_ws_resp_shape')
        ->getJson('/api/v1/workstation/orders')
        ->assertOk()
        ->assertJsonStructure(['data', 'count', 'since', 'generated_at']);

    // mode 2: legacy since
    $this->withHeader('Authorization', 'Bearer tok_ws_resp_shape')
        ->getJson('/api/v1/workstation/orders?since=2026-05-01')
        ->assertOk()
        ->assertJsonStructure(['data', 'count', 'since', 'generated_at']);

    // mode 3: new updated_since
    $this->withHeader('Authorization', 'Bearer tok_ws_resp_shape')
        ->getJson('/api/v1/workstation/orders?updated_since=2026-05-01')
        ->assertOk()
        ->assertJsonStructure(['data', 'count', 'since', 'generated_at']);
});
