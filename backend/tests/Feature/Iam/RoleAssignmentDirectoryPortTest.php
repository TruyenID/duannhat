<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleUserPivot;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use Illuminate\Support\Str;

/**
 * #1622 — cổng đọc phân công vai mà PlatformIntegration công bố cho Notifications.
 *
 * Ghim đúng những thứ mà một cổng dựng sai làm hỏng LẶNG LẼ trên đường gửi
 * thông báo: **phạm vi** (sai ⇒ gửi cho người của tổ chức khác) và **khử trùng
 * lặp** (thiếu ⇒ một người nhận nhiều bản của cùng một thông báo).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->otherOrgId, 'console_organization_id' => $this->otherOrgId]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    // `RoleFactory` cố ý không có `definition()` — `roles.name` NOT NULL không
    // default (ghi trong `FactoriesCanCreateRowsTest`). Tạo thẳng như mọi test
    // khác trong repo.
    $this->manager = Role::create(['slug' => 'shop-manager', 'name' => 'Shop Manager', 'level' => 20]);
    $this->cook = Role::create(['slug' => 'cook', 'name' => 'Cook', 'level' => 10]);

    $this->directory = app(RoleAssignmentDirectory::class);

    $this->assign = function (User $user, Role $role, ?string $orgId, ?string $branchId): void {
        RoleUserPivot::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $orgId,
            'branch_id' => $branchId,
        ]);
    };
});

it('có binding thật, không phải interface rỗng', function () {
    expect($this->directory)->toBeInstanceOf(RoleAssignmentDirectory::class);
});

it('lọc theo vai VÀ theo tổ chức — không rò người của tổ chức khác', function () {
    $inScope = User::factory()->create();
    $wrongOrg = User::factory()->create();
    $wrongRole = User::factory()->create();

    ($this->assign)($inScope, $this->manager, $this->orgId, null);
    ($this->assign)($wrongOrg, $this->manager, $this->otherOrgId, null);
    ($this->assign)($wrongRole, $this->cook, $this->orgId, null);

    $ids = $this->directory->userIdsWithRoleInOrganization('shop-manager', $this->orgId);

    expect($ids)->toBe([(string) $inScope->id]);
});

it('lọc theo vai VÀ theo chi nhánh', function () {
    $here = User::factory()->create();
    $elsewhere = User::factory()->create();

    ($this->assign)($here, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($elsewhere, $this->manager, $this->orgId, (string) $this->otherBranch->id);

    $ids = $this->directory->userIdsWithRoleInBranch('shop-manager', (string) $this->branch->id);

    expect($ids)->toBe([(string) $here->id]);
});

/**
 * Một người có NHIỀU dòng phân công là chuyện bình thường (nhiều vai, nhiều
 * chi nhánh). Không khử trùng lặp ⇒ danh sách người nhận có bản trùng ⇒ **gửi
 * trùng thông báo**. Không test nào ở tầng trên bắt được: thông báo vẫn tới.
 */
it('khử trùng lặp khi một người có nhiều dòng phân công', function () {
    $user = User::factory()->create();
    ($this->assign)($user, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($user, $this->cook, $this->orgId, (string) $this->branch->id);
    ($this->assign)($user, $this->manager, $this->orgId, (string) $this->otherBranch->id);

    expect($this->directory->userIdsInOrganizations([$this->orgId]))->toBe([(string) $user->id]);
});

it('phạm vi NHIỀU tổ chức (brand trải trên nhiều org local)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    ($this->assign)($a, $this->manager, $this->orgId, null);
    ($this->assign)($b, $this->manager, $this->otherOrgId, null);

    $ids = $this->directory->userIdsWithRoleInOrganizations('shop-manager', [$this->orgId, $this->otherOrgId]);

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain((string) $a->id)
        ->and($ids)->toContain((string) $b->id);
});

it('danh sách rỗng → trả rỗng, KHÔNG quét cả bảng', function () {
    $user = User::factory()->create();
    ($this->assign)($user, $this->manager, $this->orgId, null);

    expect($this->directory->userIdsInOrganizations([]))->toBe([])
        ->and($this->directory->userIdsWithRoleInOrganizations('shop-manager', []))->toBe([])
        ->and($this->directory->assignmentsInBranches([]))->toBe([])
        ->and($this->directory->userIdsAssignedToBranch([], (string) $this->branch->id))->toBe([]);
});

it('assignmentsInBranches trả kèm branchId — một người có thể ở nhiều cửa hàng', function () {
    $user = User::factory()->create();
    ($this->assign)($user, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($user, $this->cook, $this->orgId, (string) $this->otherBranch->id);

    $rows = $this->directory->assignmentsInBranches([
        (string) $this->branch->id,
        (string) $this->otherBranch->id,
    ]);

    expect($rows)->toHaveCount(2);
    $branchIds = array_map(static fn (array $r): string => $r['branchId'], $rows);
    sort($branchIds);
    $expected = [(string) $this->branch->id, (string) $this->otherBranch->id];
    sort($expected);
    expect($branchIds)->toBe($expected);
});

it('assignmentsInBranches lọc theo vai khi được yêu cầu', function () {
    $manager = User::factory()->create();
    $cook = User::factory()->create();
    ($this->assign)($manager, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($cook, $this->cook, $this->orgId, (string) $this->branch->id);

    $rows = $this->directory->assignmentsInBranches([(string) $this->branch->id], 'shop-manager');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['userId'])->toBe((string) $manager->id);
});

/**
 * `userIdsAssignedToBranch` là method DUY NHẤT không có `distinct()` ở tầng SQL
 * (bản cũ khử trùng lặp ở tầng collection bằng `->unique()`), nên nó là chỗ duy
 * nhất `array_unique` của adapter thật sự chịu lực. Bỏ nó ⇒ một người có hai vai
 * ở cùng cửa hàng lọt vào danh sách hai lần ⇒ **nhận trùng thông báo**.
 */
it('khử trùng lặp ở phép giao — hai vai cùng một cửa hàng vẫn ra MỘT id', function () {
    $user = User::factory()->create();
    ($this->assign)($user, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($user, $this->cook, $this->orgId, (string) $this->branch->id);

    $ids = $this->directory->userIdsAssignedToBranch([(string) $user->id], (string) $this->branch->id);

    expect($ids)->toBe([(string) $user->id]);
});

it('userIdsAssignedToBranch là phép GIAO, không phải tra cứu', function () {
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $notPassedIn = User::factory()->create();

    ($this->assign)($member, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($notPassedIn, $this->manager, $this->orgId, (string) $this->branch->id);
    ($this->assign)($outsider, $this->manager, $this->orgId, (string) $this->otherBranch->id);

    $ids = $this->directory->userIdsAssignedToBranch(
        [(string) $member->id, (string) $outsider->id],
        (string) $this->branch->id,
    );

    expect($ids)->toBe([(string) $member->id]);
});
