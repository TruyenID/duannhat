<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use App\Sso\UserProvisioner;
use App\Support\Iam\RoleTemplateMatrix;

/**
 * #2460 — cổng đọc phân công vai phải nhìn pivot GIỐNG tầng policy.
 *
 * Hai bất biến dưới đây từng sai theo cùng một kiểu: không ném lỗi, không đỏ
 * test, chỉ lặng lẽ phân giải ra 0 người nhận.
 *
 * 1. **Slug tương đương.** Platform cấp vai dưới tên `tempo-*`
 *    ({@see UserProvisioner}), không phải slug template. `hasRoleInContext()`
 *    khai triển qua `RoleTemplateMatrix::equivalentSlugs()`; directory thì so
 *    chuỗi trần ⇒ mọi user SSO vô hình. Đo trên production 2026-08-11: **1/1
 *    user giữ `tempo-admin`, KHÔNG ai giữ slug ma trận**.
 *
 * 2. **`branch_id IS NULL` = MỌI chi nhánh.** Đó là cách
 *    `UserProvisioner::syncRoleScopes()` ghi `all_branches_access` của Platform.
 *    `where branch_id = X` bỏ đúng những người quyền cao nhất.
 *
 * Cái giá đã trả: 2026-08-11 gán tay `shop-manager` cho một user ở đủ 17/17 chi
 * nhánh production để "vá" triệu chứng. Nó bốc hơi ở lần đăng nhập kế —
 * `syncRoleScopes()` xoá sạch pivot rồi dựng lại từ Platform. Không có gì để
 * gán: Platform đã nói `all_branches_access = true` rồi, chỉ là bên đọc không
 * hiểu.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->branches = collect(range(1, 3))->map(fn (int $i) => Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]));

    $this->directory = app(RoleAssignmentDirectory::class);
});

/** Cấp vai đúng cách Platform cấp: một dòng, branch để trống = mọi chi nhánh. */
function grantAllBranches(User $user, string $slug, Organization $org, int $level = 100): Role
{
    $role = Role::query()->firstOrCreate(
        ['slug' => $slug, 'console_organization_id' => null],
        ['name' => $slug, 'level' => $level],
    );

    $user->assignRole($role, $org->id);

    return $role;
}

it('#2460 — người có all_branches_access được TÌM THẤY ở mọi chi nhánh của tổ chức', function () {
    $user = User::factory()->create();
    grantAllBranches($user, 'shop-manager', $this->organization, 60);

    foreach ($this->branches as $branch) {
        // `toContain()` của Pest là VARIADIC — tham số hai là một needle nữa,
        // không phải thông điệp. Ghi chú lại vì đúng cái nhầm đó vừa làm bài
        // test này đỏ trong khi mã đã chạy đúng.
        expect($this->directory->userIdsWithRoleInBranch('shop-manager', (string) $branch->id))
            ->toContain((string) $user->id);
    }
});

it('#2460 — nhưng KHÔNG rò sang chi nhánh của tổ chức khác', function () {
    $user = User::factory()->create();
    grantAllBranches($user, 'shop-manager', $this->organization, 60);

    $otherOrg = Organization::factory()->create();
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrg->console_organization_id,
    ]);

    expect($this->directory->userIdsWithRoleInBranch('shop-manager', (string) $otherBranch->id))
        ->not->toContain((string) $user->id);
});

it('#2460 — vai Platform `tempo-admin` khớp truy vấn theo slug template', function () {
    $user = User::factory()->create();
    grantAllBranches($user, 'tempo-admin', $this->organization, 100);

    // Cùng một phép hỏi mà tầng policy đã trả lời ĐÚNG từ trước...
    expect($user->hasRoleInContext('org-admin', $this->organization->id))->toBeTrue();

    // ...giờ directory cũng phải trả lời như vậy.
    expect($this->directory->userIdsWithRoleInOrganization('org-admin', (string) $this->organization->id))
        ->toContain((string) $user->id);

    foreach ($this->branches as $branch) {
        expect($this->directory->userIdsWithRoleInBranch('org-admin', (string) $branch->id))
            ->toContain((string) $user->id);
    }
});

it('#2460 — assignmentsInBranches trải người toàn tổ chức ra từng chi nhánh được hỏi', function () {
    $user = User::factory()->create();
    grantAllBranches($user, 'shop-manager', $this->organization, 60);

    $branchIds = $this->branches->map(fn (Branch $b): string => (string) $b->id)->all();
    $rows = collect($this->directory->assignmentsInBranches($branchIds, 'shop-manager'));

    expect($rows->where('userId', (string) $user->id)->pluck('branchId')->sort()->values()->all())
        ->toBe(collect($branchIds)->sort()->values()->all());
});

it('#2460 — userIdsAssignedToBranch coi người toàn tổ chức là thuộc chi nhánh', function () {
    $user = User::factory()->create();
    grantAllBranches($user, 'shop-manager', $this->organization, 60);

    expect($this->directory->userIdsAssignedToBranch([(string) $user->id], (string) $this->branches->first()->id))
        ->toBe([(string) $user->id]);
});

it('#2460 — phân công theo TỪNG chi nhánh vẫn chỉ thuộc chi nhánh đó', function () {
    $user = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['slug' => 'shop-manager', 'console_organization_id' => null],
        ['name' => 'Shop Manager', 'level' => 60],
    );
    $user->assignRole($role, $this->organization->id, (string) $this->branches->first()->id);

    expect($this->directory->userIdsWithRoleInBranch('shop-manager', (string) $this->branches->first()->id))
        ->toContain((string) $user->id)
        ->and($this->directory->userIdsWithRoleInBranch('shop-manager', (string) $this->branches->last()->id))
        ->not->toContain((string) $user->id);
});

it('#2460 — mọi slug mà UserProvisioner có thể đúc đều có trong bảng ánh xạ', function () {
    // Nguồn: Platform `OrganizationController` — `$role = $access?->role ?? 'member'`,
    // và `service_role_level` phân nhánh trên admin/manager/(mặc định).
    $platformServiceRoles = ['owner', 'admin', 'manager', 'member', 'staff'];

    foreach ($platformServiceRoles as $serviceRole) {
        $slug = 'tempo-'.$serviceRole;

        // `toHaveKey($k, $v)` của Pest so GIÁ TRỊ ở tham số hai, không phải
        // thông điệp — dùng nó ở đây sẽ so slug với câu tiếng Việt.
        expect(array_key_exists($slug, RoleTemplateMatrix::PLATFORM_ROLE_TEMPLATES))->toBeTrue(
            "Platform gửi service_role `{$serviceRole}` nhưng `{$slug}` không có trong PLATFORM_ROLE_TEMPLATES — ".
            'user đó sẽ có quyền nhưng vô hình với mọi truy vấn theo vai.',
        );

        $template = RoleTemplateMatrix::PLATFORM_ROLE_TEMPLATES[$slug];

        expect(array_key_exists($template, RoleTemplateMatrix::ROLES))->toBeTrue()
            ->and(RoleTemplateMatrix::equivalentSlugs($template))->toContain($slug);
    }
});
