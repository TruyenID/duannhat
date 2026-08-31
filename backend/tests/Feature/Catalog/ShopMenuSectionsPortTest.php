<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuMenuSection;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Services\Menu\Contracts\ShopMenuSections;
use Illuminate\Support\Str;

/**
 * #1622 — cổng Catalog công bố "mục menu của MỘT cửa hàng" cho báo cáo doanh thu POS.
 *
 * Ba hành vi dưới đây từng là comment giải thích dài trong `PosRevenueService`;
 * giờ chúng là assertion. Cả ba hỏng theo kiểu **im lặng** — báo cáo vẫn ra số,
 * chỉ là sai tập.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->sections = app(ShopMenuSections::class);

    $this->menu = function (?string $branchId, ?string $brandId = null): Menu {
        return Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $brandId ?? $this->brand->id,
            'branch_id' => $branchId,
            'status' => 'Active',
        ]);
    };

    $this->sectionOn = function (Menu $menu, string $name): MenuSection {
        $section = MenuSection::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $menu->brand_id,
            'name' => $name,
        ]);
        MenuMenuSection::factory()->create([
            'menu_id' => $menu->id,
            'menu_section_id' => $section->id,
        ]);

        return $section;
    };
});

it('có binding thật, không phải interface rỗng', function () {
    expect($this->sections)->toBeInstanceOf(ShopMenuSections::class);
});

it('gom CẢ menu ghim chi nhánh LẪN menu chung của thương hiệu', function () {
    $pinned = ($this->menu)((string) $this->branch->id);
    $brandWide = ($this->menu)(null);

    $ids = $this->sections->menuIdsForShop((string) $this->branch->id, (string) $this->brand->id);

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain((string) $pinned->id)
        ->and($ids)->toContain((string) $brandWide->id);
});

/**
 * Dữ liệu seed vẫn có ca hai thương hiệu cùng ghim menu vào một `branch_id` vật
 * lý. Bỏ lọc `brand_id` thì dropdown của cửa hàng mọc thêm mục của thương hiệu
 * khác — comment trong bản cũ ghi đúng ca này.
 */
it('KHÔNG nhặt menu của thương hiệu khác dù cùng branch_id', function () {
    ($this->menu)((string) $this->branch->id);
    $foreign = ($this->menu)((string) $this->branch->id, (string) $this->otherBrand->id);

    $ids = $this->sections->menuIdsForShop((string) $this->branch->id, (string) $this->brand->id);

    expect($ids)->not->toContain((string) $foreign->id);
});

it('bỏ qua menu đã xoá mềm', function () {
    $live = ($this->menu)((string) $this->branch->id);
    $dead = ($this->menu)((string) $this->branch->id);
    $dead->delete();

    $ids = $this->sections->menuIdsForShop((string) $this->branch->id, (string) $this->brand->id);

    expect($ids)->toBe([(string) $live->id]);
});

/**
 * Menu chung và menu riêng thường đặt TRÙNG TÊN mục nhưng khác id. Dropdown gộp
 * theo tên; không gộp thì người dùng thấy "Main" hai lần.
 */
it('dropdown GỘP mục trùng tên', function () {
    $pinned = ($this->menu)((string) $this->branch->id);
    $brandWide = ($this->menu)(null);
    ($this->sectionOn)($pinned, 'Main');
    ($this->sectionOn)($brandWide, 'Main');
    ($this->sectionOn)($pinned, 'Drinks');

    $rows = $this->sections->sectionsForShop((string) $this->branch->id, (string) $this->brand->id);

    expect(array_column($rows, 'name'))->toBe(['Drinks', 'Main']);
});

/**
 * Chiều NGƯỢC lại của việc gộp: người dùng chọn một id đại diện, bộ lọc phải nở
 * ra mọi id cùng tên. Không nở ra ⇒ báo cáo **thiếu món** gắn vào mục cùng tên
 * của menu kia, và tổng vẫn ra một con số trông hợp lý.
 */
it('bộ lọc NỞ RA mọi mục cùng tên trong menu của cửa hàng', function () {
    $pinned = ($this->menu)((string) $this->branch->id);
    $brandWide = ($this->menu)(null);
    $a = ($this->sectionOn)($pinned, 'Main');
    $b = ($this->sectionOn)($brandWide, 'Main');

    $menuIds = $this->sections->menuIdsForShop((string) $this->branch->id, (string) $this->brand->id);
    $ids = $this->sections->sectionIdsSharingName((string) $a->id, $menuIds);

    sort($ids);
    $expected = [(string) $a->id, (string) $b->id];
    sort($expected);
    expect($ids)->toBe($expected);
});

it('không có menu nào → bộ lọc thu hẹp về đúng mục đã chọn, KHÔNG mở thành tất cả', function () {
    $section = ($this->sectionOn)(($this->menu)((string) $this->branch->id), 'Main');

    expect($this->sections->sectionIdsSharingName((string) $section->id, []))
        ->toBe([(string) $section->id]);
});

it('id mục không tồn tại → sectionName null, và bộ lọc giữ nguyên id đã chọn', function () {
    $ghost = (string) Str::uuid();
    $menuIds = $this->sections->menuIdsForShop((string) $this->branch->id, (string) $this->brand->id);

    expect($this->sections->sectionName($ghost))->toBeNull()
        ->and($this->sections->sectionIdsSharingName($ghost, $menuIds))->toBe([$ghost]);
});
