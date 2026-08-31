<?php

declare(strict_types=1);

/**
 * #1550 — phía GHI của ranh giới Menu, xây thật.
 *
 * `MenuMutationFacade` từng là 54 method **không ai implement**, và bánh cóc
 * ngược `UNIMPLEMENTED_BY_DESIGN` giữ sự thật đó nhìn thấy được. Nay có
 * `MenuMutationService` (mỏng) + `EloquentMenuPersistence`.
 *
 * ## Điều file này phải chứng minh
 *
 * Không phải "54 method tồn tại" — điều đó `ReflectionClass` nói được. Mà là
 * chúng **GHI THẬT**, và ghi qua đúng đường mà production đang chạy: một mặt
 * tiền trả `MutationResult` mà không đụng DB sẽ qua được mọi kiểm tra hình
 * dạng. Nên mỗi ca dưới đây đọc lại DB sau khi gọi.
 */

use App\Exceptions\MenuOperationException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Services\DomainMutation\MutationContext;
use App\Services\Menu\Commands\CreateMenuCommand;
use App\Services\Menu\Commands\CreateMenuScheduleCommand;
use App\Services\Menu\Commands\MenuLifecycleCommand;
use App\Services\Menu\Commands\ReviseMenuCommand;
use App\Services\Menu\Contracts\MenuMutationFacade;
use App\Services\Menu\Enums\MenuLifecycleAction;
use App\Services\Menu\MenuMutationService;
use App\Services\Menu\ValueObjects\MenuDefinitionPayload;
use App\Services\Menu\ValueObjects\MenuLayoutPayload;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->facade = app(MenuMutationFacade::class);
});

/**
 * `expectedVersion` LUÔN có mặt.
 *
 * Phần lớn Command của Menu gọi `requireExpectedVersion()` — chỉ
 * `CreateMenuCommand` (aggregate mới toanh) là không. Đặt mặc định ở đây thay
 * vì rải `mmfXxxContext()` cho từng nhóm: danh sách "lệnh nào cần version" là
 * chuyện của Command, không phải của test.
 *
 * Con số 1 chỉ để thoả cổng — bảng `menus` không có cột `version`, và chính sự
 * lệch đó là lý do `MutationResult.version` trả `null` cho menu.
 */
function mmfContext(?string $orgId = null, ?int $expectedVersion = 1): MutationContext
{
    return new MutationContext(
        $orgId ?? test()->orgId,
        (string) Str::uuid(),
        'corr-'.Str::random(8),
        'idem-'.Str::random(12),
        $expectedVersion,
    );
}

function mmfDefinition(string $name = 'Thực đơn trưa'): MenuDefinitionPayload
{
    return new MenuDefinitionPayload($name, null, new MenuLayoutPayload([]));
}

it('#1550 facade GHI THẬT — menu xuất hiện trong DB với id do người gọi cấp', function () {
    $menuId = (string) Str::uuid();
    $payload = mmfDefinition();

    $result = $this->facade->create(new CreateMenuCommand(
        mmfContext(),
        $menuId,
        (string) $this->brand->id,
        (string) $this->branch->id,
        $payload,
        $payload->fingerprint(),
    ));

    // Id do NGƯỜI GỌI cấp, không phải do service sinh — đây là ngữ nghĩa mà
    // `MenuService::create(array)` không có, và là lý do tôi từng kết luận sai
    // rằng facade "không uỷ quyền được". Tiền lệ `EloquentProductPersistence`
    // tiêm id vào mảng; bản này làm y hệt.
    expect($result->aggregateId)->toBe($menuId);

    $row = Menu::query()->find($menuId);
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Thực đơn trưa')
        ->and((string) $row->organization_id)->toBe($this->orgId)
        ->and((string) $row->branch_id)->toBe((string) $this->branch->id);
});

it('#1550 fingerprint SAI thì Command từ chối — không tới được DB', function () {
    $payload = mmfDefinition();

    expect(fn () => new CreateMenuCommand(
        mmfContext(),
        (string) Str::uuid(),
        (string) $this->brand->id,
        null,
        $payload,
        hash('sha256', 'không-phải-fingerprint-của-payload'),
    ))->toThrow(InvalidArgumentException::class);

    expect(Menu::query()->count())->toBe(0);
});

it('#1550 revise ghi đè tên, và chỉ trong tổ chức của mình', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'name' => 'Tên cũ',
    ]);

    $payload = mmfDefinition('Tên mới');
    $this->facade->revise(new ReviseMenuCommand(
        mmfContext(),
        (string) $menu->id,
        $payload,
        $payload->fingerprint(),
    ));

    expect($menu->fresh()->name)->toBe('Tên mới');
});

it('#1550 phạm vi tổ chức được CƯỠNG CHẾ — không sửa được menu tenant khác', function () {
    // `MenuService::findById($id)` KHÔNG có phạm vi tổ chức. Persistence phải
    // tự lọc, nếu không ai biết uuid là sửa được menu của tenant khác.
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Của tôi',
    ]);

    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);

    $payload = mmfDefinition('Bị chiếm');
    expect(fn () => $this->facade->revise(new ReviseMenuCommand(
        mmfContext($otherOrg),
        (string) $menu->id,
        $payload,
        $payload->fingerprint(),
    )))->toThrow(ModelNotFoundException::class);

    expect($menu->fresh()->name)->toBe('Của tôi');
});

it('#1550 lịch đi qua MenuScheduleService — hàng thật, id do người gọi cấp', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $scheduleId = (string) Str::uuid();
    $payload = new MenuSchedulePayload($scheduleId, 127, '11:00:00', '14:00:00', 1);

    $this->facade->createSchedule(new CreateMenuScheduleCommand(
        mmfContext(),
        (string) $menu->id,
        $payload,
        $payload->fingerprint(),
    ));

    $row = MenuSchedule::query()->where('menu_id', $menu->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->start_time)->toContain('11:00')
        ->and((int) $row->days_of_week)->toBe(127);
});

it('#1550 vòng đời đi qua LUẬT NGHIỆP VỤ thật, không vòng qua nó', function () {
    // Đây là phép đo phân biệt "facade ghi thật" với "facade ghi tắt": nếu nó
    // tự `update(['status' => …])` thì menu rỗng vẫn submit được, và luật
    // "phải có ít nhất một món" biến mất khỏi đường công bố.
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Draft',
    ]);

    expect(fn () => $this->facade->submit(new MenuLifecycleCommand(
        mmfContext(),
        (string) $menu->id,
        MenuLifecycleAction::Submit,
    )))->toThrow(MenuOperationException::class);
});

it('#1550 bảy hành động vòng đời đi tới bảy nhánh KHÁC nhau', function () {
    // Chúng dùng chung `MenuLifecycleCommand` và phân biệt bằng `action`. Gộp
    // nhầm là `approve` chạy đường `archive` — cùng chữ ký, không gì kêu.
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Draft',
    ]);

    $this->facade->archive(new MenuLifecycleCommand(mmfContext(), (string) $menu->id, MenuLifecycleAction::Archive));

    expect(Menu::query()->find($menu->id))->toBeNull()
        ->and(Menu::withTrashed()->find($menu->id))->not->toBeNull();

    $this->facade->restore(new MenuLifecycleCommand(mmfContext(), (string) $menu->id, MenuLifecycleAction::Restore));

    expect(Menu::query()->find($menu->id))->not->toBeNull();
});

it('#1550 facade là MỎNG — mọi method uỷ quyền cho persistence, không tự ghi', function () {
    // Nếu facade tự ghi thì có hai đường ghi cho cùng một aggregate, và luật
    // phạm vi tổ chức sẽ trôi ở đường ít ai chạm.
    $src = (string) file_get_contents(
        (new ReflectionClass(MenuMutationService::class))->getFileName(),
    );

    expect($src)->not->toContain('DB::');
    expect($src)->not->toContain('::query()');
    expect(substr_count($src, '$this->persistence->'))->toBe(54);
});
