<?php

declare(strict_types=1);

/**
 * #1550 — phía ĐỌC của ranh giới Menu, xây thật.
 *
 * `MenuQueryPort` + `MenuSnapshot` từng là interface **không ai implement,
 * không ai bind**: `app()->make()` ném. Đúng hình dạng "đường ống rỗng" mà
 * #1544 mô tả cho `OrderQueryPort` — và trớ trêu là cổng canh chuyện đó
 * (`CanonicalPortsAreBindableTest` P2) lại không liệt kê tên nó.
 *
 * Phía GHI (54 method của `MenuMutationFacade`) vẫn chưa xây, và bánh cóc ngược
 * `UNIMPLEMENTED_BY_DESIGN` giữ nợ đó nhìn thấy được.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Services\Menu\Contracts\MenuQueryPort;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    // Đóng băng đồng hồ: `findEffectiveForBranch` đi qua `getCurrentMenu`, vốn
    // đọc GIỜ CHI NHÁNH (#1091). Không đóng băng thì test chỉ xanh vào một số
    // giờ trong ngày — đúng lỗi #1732 vừa phải sửa ở file khác.
    Carbon::setTestNow('2026-08-04 03:00:00');

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);

    $this->port = app(MenuQueryPort::class);
});

function mqpMenu(array $attrs = []): Menu
{
    return Menu::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'status' => 'Active',
    ], $attrs));
}

it('#1550 cổng đọc RESOLVE được — không còn là interface rỗng', function () {
    expect($this->port)->toBeInstanceOf(MenuQueryPort::class);
});

it('#1550 findById trả ảnh chụp mang đúng bốn trường', function () {
    $menu = mqpMenu();

    $snap = $this->port->findById($this->orgId, (string) $menu->id);

    expect($snap)->not->toBeNull()
        ->and($snap->aggregateId())->toBe((string) $menu->id)
        ->and($snap->organizationId())->toBe($this->orgId)
        ->and($snap->brandId())->toBe((string) $this->brand->id)
        ->and($snap->branchId())->toBe((string) $this->branch->id)
        ->and($snap->status())->toBe('Active');
});

it('#1550 findById CÓ phạm vi tổ chức — không đọc sang tenant khác', function () {
    // Đây là tính chất, không phải trang trí: `MenuService::findById($id)` KHÔNG
    // nhận organizationId, nên một cổng chép nguyên nó sẽ trả menu của tenant
    // khác cho ai biết uuid.
    $menu = mqpMenu();
    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);

    expect($this->port->findById($otherOrg, (string) $menu->id))->toBeNull();
});

it('#1550 findById trả null khi không có, KHÔNG ném', function () {
    // `MenuService::findById` ném `ModelNotFoundException`. Cổng trả null vì
    // chữ ký nói `?MenuSnapshot` — đổi một trong hai là đổi hợp đồng lỗi của
    // mọi chỗ gọi ngoài module.
    expect($this->port->findById($this->orgId, (string) Str::uuid()))->toBeNull();
});

it('#1550 findEffectiveForBranch trả thực đơn ĐANG PHÁT', function () {
    $menu = mqpMenu();

    $snap = $this->port->findEffectiveForBranch($this->orgId, (string) $this->branch->id);

    expect($snap)->not->toBeNull()
        ->and($snap->aggregateId())->toBe((string) $menu->id);
});

it('#1550 thực đơn NHÁP không phải thực đơn đang phát', function () {
    mqpMenu(['status' => 'Draft']);

    expect($this->port->findEffectiveForBranch($this->orgId, (string) $this->branch->id))->toBeNull();
});

it('#1550 ngoài khung giờ thì KHÔNG phát — luật lịch không bị đánh rơi', function () {
    // Ca này là lý do cổng UỶ QUYỀN cho `getCurrentMenu()` thay vì chép truy
    // vấn: bản chép sẽ đánh rơi từng luật một, và luật lịch là cái rơi trước.
    $menu = mqpMenu();
    MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'start_time' => '20:00:00',
        'end_time' => '23:00:00',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 1,
    ]);

    // 03:00 UTC = 12:00 giờ Tokyo — ngoài cửa sổ 20:00–23:00.
    expect($this->port->findEffectiveForBranch($this->orgId, (string) $this->branch->id))->toBeNull();
});

it('#1550 chi nhánh không có thực đơn nào ⇒ null, không phải lỗi', function () {
    expect($this->port->findEffectiveForBranch($this->orgId, (string) $this->branch->id))->toBeNull();
});

it('#1550 version() là 0 và đó là SỰ THẬT — bảng menus không có cột đó', function () {
    // Trả một số giả tăng dần sẽ mời người sau xây kiểm tra xung đột lạc quan
    // trên một con số không ai ghi.
    expect(Schema::hasColumn('menus', 'version'))->toBeFalse();
    expect($this->port->findById($this->orgId, (string) mqpMenu()->id)->version())->toBe(0);
});
