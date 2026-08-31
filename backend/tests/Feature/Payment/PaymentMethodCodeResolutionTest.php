<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ghim ƯU TIÊN của `PosEffectivePaymentOptionEnricher::resolveMethodByCode()`.
 *
 * Vì sao file này tồn tại (#1887): tôi gộp `LegacyPaymentMethodResolver` vào
 * enricher với lập luận rằng hai truy vấn giống hệt nhau — cùng cột, cùng phạm
 * vi, CÙNG THỨ TỰ ƯU TIÊN branch-riêng-thắng-org-chung. Lập luận đó đúng, nhưng
 * nó chỉ tồn tại dưới dạng VĂN XUÔI trong commit, PR và docblock: không một test
 * nào tạo hai hàng cùng `code` (một gắn branch, một dùng chung org) rồi khẳng
 * định hàng nào thắng.
 *
 * Nghĩa là ai đó "dọn dẹp" cái `orderByRaw` kia sẽ làm mọi shop có override cấp
 * branch âm thầm nhảy sang hàng dùng chung của tổ chức — sai `payment_method_id`
 * trên mọi khoản thanh toán, và KHÔNG CÓ GÌ ĐỎ. Đó chính xác là loại "số xanh mà
 * không an toàn" mà cả plan-055 sinh ra để chống, nên nó phải được mã hoá chứ
 * không phải được kể lại.
 */
function makeOrg(): Organization
{
    $id = (string) Str::uuid();

    return Organization::factory()->create(['id' => $id, 'console_organization_id' => $id]);
}

function makeBranch(Organization $org): Branch
{
    $brand = Brand::factory()->create(['console_organization_id' => $org->console_organization_id]);

    return Branch::factory()->create([
        'console_organization_id' => $org->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->org = makeOrg();
    $this->branch = makeBranch($this->org);
});

function resolveCode(string $code, string $orgId, string $branchId): ?PaymentMethod
{
    return app(PosEffectivePaymentOptionEnricher::class)->resolveMethodByCode($code, $orgId, $branchId);
}

it('cho hàng gắn BRANCH thắng hàng dùng chung của tổ chức khi trùng mã', function () {
    $shared = PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $this->org->id,
        'branch_id' => null, 'is_active' => true,
    ]);
    $override = PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $this->org->id,
        'branch_id' => $this->branch->id, 'is_active' => true,
    ]);

    $resolved = resolveCode('cash', (string) $this->org->id, (string) $this->branch->id);

    expect($resolved?->id)->toBe($override->id)
        ->and($resolved?->id)->not->toBe($shared->id);
});

it('rơi về hàng dùng chung của tổ chức khi branch không có override', function () {
    $shared = PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $this->org->id,
        'branch_id' => null, 'is_active' => true,
    ]);

    expect(resolveCode('cash', (string) $this->org->id, (string) $this->branch->id)?->id)->toBe($shared->id);
});

it('không bao giờ trả hàng của BRANCH KHÁC', function () {
    $other = makeBranch($this->org);
    PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $this->org->id,
        'branch_id' => $other->id, 'is_active' => true,
    ]);

    expect(resolveCode('cash', (string) $this->org->id, (string) $this->branch->id))->toBeNull();
});

it('không bao giờ trả hàng của TỔ CHỨC KHÁC dù trùng mã và trùng branch id', function () {
    $otherOrg = makeOrg();
    PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $otherOrg->id,
        'branch_id' => $this->branch->id, 'is_active' => true,
    ]);

    expect(resolveCode('cash', (string) $this->org->id, (string) $this->branch->id))->toBeNull();
});

it('bỏ qua hàng is_active = false thay vì trả về nó', function () {
    PaymentMethod::factory()->create([
        'code' => 'cash', 'organization_id' => $this->org->id,
        'branch_id' => $this->branch->id, 'is_active' => false,
    ]);

    expect(resolveCode('cash', (string) $this->org->id, (string) $this->branch->id))->toBeNull();
});

it('trả null (không throw) khi không khớp — quyết định 422 thuộc về controller', function () {
    expect(resolveCode('khong-ton-tai', (string) $this->org->id, (string) $this->branch->id))->toBeNull();
});
