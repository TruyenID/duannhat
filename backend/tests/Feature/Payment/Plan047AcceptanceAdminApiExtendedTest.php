<?php

/**
 * Plan 047 acceptance — admin API G11 and error-shape guarantees.
 */

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->fixtures->seedConnection();
});

describe('G11 distinct API error classes', function () {
    it('G11 returns 401 for unauthenticated shop payment configuration', function () {
        auth()->logout();
        $this->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertUnauthorized();
    });

    it('G11 returns 403 for cross-shop access to payment configuration', function () {
        $foreignManager = User::factory()->create([
            'console_organization_id' => (string) Str::uuid(),
        ]);
        grantOrgAccess($foreignManager, (string) $foreignManager->console_organization_id);

        $this->actingAs($foreignManager)
            ->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertForbidden();
    });

    it('G11 returns 409 conflict when device policy widen is blocked', function () {
        $device = $this->fixtures->seedDevice('pos');
        $base = "{$this->fixtures->shopBase()}/devices/{$device->id}/payment-options";

        $this->actingAs($this->fixtures->manager)
            ->patchJson($base, [
                'option_id' => $this->fixtures->option->id,
                'preference' => 'enabled',
            ])->assertStatus(409)
            ->assertJsonStructure(['message']);
    });

    it('G11 returns 422 for missing payment option preference payload', function () {
        $this->actingAs($this->fixtures->manager)
            ->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [])
            ->assertStatus(422);
    });
});

describe('G1 G6 G7 G9 G10 registry', function () {
    it('G1 G6 G7 G9 G10 are covered by PaymentGatewayAdminTest and ShopPaymentPolicyApiTest', function () {
        $hq = file_get_contents(base_path('tests/Feature/HQ/PaymentGatewayAdminTest.php'));
        $shop = file_get_contents(base_path('tests/Feature/Payment/ShopPaymentPolicyApiTest.php'));

        expect($hq)->toContain('G1')
            ->and($shop)->toContain('G9 option rows expose capability preference effective source and trace')
            ->and($shop)->toContain('G10 device inherit customize disable reset flow is reversible');
    });
});

describe('G4 secrets never in GET payloads', function () {
    it('G4 shop payment configuration omits secret fields from JSON', function () {
        $payload = $this->actingAs($this->fixtures->manager)
            ->getJson("{$this->fixtures->shopBase()}/payment-configuration")
            ->assertOk()
            ->json();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        expect($encoded)->not->toMatch('/sk_(live|test)_/i')
            ->and($encoded)->not->toMatch('/whsec_/i');
    });
});

describe('G11 device token cannot administer connections', function () {
    it('G11 workstation device token cannot PATCH HQ gateway connections', function () {
        $device = Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => 'ws-g11-admin',
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ws-g11-admin'])
            ->patchJson("/api/v1/hq/{$this->fixtures->brand->slug}/payment-gateway-connections/".(string) Str::uuid(), [
                'merchant_display_name' => 'blocked',
            ])->assertStatus(404);
    });
});

describe('G12 G13 G14 G15 admin-web coverage registry', function () {
    it('G12 G14 are covered by shop-payments-settings-page vitest suite', function () {
        // #2333/#2343 — admin-web ĐÃ in-tree tại web/admin từ #2306. Đường dẫn
        // sibling cũ không giải được, nên bài này SKIP ở mọi lượt chạy kể cả CI:
        // hợp đồng coverage G12/G14 đã thôi được canh mà không ai thấy. Không còn
        // hoàn cảnh "chưa checkout" nào để bỏ qua, nên vắng file là ĐỎ.
        $path = base_path('../web/admin/src/__tests__/shop-payments-settings-page.test.tsx');
        expect(is_file($path))->toBeTrue(
            "không thấy {$path} — hợp đồng coverage G12/G14 rỗng; kiểm lại đường dẫn hoặc cây."
        );

        $source = file_get_contents($path);
        expect($source)->toContain('G12 mutually exclusive states')
            ->and($source)->toContain('secrets never in DOM');
    });
});
