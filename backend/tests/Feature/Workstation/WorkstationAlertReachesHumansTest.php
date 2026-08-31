<?php

declare(strict_types=1);

/**
 * #2847 — alert máy trạm phải tới được một CON NGƯỜI, không chỉ tới được 202.
 *
 * `WorkstationAlertUplinkTest` cạnh file này thay `NotificationDispatcher` bằng
 * bản giả, nên nó ghim đúng thứ nó định ghim (hình dạng request, khoá chặn lặp,
 * fail-open) — nhưng bản giả **bỏ qua `scopeId`**, nên nó xanh y hệt khi org id
 * là chuỗi rỗng. Đó là cách lỗi này sống sót và chạy 7.523 lần trên production
 * trong hai ngày với tỷ lệ hỏng 100%.
 *
 * Bài này đi ngược lại một cách có chủ đích: **dispatcher THẬT**, đi **qua
 * endpoint**, và khẳng định `count(recipients) > 0`. Endpoint cố ý trả 202 kể
 * cả khi dispatch hỏng (fail-open, và đó là quyết định đúng), nên `assertStatus`
 * và `accepted` đều KHÔNG chứng minh được gì về việc tới người — chỉ con số
 * người nhận mới phân biệt "đã báo" với "đã ghi một hàng cho không ai".
 *
 * Hai chi tiết cố tình dựng lệch nhau vì production dựng lệch nhau:
 *
 *   1. `organizations.id` ≠ `console_organization_id`. Dùng thẳng
 *      `$branch->console_organization_id` làm org id sẽ xanh nếu hai giá trị
 *      bằng nhau, và sai trên production nơi chúng khác nhau.
 *   2. Vai lưu là **`tempo-admin`**, không phải `org-admin`. Trên production
 *      KHÔNG AI giữ slug ma trận — cả 4 user đều giữ `tempo-admin` (CLAUDE.md
 *      § "Vai người dùng"). Fixture dùng `org-admin` sẽ ghim một thế giới không
 *      tồn tại.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->consoleOrgId = (string) Str::uuid();

    // id local KHÁC console id — xem chú thích (1) ở đầu file.
    $this->organization = Organization::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'slug' => 'wsalert-'.Str::random(6),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->push = fn (array $alerts) => $this
        ->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/alerts', ['alerts' => $alerts]);
});

/** Gắn vai đúng hình dạng `role_user_pivots` mà cổng vai đọc. */
function wsAlertAssignRole(User $user, string $slug, string $organizationId, ?string $branchId): void
{
    $role = Role::firstOrCreate(
        ['slug' => $slug],
        [
            'id' => (string) Str::uuid(),
            'console_organization_id' => $organizationId,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'level' => 100,
        ],
    );

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return list<string> */
function wsAlertRecipientIds(): array
{
    $notification = Notification::query()
        ->where('type', 'workstation.alert')
        ->latest('id')
        ->first();

    expect($notification)->not->toBeNull();

    return $notification->recipients()->pluck('recipient_id')->all();
}

// =========================================================================
//  PHẢI TỚI NGƯỜI
// =========================================================================

it('#2847 alert máy trạm tới người giữ tempo-admin — người nhận KHÁC RỖNG', function () {
    // `branch_id = NULL` = all_branches_access (#2460): NULL nghĩa là MỌI chi
    // nhánh, không phải "không chi nhánh nào".
    $admin = User::factory()->create(['console_organization_id' => $this->consoleOrgId]);
    wsAlertAssignRole($admin, 'tempo-admin', (string) $this->organization->id, null);

    ($this->push)([[
        'kind' => 'cloud_money_overwrite',
        'subject' => 'order-1',
        'severity' => 'warning',
        'title' => 'Cloud tính lại tiền của đơn này khác số máy trạm đang giữ',
    ]])->assertStatus(202);

    $recipientIds = wsAlertRecipientIds();

    // ĐÂY là khẳng định có giá. Với `(string) $branch->organization_id` (bản
    // trước #2847) hàng `notifications` vẫn KHÔNG được tạo vì dispatch ném
    // trước đó — nhưng kể cả khi nó được tạo, danh sách này vẫn rỗng.
    expect(count($recipientIds))->toBeGreaterThan(0)
        ->and($recipientIds)->toContain($admin->id);
});

it('#2847 người giữ vai ở tổ chức KHÁC không nhận nhầm', function () {
    // Nếu ai đó "sửa" lỗi bằng cách bỏ scope đi cho hết lỗi thì bài này đỏ.
    // Một cảnh báo gửi nhầm sang tổ chức khác tệ hơn một cảnh báo không gửi.
    $mine = User::factory()->create(['console_organization_id' => $this->consoleOrgId]);
    wsAlertAssignRole($mine, 'tempo-admin', (string) $this->organization->id, null);

    $otherConsoleOrgId = (string) Str::uuid();
    $otherOrg = Organization::factory()->create(['console_organization_id' => $otherConsoleOrgId]);
    $stranger = User::factory()->create(['console_organization_id' => $otherConsoleOrgId]);
    wsAlertAssignRole($stranger, 'tempo-admin', (string) $otherOrg->id, null);

    ($this->push)([[
        'kind' => 'no_printer',
        'subject' => 'receipt_printer',
        'severity' => 'critical',
        'title' => 'Chưa cấu hình máy in hoá đơn',
    ]])->assertStatus(202);

    $recipientIds = wsAlertRecipientIds();

    expect($recipientIds)->toContain($mine->id)
        ->and($recipientIds)->not->toContain($stranger->id);
});

// =========================================================================
//  PHẢI IM ĐÚNG CHỖ
// =========================================================================

it('#2847 tổ chức chưa nhân bản về Tempo thì 422, không dispatch với org rỗng', function () {
    // Chi nhánh trỏ tới một console org không có bản sao local. Bản trước lặng
    // lẽ dispatch với `''`; giờ nó phải nói ra.
    $orphanBranch = Branch::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->organization->id,
        'branch_id' => $orphanBranch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workstation/alerts', ['alerts' => [[
            'kind' => 'no_printer',
            'subject' => 'receipt_printer',
            'severity' => 'critical',
            'title' => 'Chưa cấu hình máy in hoá đơn',
        ]]])
        ->assertStatus(422);

    expect(Notification::query()->where('type', 'workstation.alert')->count())->toBe(0);
});
