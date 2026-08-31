<?php

declare(strict_types=1);

/**
 * #1445 — chứng từ thuộc QUỐC GIA NƠI SHOP TỒN TẠI.
 *
 * `vat_invoice` (hoá đơn GTGT) và `red_invoice` (hoá đơn đỏ) là chứng từ luật
 * định Việt Nam. Một brand ở Nhật không in bản dịch của chúng — nó in chứng từ
 * Nhật (適格簡易請求書), một `kind` KHÁC chưa tồn tại (tách plan riêng). Nên
 * catalog HQ phải KHÔNG liệt kê chúng cho brand JP.
 *
 * Bốn trục độc lập, không suy diễn chéo: quốc gia tuân thủ ≠ tiền tệ ≠ múi giờ ≠
 * ngôn ngữ in. Test này chỉ đụng trục thứ nhất.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/** @return array{0: Brand, 1: User} */
function brandInCountry(?string $country): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(array_filter([
        'id' => $orgId,
        'console_organization_id' => $orgId,
        // `null` = không truyền, để cột nhận default DB ('JP') — mô phỏng org
        // chưa bao giờ được mirror quốc gia. Cột NOT NULL nên không có "trống".
        'operating_country' => $country,
    ], fn ($v) => $v !== null));

    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => $country === 'VN' ? 'Asia/Ho_Chi_Minh' : 'Asia/Tokyo',
    ]);

    $admin = User::factory()->create(['console_organization_id' => $orgId]);
    grantOrgAccess($admin, $orgId);

    return [$brand, $admin];
}

/** @return list<string> */
function catalogKinds(Brand $brand, User $admin): array
{
    $response = test()->actingAs($admin)
        ->getJson("/api/v1/hq/{$brand->slug}/print-templates")
        ->assertOk();

    return array_column($response->json('data'), 'kind');
}

it('C1: brand VN thấy hai chứng từ luật định Việt Nam', function () {
    [$brand, $admin] = brandInCountry('VN');

    expect(catalogKinds($brand, $admin))
        ->toContain('vat_invoice')
        ->toContain('red_invoice');
});

it('C2: brand JP KHÔNG thấy chúng — và không thấy bản dịch nào của chúng', function () {
    [$brand, $admin] = brandInCountry('JP');

    expect(catalogKinds($brand, $admin))
        ->not->toContain('vat_invoice')
        ->not->toContain('red_invoice');
});

it('C3: chứng từ vận hành thì nước nào cũng có', function () {
    // Cổng quốc gia chỉ được chạm hai kind luật định. Nếu ai đó lỡ tay gắn
    // `countries()` cho một kind vận hành, quán Nhật sẽ mất phiếu bếp.
    [$jpBrand, $jpAdmin] = brandInCountry('JP');
    [$vnBrand, $vnAdmin] = brandInCountry('VN');

    $operational = ['receipt', 'kitchen', 'runner', 'remaining', 'shift_report'];

    expect(catalogKinds($jpBrand, $jpAdmin))->toContain(...$operational);
    expect(catalogKinds($vnBrand, $vnAdmin))->toContain(...$operational);
});

it('C4: org chưa mirror quốc gia đi theo posture JP như mọi chỗ khác', function () {
    // `organizations.operating_country` là NOT NULL default 'JP', và
    // `ComplianceProfileResolver` cũng fail-safe về JP cho org không tìm thấy
    // (#1153). Ở đây ta ĐI THEO nó thay vì tự bịa một fallback thứ hai — hai
    // đường đọc quốc gia là hai lần cơ hội lệch nhau.
    [$brand, $admin] = brandInCountry(null);

    expect(catalogKinds($brand, $admin))
        ->not->toContain('vat_invoice')
        ->toContain('receipt');
});
