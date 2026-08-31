<?php

/**
 * #3074 — hai connection cho CÙNG một chủ sở hữu là điều KHÔNG THỂ.
 *
 * Trước bản này, thứ duy nhất giữ cho chuyện đó không xảy ra là một lời tra
 * trong `PaymentGatewayOrchestrationBootstrap` — và #3070 cho thấy lời tra đó
 * đủ mong manh: #2893 đóng dấu `acct_…` lên đúng cột nó dùng làm khoá, lời tra
 * trượt, và bootstrap đẻ ra connection thứ hai. Tiền vẫn đúng, không gì đỏ, chỉ
 * có cái sổ tách làm đôi trong im lặng.
 *
 * Ràng buộc ở tầng DB là thứ không phụ thuộc vào việc ai đó nhớ tra bằng cột
 * nào. Bài này ghim nó, và ghim luôn hai tính chất mà một khoá SAI vẫn đi qua:
 * nhượng quyền phải giữ được connection riêng, và hàng HQ không được lọt qua kẽ
 * NULL.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

uses()->group('payment');

function tenantKeyOrg(): array
{
    $consoleOrganizationId = (string) Str::uuid();
    $organization = Organization::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $brand = Brand::factory()->create(['console_organization_id' => $consoleOrganizationId]);

    return [$organization, $brand, $consoleOrganizationId];
}

it('CHẶN connection thứ hai cho cùng một chủ sở hữu HQ', function () {
    // Đây là #3070 tái hiện: cùng provider · environment · org · brand, chủ sở
    // hữu là HQ nên `owner_branch_id` NULL ở cả hai hàng.
    [$organization, $brand] = tenantKeyOrg();

    $first = PaymentGatewayConnection::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_scope' => 'hq',
        'owner_branch_id' => null,
    ]);

    expect(fn () => PaymentGatewayConnection::factory()->create([
        'provider_id' => $first->provider_id,
        'environment' => $first->environment,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_scope' => 'hq',
        'owner_branch_id' => null,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('vẫn CHO PHÉP nhượng quyền có connection riêng dưới cùng org+brand', function () {
    // Vế này quan trọng ngang vế trên. Khoá `(provider, env, org, brand)` mà tôi
    // thử trước đó làm 15 bài đỏ đúng vì nó cấm luôn mô hình nhượng quyền: bên
    // nhận nhượng quyền có tài khoản PSP của chính họ, và schema khai rõ
    // `owner_scope=franchise` dùng operatorOrgUnitId riêng.
    //
    // Một ràng buộc chữa #3070 bằng cách phá tính năng khác thì không phải bản vá.
    [$organization, $brand, $consoleOrganizationId] = tenantKeyOrg();
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    $hq = PaymentGatewayConnection::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_scope' => 'hq',
        'owner_branch_id' => null,
    ]);

    $franchise = PaymentGatewayConnection::factory()->create([
        'provider_id' => $hq->provider_id,
        'environment' => $hq->environment,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_scope' => 'franchise',
        'owner_branch_id' => $branch->id,
    ]);

    expect((string) $franchise->id)->not->toBe((string) $hq->id);
});

it('CHO PHÉP hai tenant dùng chung MỘT tài khoản PSP', function () {
    // Điều mà unique CŨ trên (provider, environment, merchant_account_id) cấm —
    // và #2864 đo được tài khoản Stripe này ĐANG dùng chung với một hệ khác.
    //
    // Chừng nào điều cấm đó còn, ruling #2893 (bản ghi connection phải mang định
    // danh PSP thật) không thể áp cho tenant thứ hai: hàng thứ hai sẽ đâm index
    // ngay lúc thanh toán.
    $shared = 'acct_1LLeKFCUZcB5vP8B';
    [$orgA, $brandA] = tenantKeyOrg();
    [$orgB, $brandB] = tenantKeyOrg();

    $a = PaymentGatewayConnection::factory()->create([
        'organization_id' => $orgA->id,
        'brand_id' => $brandA->id,
        'merchant_account_id' => $shared,
    ]);

    $b = PaymentGatewayConnection::factory()->create([
        'provider_id' => $a->provider_id,
        'environment' => $a->environment,
        'organization_id' => $orgB->id,
        'brand_id' => $brandB->id,
        'merchant_account_id' => $shared,
    ]);

    expect((string) $b->merchant_account_id)->toBe($shared);
});

it('đóng dấu owner_branch_key ở MỌI đường ghi, không nhờ ai nhớ', function () {
    // Cột khoá mà bốn nơi tạo connection phải nhớ điền là một cột sẽ bị quên, và
    // quên ở đây nghĩa là hàng mới trượt khỏi ràng buộc duy nhất.
    [$organization, $brand, $consoleOrganizationId] = tenantKeyOrg();
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    $hq = PaymentGatewayConnection::factory()->create(['owner_branch_id' => null]);
    expect((string) $hq->fresh()->owner_branch_key)
        ->toBe(PaymentGatewayConnection::HQ_OWNER_BRANCH_KEY);

    $franchise = PaymentGatewayConnection::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_scope' => 'franchise',
        'owner_branch_id' => $branch->id,
    ]);
    expect((string) $franchise->fresh()->owner_branch_key)->toBe((string) $branch->id);

    // Và nó theo kịp khi chủ sở hữu ĐỔI — nếu không, một hàng chuyển từ HQ sang
    // nhượng quyền sẽ giữ khoá cũ và chiếm mất chỗ của HQ mãi mãi.
    $franchise->owner_branch_id = null;
    $franchise->owner_scope = 'hq';
    $franchise->save();
    expect((string) $franchise->fresh()->owner_branch_key)
        ->toBe(PaymentGatewayConnection::HQ_OWNER_BRANCH_KEY);
});
