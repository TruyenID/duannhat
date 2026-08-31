<?php

declare(strict_types=1);

/**
 * #2901 — bề mặt HQ: ai được yêu cầu kéo log máy trạm, và ai được đọc chúng.
 *
 * Đây là bề mặt PII, nên bài nặng nhất ở đây là bài **từ chối**: một tài khoản
 * thu ngân (`shop-staff`) có vai hợp lệ trong tổ chức và đi qua được
 * `ResolveBrandFromSlug` — thứ duy nhất đứng giữa nó và log của quán là policy.
 * #1271 là án lệ ngay trong repo này: một policy được viết, được ghi rõ là
 * HQ-only, và **chưa bao giờ được gọi** — thứ bảo vệ thực tế chỉ là một mục
 * menu bị ẩn, mà ẩn không phải là kiểm tra.
 *
 * Rào phải chứng minh CẢ HAI CHIỀU: từ chối đúng người, và vẫn cho đúng người
 * kia vào. Một bản vá khoá tất cả mọi người cũng làm vế "từ chối" xanh.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use Carbon\CarbonImmutable;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Một user mang ĐÚNG một vai template, có đủ permission của vai đó.
 *
 * Phải đi qua `IamSeeder` chứ không `Role::create()` trần: policy hỏi
 * PERMISSION (`shop.manage`), và một vai không có permission nào sẽ bị từ chối
 * vì lý do SAI — bài test vẫn xanh mà không chứng minh được điều nó nói.
 */
function wsLogUserWithRole(string $orgId, string $roleSlug, ?string $branchId = null): User
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

    $user = User::factory()->create(['console_organization_id' => $orgId]);
    $user->assignRole($role, $orgId, $branchId);

    return $user;
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16T10:00:00Z'));

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->device = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => Str::random(64),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->requestsUrl = "/api/v1/hq/{$this->brand->slug}/workstation-log-requests";
    $this->recordsUrl = "/api/v1/hq/{$this->brand->slug}/workstation-log-records";

    $this->body = fn (array $overrides = []) => array_merge([
        'device_id' => (string) $this->device->id,
        'from' => '2026-08-16T00:00:00Z',
        'to' => '2026-08-16T06:00:00Z',
    ], $overrides);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
//  CỔNG VAI — cả hai chiều
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 shop-staff (thu ngân) KHÔNG tạo được yêu cầu và KHÔNG đọc được log', function () {
    // Đúng loại tài khoản mà #1271 để lọt: có vai trong tổ chức, qua được
    // `ResolveBrandFromSlug`, và trước khi có policy thì tới thẳng dữ liệu.
    $cashier = wsLogUserWithRole($this->orgId, 'shop-staff');

    $this->actingAs($cashier)->postJson($this->requestsUrl, ($this->body)())->assertForbidden();
    $this->actingAs($cashier)->getJson($this->requestsUrl)->assertForbidden();
    $this->actingAs($cashier)->getJson($this->recordsUrl)->assertForbidden();

    expect(WorkstationLogRequest::query()->count())->toBe(0);
});

it('#2901 staff (không có shop.manage) cũng bị chặn', function () {
    $staff = wsLogUserWithRole($this->orgId, 'staff');

    $this->actingAs($staff)->postJson($this->requestsUrl, ($this->body)())->assertForbidden();
    $this->actingAs($staff)->getJson($this->recordsUrl)->assertForbidden();
});

it('#2901 shop-manager và org-admin thì VÀO ĐƯỢC — rào khoá tất cả cũng làm vế từ chối xanh', function () {
    foreach (['shop-manager', 'org-admin', 'org-manager'] as $slug) {
        $user = wsLogUserWithRole($this->orgId, $slug);

        $this->actingAs($user)
            ->postJson($this->requestsUrl, ($this->body)())
            ->assertCreated();

        $this->actingAs($user)->getJson($this->recordsUrl)->assertOk();
    }

    expect(WorkstationLogRequest::query()->count())->toBe(3);
});

it('#2901 shop-manager của quán KHÁC không chạm được máy của quán này', function () {
    // `hasOrganizationPermission` trả lời "vai này có được điều tra không";
    // `canAccessBranch` trả lời "có được điều tra QUÁN NÀY không". Cần cả hai.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $manager = wsLogUserWithRole($this->orgId, 'shop-manager', (string) $otherBranch->id);

    $this->actingAs($manager)
        ->postJson($this->requestsUrl, ($this->body)())
        ->assertForbidden();

    expect(WorkstationLogRequest::query()->count())->toBe(0);
});

it('#2901 org-admin có all_branches_access (pivot branch_id NULL) chạm được mọi quán (#2460)', function () {
    // `branch_id IS NULL` nghĩa là MỌI chi nhánh, KHÔNG phải "không chi nhánh
    // nào" — tầng thông báo từng hiểu ngược và im lặng gửi cho 0 người.
    $admin = wsLogUserWithRole($this->orgId, 'org-admin', null);

    $this->actingAs($admin)->postJson($this->requestsUrl, ($this->body)())->assertCreated();
});

it('#2901 người của tổ chức KHÁC không thấy thiết bị này tồn tại (404, không 403)', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'is_active' => true,
    ]);

    $outsider = wsLogUserWithRole($otherOrgId, 'org-admin');

    // 404 chứ không 403: đừng để một id đoán mò xác nhận được rằng thiết bị đó
    // tồn tại ở tổ chức khác.
    $this->actingAs($outsider)
        ->postJson("/api/v1/hq/{$otherBrand->slug}/workstation-log-requests", ($this->body)())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
//  TẠO YÊU CẦU — phạm vi CỐ ĐỊNH, không có trường tự do
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 yêu cầu tạo ra ở trạng thái pending, có hạn, và số đếm bắt đầu từ 0', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $res = $this->actingAs($admin)->postJson($this->requestsUrl, ($this->body)())->assertCreated();

    $row = WorkstationLogRequest::query()->sole();

    expect($row->status)->toBe(WorkstationLogRequestStatusEnum::Pending)
        ->and($row->device_id)->toBe((string) $this->device->id)
        ->and($row->branch_id)->toBe((string) $this->branch->id)
        ->and($row->organization_id)->toBe($this->orgId)
        ->and($row->requested_by_user_id)->toBe((string) $admin->id)
        ->and($row->received_count)->toBe(0)
        ->and($row->rejected_count)->toBe(0)
        ->and($row->fulfilled_at)->toBeNull()
        // NOT NULL có chủ đích: một yêu cầu không hạn là lệnh THƯỜNG TRỰC
        // chuyển PII, ngược hẳn lý do chọn kéo-theo-yêu-cầu.
        ->and($row->expires_at)->not->toBeNull();

    expect($res->json('data.status'))->toBe('pending');
});

it('#2901 khoảng thời gian phải là RFC3339 UTC — giờ tường bị từ chối', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    // Quán VN (UTC+7) và JP (UTC+9) chạy chung một backend UTC, nên một khoảng
    // gõ theo giờ tường sẽ hỏi nhầm 7–9 tiếng (#1091) — và kết quả rỗng KHÔNG
    // tự tố cáo điều đó.
    foreach (['2026-08-16 00:00:00', '2026-08-16T00:00:00+09:00', '2026-08-16'] as $bad) {
        $this->actingAs($admin)
            ->postJson($this->requestsUrl, ($this->body)(['from' => $bad]))
            ->assertStatus(422);
    }

    expect(WorkstationLogRequest::query()->count())->toBe(0);
});

it('#2901 khoảng rỗng hoặc đảo ngược ⇒ 422', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $this->actingAs($admin)
        ->postJson($this->requestsUrl, ($this->body)(['to' => '2026-08-16T00:00:00Z']))
        ->assertStatus(422);

    $this->actingAs($admin)
        ->postJson($this->requestsUrl, ($this->body)([
            'from' => '2026-08-16T06:00:00Z',
            'to' => '2026-08-16T00:00:00Z',
        ]))
        ->assertStatus(422);
});

it('#2901 max_records vượt trần hệ thống ⇒ 422', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $ceiling = (int) config('workstation_logs.request_max_records');

    $this->actingAs($admin)
        ->postJson($this->requestsUrl, ($this->body)(['max_records' => $ceiling + 1]))
        ->assertStatus(422);
});

it('#2901 chỉ MÁY TRẠM mới có đường trả log — thiết bị loại khác ⇒ 422', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $kiosk = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => Str::random(64),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($admin)
        ->postJson($this->requestsUrl, ($this->body)(['device_id' => (string) $kiosk->id]))
        ->assertStatus(422);
});

it('#2901 KHÔNG có trường tự do nào đi tới máy trạm — key lạ bị strip, không được lưu', function () {
    // Ràng buộc chốt của thiết kế: đường nhận yêu cầu ở máy trạm chỉ ĐỌC LOG,
    // phạm vi cố định. `$request->validate()` strip mọi khoá không có rule,
    // nên bài này chốt rằng không có rule nào nhận lệnh/đường dẫn/tham số.
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $this->actingAs($admin)->postJson($this->requestsUrl, ($this->body)([
        'command' => 'cat /etc/passwd',
        'path' => '/var/log',
        'file' => 'app.db',
    ]))->assertCreated();

    $stored = WorkstationLogRequest::query()->sole()->getAttributes();

    foreach (['command', 'path', 'file'] as $key) {
        expect($stored)->not->toHaveKey($key);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
//  ĐỌC — và bẫy mẫu số bằng không
// ─────────────────────────────────────────────────────────────────────────────

it('#2901 đọc log chỉ thấy dữ liệu của TỔ CHỨC MÌNH', function () {
    $mine = WorkstationLogRecord::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
    ]);

    $theirs = WorkstationLogRecord::factory()->create([
        'organization_id' => (string) Str::uuid(),
    ]);

    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $ids = $this->actingAs($admin)->getJson($this->recordsUrl)->assertOk()->json('data.*.id');

    expect($ids)->toContain((string) $mine->id)
        ->and($ids)->not->toContain((string) $theirs->id);
});

/**
 * Ranh giới THẬT của bề mặt này: nó là bề mặt ORG-WIDE.
 *
 * Bài ngay trên chứng minh cách ly theo TỔ CHỨC. Bài này ghim thứ khác và dễ
 * hiểu nhầm hơn: một `shop-manager` gắn cứng vào MỘT quán không đọc được gì cả
 * — kể cả log của chính quán mình.
 *
 * Đó không phải lỗi, và cũng không phải do `WorkstationLogReads` chặn: route HQ
 * không đặt `branch_id` vào request attributes, nên `resolveLocalBranchId()`
 * trả `null` và `User::getRolesForContext($org, null)` chỉ nhận pivot
 * `branch_id IS NULL`. Policy 403 trước khi truy vấn nào chạy.
 *
 * Ghi lại vì hai lý do. Một: nó là hợp đồng, và nếu ngày nào đó ai muốn cho vai
 * gắn-quán vào đây thì bài này sẽ đỏ và bắt họ đọc luôn phần phạm vi ở
 * `WorkstationLogReads`. Hai: cùng cơ chế này đang làm bài "shop-manager của
 * quán KHÁC không chạm được máy của quán này" ở trên xanh — tức vế
 * `canAccessBranch()` mà bình luận ở đó nêu **chưa bao giờ được chạy tới**.
 */
it('#2901 vai gắn cứng MỘT quán không vào được bề mặt HQ — kể cả log quán mình', function () {
    WorkstationLogRecord::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
    ]);

    $manager = wsLogUserWithRole($this->orgId, 'shop-manager', (string) $this->branch->id);

    $this->actingAs($manager)->getJson($this->recordsUrl)->assertForbidden();
    $this->actingAs($manager)->getJson($this->requestsUrl)->assertForbidden();
});

/**
 * Chiều IM của cùng một rào.
 *
 * `WorkstationLogReads` thêm `whereIn('branch_id', branches($user))` vào cả hai
 * đường đọc. Một bản vá siết quá tay cũng làm mọi bài "từ chối" ở trên xanh,
 * nên phải có bài chứng minh org-admin vẫn thấy ĐỦ — gồm cả quán mà họ không có
 * pivot riêng, đúng ruling #2460 (`branch_id IS NULL` = MỌI chi nhánh).
 */
it('#2901 org-admin all_branches_access vẫn đọc được log của MỌI quán (#2460)', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $here = WorkstationLogRecord::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
    ]);

    $there = WorkstationLogRecord::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);

    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    $ids = $this->actingAs($admin)->getJson($this->recordsUrl)->assertOk()->json('data.*.id');

    expect($ids)->toContain((string) $here->id)
        ->and($ids)->toContain((string) $there->id);

    $hereReq = WorkstationLogRequest::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
    ]);

    $thereReq = WorkstationLogRequest::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);

    $reqIds = $this->actingAs($admin)->getJson($this->requestsUrl)->assertOk()->json('data.*.id');

    expect($reqIds)->toContain((string) $hereReq->id)
        ->and($reqIds)->toContain((string) $thereReq->id);
});

it('#2901 danh sách yêu cầu phân biệt fulfilled-0-dòng với expired — không gộp thành "không có sự cố"', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    WorkstationLogRequest::factory()->fulfilled(0)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
    ]);

    WorkstationLogRequest::factory()->expired()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
    ]);

    $rows = collect($this->actingAs($admin)->getJson($this->requestsUrl)->assertOk()->json('data'));

    $fulfilled = $rows->firstWhere('status', 'fulfilled');
    $expired = $rows->firstWhere('status', 'expired');

    // `fulfilled` + 0 dòng = một khẳng định thật ("khoảng đó không có gì qua
    // allowlist"), và `fulfilled_at` là dấu chứng minh có người trả lời.
    expect($fulfilled['received_count'])->toBe(0)
        ->and($fulfilled['fulfilled_at'])->not->toBeNull()
        // `expired` KHÔNG khẳng định gì: máy tắt / mất mạng / bản cũ chưa biết
        // endpoint này. Máy trạm bản cũ không gửi gì ⇒ Cloud không được hiểu
        // "không có log" thành "không có sự cố".
        ->and($expired['fulfilled_at'])->toBeNull();
});

it('#2901 rejected_count hiện ra ở HQ — không có nó thì lỗ hổng allowlist vô hình', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    WorkstationLogRequest::factory()->fulfilled(5)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'device_id' => $this->device->id,
        'rejected_count' => 12,
    ]);

    $row = $this->actingAs($admin)->getJson($this->requestsUrl)->assertOk()->json('data.0');

    expect($row['rejected_count'])->toBe(12);
});

it('#2901 log trả về theo thứ tự thời gian TĂNG dần', function () {
    $admin = wsLogUserWithRole($this->orgId, 'org-admin');

    foreach (['2026-08-16 03:00:00', '2026-08-16 01:00:00', '2026-08-16 02:00:00'] as $i => $at) {
        WorkstationLogRecord::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'device_id' => $this->device->id,
            'local_id' => $i + 1,
            'logged_at' => $at,
        ]);
    }

    $times = $this->actingAs($admin)->getJson($this->recordsUrl)->assertOk()->json('data.*.logged_at');

    // Đọc ngược một chuỗi nhân quả là cách nhanh nhất để kết luận nhầm về
    // nguyên nhân.
    expect($times)->toBe([
        '2026-08-16T01:00:00Z',
        '2026-08-16T02:00:00Z',
        '2026-08-16T03:00:00Z',
    ]);
});

it('#2901 KHÔNG có đường xoá log qua HTTP — dấu vết không được biến mất bằng tay', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_contains($r->uri(), 'workstation-log-'))
        ->flatMap(fn ($r): array => $r->methods())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($methods)->toBe(['GET', 'HEAD', 'POST']);
});
