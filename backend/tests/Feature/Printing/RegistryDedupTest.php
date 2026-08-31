<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\User;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Support\Str;

/**
 * plan-052 T1.5 / P-18 (#1166) — one registry per physical thing.
 *
 * A printer used to be registrable BOTH in `printers` and as a peripheral
 * device. Two registries for one machine means a shop can configure the same
 * printer twice, at two addresses, and neither screen shows the other's row.
 */
describe('ALLOWED_TYPES', function () {
    it('no longer offers the three printer types', function (string $type) {
        expect(PeripheralDeviceService::ALLOWED_TYPES)->not->toContain($type);
    })->with(['receipt_printer', 'kitchen_printer', 'bar_printer']);

    it('still offers the payment hardware 周辺機器 is actually for', function (string $type) {
        expect(PeripheralDeviceService::ALLOWED_TYPES)->toContain($type);
    })->with(['payment_terminal', 'coin_changer', 'pos', 'workstation', 'kiosk']);

    it('names the retired types so the guard and the purge command agree', function () {
        expect(PeripheralDeviceService::RETIRED_PRINTER_TYPES)
            ->toBe(['receipt_printer', 'kitchen_printer', 'bar_printer'])
            ->and(array_intersect(
                PeripheralDeviceService::RETIRED_PRINTER_TYPES,
                PeripheralDeviceService::ALLOWED_TYPES,
            ))->toBe([]);
    });
});

describe('the API refuses the retired types', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $this->orgId,
            'console_organization_id' => $this->orgId,
        ]);
        $brand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $this->shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $brand->console_brand_id,
            'slug' => 'dedup-shop',
            'is_active' => true,
        ]);
        $this->user = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($this->user, $this->orgId);
    });

    it('422s a shop trying to register a printer as a peripheral device', function (string $type) {
        $this->actingAs($this->user)
            ->postJson("/api/v1/shops/{$this->shop->slug}/peripheral-devices", [
                'name' => 'Bếp 1',
                'type' => $type,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    })->with(['receipt_printer', 'kitchen_printer', 'bar_printer']);

    it('still accepts a payment terminal', function () {
        $this->actingAs($this->user)
            ->postJson("/api/v1/shops/{$this->shop->slug}/peripheral-devices", [
                'name' => 'P400',
                'type' => 'payment_terminal',
                'metadata' => ['host' => '192.168.1.50', 'port' => 8080],
            ])
            ->assertCreated();
    });
});

describe('P-18 — hàng printer legacy KHÔNG bị xoá', function () {
    // Lệnh `peripheral-devices:purge-printers` ĐÃ GỠ ở #2507: production còn 0
    // hàng thuộc `RETIRED_PRINTER_TYPES`, nên bản vá một lần đó hết đối tượng.
    //
    // Bất biến của P-18 thì KHÔNG mất theo: không đường nào trong plan-052 được
    // xoá một hàng peripheral device. Giữ phần ghim đó ở đây, bỏ phần gọi lệnh.
    it('không đường nào của plan-052 xoá hàng legacy', function () {
        $device = PeripheralDevice::factory()->retiredPrinterType()->create();

        expect(PeripheralDevice::withTrashed()->find($device->id))->not->toBeNull()
            ->and(PeripheralDevice::find($device->id))->not->toBeNull();
    });
});
