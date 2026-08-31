<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Models\Branch;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductSku;
use App\Models\FloatingSectionSchedule;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSchedule;
use App\Models\MenuSection;
use App\Services\DomainMutation\MutationResult;
use App\Services\Menu\Commands as C;
use App\Services\Menu\Contracts\MenuPersistencePort;
use App\Services\Menu\Enums\MenuLayoutMutation;
use App\Services\Menu\Enums\MenuLifecycleAction;
use App\Services\Menu\ValueObjects\MenuItemPayload;
use App\Services\Menu\ValueObjects\MenuSchedulePayload;
use App\Services\Menu\ValueObjects\MenuSectionPayload;
use App\Services\Product\BranchMenuScheduleService;
use App\Services\Product\FloatingSectionScheduleService;
use App\Services\Product\FloatingSectionService;
use App\Services\Product\MenuScheduleService;
use App\Services\Product\MenuSectionService;
use App\Services\Product\MenuService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phía GHI của ranh giới Menu (#1550).
 *
 * ## Uỷ quyền, KHÔNG viết lại đường ghi
 *
 * Tôi từng kết luận ngược lại và đã sai: `CreateMenuCommand` mang `menuId` do
 * NGƯỜI GỌI cấp còn `MenuService::create(array)` tự sinh id, nên tôi đọc thành
 * "không uỷ quyền được". Tiền lệ đã cài nói khác —
 * `EloquentProductPersistence::insertProductType()` gọi thẳng service cũ và
 * **tiêm id vào mảng**:
 *
 *     $this->productTypeService->create(['id' => $command->productTypeId, ...]);
 *
 * Nên class này làm y hệt. Hệ quả quan trọng: mọi thứ đường ghi menu đang gánh
 * vẫn chạy nguyên — chuẩn hoá bản dịch, priority mặc định, ba tầng thuế
 * (#1218/#1226/#1227), đánh dấu catalog revision (#1661), ghi pivot section qua
 * `MenuSectionPivotWriter`, cascade lịch. Viết lại chúng ở đây là dựng bộ thứ
 * hai cho từng luật đó.
 *
 * ## Phạm vi tổ chức là BẮT BUỘC ở mọi lần tra
 *
 * Command mang `context->organizationId`; mọi `scoped*()` dưới đây lọc theo nó.
 * `MenuService::findById($id)` KHÔNG có phạm vi — dùng thẳng nó là cho ai biết
 * uuid sửa menu của tenant khác.
 *
 * ## `version` luôn `null`
 *
 * Bảng `menus` không có cột `version` (kiểm `create_menus_table`). `MutationResult`
 * cho phép null, và trả một số giả tăng dần sẽ mời người sau xây kiểm tra xung
 * đột lạc quan trên một con số không ai ghi. Cùng lý do đã ghi ở
 * {@see MenuAggregateSnapshot}.
 */
final class EloquentMenuPersistence implements MenuPersistencePort
{
    public function __construct(
        private readonly MenuService $menus,
        private readonly MenuSectionService $sections,
        private readonly MenuScheduleService $schedules,
        private readonly FloatingSectionService $floating,
        private readonly FloatingSectionScheduleService $floatingSchedules,
        private readonly BranchMenuScheduleService $branchSchedules,
    ) {}

    // =====================================================================
    //  Tra cứu CÓ PHẠM VI — xem docblock class
    // =====================================================================

    private function scopedMenu(string $menuId, ?string $organizationId): Menu
    {
        return Menu::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereKey($menuId)
            ->firstOrFail();
    }

    /** Như {@see scopedMenu} nhưng NHÌN THẤY hàng đã xoá mềm — chỉ cho đường khôi phục. */
    private function trashedMenu(string $menuId, ?string $organizationId): Menu
    {
        return Menu::withTrashed()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereKey($menuId)
            ->firstOrFail();
    }

    private function scopedSection(string $sectionId, ?string $organizationId): MenuSection
    {
        return MenuSection::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereKey($sectionId)
            ->firstOrFail();
    }

    private function scopedFloating(string $sectionId, ?string $organizationId): FloatingSection
    {
        return FloatingSection::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereKey($sectionId)
            ->firstOrFail();
    }

    /**
     * Dòng đơn của menu, ràng theo MENU chứ không chỉ theo id.
     *
     * Không ràng thì một `menuProductId` của menu khác vẫn sửa được miễn nó
     * cùng tổ chức — command đã nêu cả hai id, nên bỏ qua cái thứ nhất là vứt
     * đi thông tin mà chính chỗ gọi đã cung cấp.
     */
    private function scopedMenuProduct(string $menuId, string $menuProductId, ?string $organizationId): MenuProduct
    {
        $menu = $this->scopedMenu($menuId, $organizationId);

        return MenuProduct::query()
            ->where('menu_id', $menu->id)
            ->whereKey($menuProductId)
            ->firstOrFail();
    }

    private function scopedMenuSku(string $menuId, string $menuProductSkuId, ?string $organizationId): MenuProductSku
    {
        $menu = $this->scopedMenu($menuId, $organizationId);

        return MenuProductSku::query()
            ->whereKey($menuProductSkuId)
            ->whereIn('menu_product_id', MenuProduct::query()->where('menu_id', $menu->id)->select('id'))
            ->firstOrFail();
    }

    private function scopedMenuSchedule(string $menuId, string $scheduleId, ?string $organizationId): MenuSchedule
    {
        $menu = $this->scopedMenu($menuId, $organizationId);

        return MenuSchedule::query()->where('menu_id', $menu->id)->whereKey($scheduleId)->firstOrFail();
    }

    private function ok(string $aggregateId): MutationResult
    {
        return new MutationResult($aggregateId, null, true);
    }

    /**
     * Chạy một lượt ghi mà id do NGƯỜI GỌI cấp thật sự được dùng.
     *
     * Không có nó thì id bị bỏ qua ÂM THẦM: `'id'` không nằm trong `$fillable`
     * của `Menu`/`MenuSection`/`FloatingSection`, nên `Model::create(['id' =>
     * …])` sinh một uuid khác và `MutationResult` trả về một id mà người gọi
     * chưa từng thấy. Đo được:
     *
     *     muốn    23c6e26f-07e5-4dfb-a98c-73512ebd6449
     *     thực tế 019fca95-8865-72c1-9398-43f231280bd1
     *
     * Điều đó phá đúng thứ id-do-người-gọi-cấp sinh ra để làm: chống trùng.
     * Gửi lại cùng một lệnh sẽ tạo hàng thứ hai thay vì trúng hàng cũ.
     *
     * `unguarded()` mở mass-assignment CHỈ trong lượt gọi này rồi trả lại
     * nguyên trạng — không nới `$fillable` của model, vì nới là mở cho mọi
     * đường ghi khác trong toàn hệ thống.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    private function withSuppliedId(\Closure $write): mixed
    {
        return Model::unguarded($write);
    }

    /** Payload lịch → mảng mà `MenuScheduleService` nhận. */
    private function schedulePayload(MenuSchedulePayload $p): array
    {
        return array_filter([
            'id' => $p->scheduleId,
            'days_of_week' => $p->daysOfWeek,
            'start_time' => $p->startTime,
            'end_time' => $p->endTime,
            'priority' => $p->priority,
            'start_date' => $p->startDate,
            'end_date' => $p->endDate,
            'is_active' => $p->active,
            'master_schedule_id' => $p->masterScheduleId,
        ], static fn ($v) => $v !== null);
    }

    /** Payload một dòng menu → mảng cho `syncLayout`. */
    private function itemPayload(MenuItemPayload $p): array
    {
        return array_filter([
            'product_id' => $p->productId,
            'product_sku_id' => $p->skuId,
            'display_order' => $p->position,
            'tax_type_id' => $p->taxTypeId,
            'is_active' => $p->active,
            'master_menu_product_id' => $p->masterMenuProductId,
        ], static fn ($v) => $v !== null);
    }

    // =====================================================================
    //  Menu — vòng đời và định nghĩa
    // =====================================================================

    public function insertMenu(C\CreateMenuCommand $command): MutationResult
    {
        $p = $command->payload;

        $menu = $this->withSuppliedId(fn () => $this->menus->create(array_filter([
            'id' => $command->menuId,
            'organization_id' => $command->context->organizationId,
            'brand_id' => $command->brandId,
            'branch_id' => $command->branchId,
            'name' => $p->name,
            'description' => $p->description,
            'is_master' => $p->master,
            'valid_from' => $p->validFrom,
            'valid_to' => $p->validTo,
            'priority' => $p->priority,
            'cart_timeout_minutes' => $p->cartTimeoutMinutes,
            'service_type' => $p->serviceType?->value,
            'master_menu_id' => $p->masterMenuId,
        ], static fn ($v) => $v !== null)));

        return $this->ok((string) $menu->id);
    }

    public function applyRevision(C\ReviseMenuCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $p = $command->payload;

        $this->menus->update($menu, array_filter([
            'name' => $p->name,
            'description' => $p->description,
            'valid_from' => $p->validFrom,
            'valid_to' => $p->validTo,
            'priority' => $p->priority,
            'cart_timeout_minutes' => $p->cartTimeoutMinutes,
            'service_type' => $p->serviceType?->value,
        ], static fn ($v) => $v !== null));

        return $this->ok((string) $menu->id);
    }

    /**
     * Bảy hành động vòng đời đi chung một cửa, và enum là thứ phân biệt.
     *
     * `MenuLifecycleCommand` mang `action`; facade có bảy method riêng nhưng
     * cùng trỏ về đây. Gộp ở tầng persistence chứ không ở facade: mỗi hành động
     * có tên riêng ở API công bố (đọc được), còn chỗ ghi thì chỉ có một chỗ để
     * quên cập nhật.
     */
    public function markRestored(C\MenuLifecycleCommand $command): MutationResult
    {
        // `Restore` là hành động DUY NHẤT tìm hàng đã xoá mềm — mọi hành động
        // khác chạy trên menu còn sống. Không mở `withTrashed()` ở đây thì khôi
        // phục ném `ModelNotFoundException` cho một menu đang nằm trong thùng
        // rác, tức đường khôi phục không dùng được. Test bắt được đúng ca này.
        $menu = $command->action === MenuLifecycleAction::Restore
            ? $this->trashedMenu($command->menuId, $command->context->organizationId)
            : $this->scopedMenu($command->menuId, $command->context->organizationId);
        $actorId = $command->context->actorId;

        match ($command->action) {
            MenuLifecycleAction::Submit => $this->menus->submit($menu),
            MenuLifecycleAction::Approve => $this->menus->approve($menu, (string) $actorId),
            MenuLifecycleAction::Reject => $this->menus->reject($menu, (string) $actorId, (string) $command->reason),
            MenuLifecycleAction::Activate => $this->menus->activate($menu),
            MenuLifecycleAction::Deactivate => $this->menus->deactivate($menu),
            MenuLifecycleAction::Archive => $this->menus->delete($menu),
            MenuLifecycleAction::Restore => $this->menus->restore($menu),
        };

        return $this->ok((string) $menu->id);
    }

    public function cloneToBranch(C\CloneMenuToBranchCommand $command): MutationResult
    {
        $master = $this->scopedMenu($command->sourceMenuId, $command->context->organizationId);
        $clone = $this->withSuppliedId(fn () => $this->menus->cloneToBranch($master, $command->branchId, ['id' => $command->newMenuId]));

        return $this->ok((string) $clone->id);
    }

    public function duplicateStandalone(C\DuplicateStandaloneMenuCommand $command): MutationResult
    {
        $source = $this->scopedMenu($command->sourceMenuId, $command->context->organizationId);
        $copy = $this->withSuppliedId(fn () => $this->menus->duplicate($source, ['id' => $command->newMenuId]));

        return $this->ok((string) $copy->id);
    }

    public function syncFromMaster(C\SyncMenuFromMasterCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $this->menus->syncFromMaster($menu);

        return $this->ok((string) $menu->id);
    }

    // =====================================================================
    //  Bố cục: section, dòng menu, SKU
    // =====================================================================

    public function insertSection(C\CreateMenuSectionCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $p = $command->payload;

        $section = $this->withSuppliedId(fn () => $this->sections->create([
            'id' => $p->sectionId,
            'organization_id' => $menu->organization_id,
            'brand_id' => $menu->brand_id,
            'name' => $p->name,
        ]));

        return $this->ok((string) $section->id);
    }

    public function reviseSection(C\ReviseMenuSectionCommand $command): MutationResult
    {
        $this->scopedMenu($command->menuId, $command->context->organizationId);
        $section = $this->scopedSection($command->payload->sectionId, $command->context->organizationId);
        $this->sections->update($section, ['name' => $command->payload->name]);

        return $this->ok((string) $section->id);
    }

    public function removeSection(C\RemoveMenuSectionCommand $command): MutationResult
    {
        $this->scopedMenu($command->menuId, $command->context->organizationId);
        $section = $this->scopedSection($command->sectionId, $command->context->organizationId);
        $this->sections->delete($section);

        return $this->ok((string) $section->id);
    }

    /**
     * Bốn động từ sắp xếp đi chung một cửa, phân biệt bằng `operation`.
     *
     * `reorderSections` chạm PIVOT (`menu_menu_sections`), `reorderProducts` /
     * `reorderLayout` / `replaceLayout` chạm dòng menu — nên đây KHÔNG gộp
     * chúng thành một lời gọi, chỉ gộp cửa vào.
     */
    public function replaceLayout(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $sections = $command->payload->sections;

        DB::transaction(function () use ($command, $menu, $sections): void {
            match ($command->operation) {
                MenuLayoutMutation::ReorderSections => $this->menus->syncSections($menu, array_map(
                    static fn ($s, $i) => ['id' => $s->sectionId, 'display_order' => $s->position ?: $i + 1],
                    $sections,
                    array_keys($sections),
                )),
                MenuLayoutMutation::ReorderProducts, MenuLayoutMutation::ReorderLayout, MenuLayoutMutation::ReplaceLayout => $this->menus->syncLayout(
                    $menu,
                    $this->flattenLayoutItems($sections),
                ),
            };
        });

        return $this->ok((string) $menu->id);
    }

    /** @param  list<MenuSectionPayload>  $sections */
    private function flattenLayoutItems(array $sections): array
    {
        $items = [];
        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                $items[] = ['menu_section_id' => $section->sectionId] + $this->itemPayload($item);
            }
        }

        return $items;
    }

    public function placeProduct(C\PlaceMenuProductCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $created = $this->menus->addProducts($menu, [$command->payload->productId], $command->sectionId);

        $id = is_iterable($created) ? (string) (collect($created)->first()->id ?? $menu->id) : (string) $menu->id;

        return $this->ok($id);
    }

    public function removeProduct(C\RemoveMenuProductCommand $command): MutationResult
    {
        $line = $this->scopedMenuProduct($command->menuId, $command->menuProductId, $command->context->organizationId);
        $this->menus->removeProduct($line);

        return $this->ok((string) $line->id);
    }

    public function toggleProduct(C\ToggleMenuProductCommand $command): MutationResult
    {
        $line = $this->scopedMenuProduct($command->menuId, $command->menuProductId, $command->context->organizationId);

        // `toggleProduct()` LẬT trạng thái, còn command nói rõ muốn bật hay tắt.
        // Chỉ gọi khi hai bên khác nhau — nếu không, một lệnh "bật" trên dòng
        // đang bật sẽ tắt nó.
        if ((bool) $line->is_active !== $command->active) {
            $this->menus->toggleProduct($line);
        }

        return $this->ok((string) $line->id);
    }

    public function toggleSku(C\ToggleMenuSkuCommand $command): MutationResult
    {
        $sku = $this->scopedMenuSku($command->menuId, $command->menuProductSkuId, $command->context->organizationId);

        if ((bool) $sku->is_active !== $command->active) {
            $sku->update(['is_active' => $command->active]);
        }

        return $this->ok((string) $sku->id);
    }

    public function resetSkuPrice(C\ResetMenuSkuPriceCommand $command): MutationResult
    {
        $sku = $this->scopedMenuSku($command->menuId, $command->menuProductSkuId, $command->context->organizationId);
        $this->menus->resetSkuPrice($sku);

        return $this->ok((string) $sku->id);
    }

    public function syncToppings(C\SyncMenuToppingsCommand $command): MutationResult
    {
        $line = $this->scopedMenuProduct($command->menuId, $command->menuProductId, $command->context->organizationId);

        // Ghi đè topping ở tầng SHOP là `MenuProductToppingItemOverride` — cùng
        // bảng mà #1192 dạy là phải bump catalog revision. Model observer lo
        // việc đó, nên ghi qua quan hệ chứ không qua query builder.
        DB::transaction(function () use ($command, $line): void {
            foreach ($command->payload->overrides as $o) {
                $line->toppingItemOverrides()->updateOrCreate(
                    [
                        'topping_group_id' => $o->toppingGroupId,
                        'topping_group_item_id' => $o->itemId,
                        'product_sku_id' => $o->skuId,
                    ],
                    [
                        'is_hidden' => $o->hidden,
                        'override_price' => $o->priceOverrideMinor,
                    ],
                );
            }
        });

        return $this->ok((string) $line->id);
    }

    public function applyShopOverride(C\ApplyShopMenuOverrideCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $p = $command->payload;

        $sku = $this->scopedMenuSku($command->menuId, $p->skuId, $command->context->organizationId);
        $this->menus->overrideSkuPrice($sku, $p->sellingPriceMinor / 100);

        return $this->ok((string) $menu->id);
    }

    public function clearShopOverride(C\ClearShopMenuOverrideCommand $command): MutationResult
    {
        $sku = $this->scopedMenuSku($command->menuId, $command->menuItemId, $command->context->organizationId);
        $this->menus->resetSkuPrice($sku);

        return $this->ok((string) $sku->id);
    }

    // =====================================================================
    //  Lịch của menu + ghi đè theo chi nhánh
    // =====================================================================

    public function insertSchedule(C\CreateMenuScheduleCommand $command): MutationResult
    {
        $menu = $this->scopedMenu($command->menuId, $command->context->organizationId);
        $schedule = $this->withSuppliedId(fn () => $this->schedules->create($menu, $this->schedulePayload($command->payload)));

        return $this->ok((string) $schedule->id);
    }

    public function replaceSchedule(C\UpdateMenuScheduleCommand $command): MutationResult
    {
        $schedule = $this->scopedMenuSchedule($command->menuId, $command->scheduleId, $command->context->organizationId);
        $this->schedules->update($schedule, $this->schedulePayload($command->payload));

        return $this->ok((string) $schedule->id);
    }

    public function removeSchedule(C\DeleteMenuScheduleCommand $command): MutationResult
    {
        $schedule = $this->scopedMenuSchedule($command->menuId, $command->scheduleId, $command->context->organizationId);
        $this->schedules->delete($schedule);

        return $this->ok((string) $schedule->id);
    }

    public function upsertBranchScheduleOverride(C\UpsertBranchMenuScheduleOverrideCommand $command): MutationResult
    {
        $schedule = $this->scopedMenuSchedule($command->menuId, $command->masterScheduleId, $command->context->organizationId);
        $branch = Branch::query()->findOrFail($command->branchId);

        $this->branchSchedules->upsertOverride($schedule, $branch, $this->schedulePayload($command->payload));

        return $this->ok((string) $schedule->id);
    }

    public function resetBranchScheduleOverride(C\ResetBranchMenuScheduleOverrideCommand $command): MutationResult
    {
        $schedule = $this->scopedMenuSchedule($command->menuId, $command->masterScheduleId, $command->context->organizationId);
        $branch = Branch::query()->findOrFail($command->branchId);

        $this->branchSchedules->deleteOverride($schedule, $branch);

        return $this->ok((string) $schedule->id);
    }

    // =====================================================================
    //  Khung giờ ưu đãi (floating section)
    // =====================================================================

    public function insertFloatingSection(C\CreateFloatingMenuSectionCommand $command): MutationResult
    {
        $p = $command->payload;

        $section = $this->withSuppliedId(fn () => $this->floating->create(array_filter([
            'id' => $command->sectionId,
            'organization_id' => $command->context->organizationId,
            'branch_id' => $command->branchId,
            'name' => $p->name,
            'display_order' => $p->position,
            'is_active' => $p->active,
            'start_date' => $p->startDate,
            'end_date' => $p->endDate,
        ], static fn ($v) => $v !== null)));

        return $this->ok((string) $section->id);
    }

    public function reviseFloatingSection(C\ReviseFloatingMenuSectionCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $p = $command->payload;

        $this->floating->update($section, array_filter([
            'name' => $p->name,
            'display_order' => $p->position,
            'is_active' => $p->active,
            'start_date' => $p->startDate,
            'end_date' => $p->endDate,
        ], static fn ($v) => $v !== null));

        return $this->ok((string) $section->id);
    }

    public function removeFloatingSection(C\RemoveFloatingMenuSectionCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $this->floating->delete($section);

        return $this->ok((string) $section->id);
    }

    public function duplicateFloatingSection(C\DuplicateFloatingMenuSectionCommand $command): MutationResult
    {
        $source = $this->scopedFloating($command->sourceSectionId, $command->context->organizationId);
        $copy = $this->floating->duplicate($source);

        return $this->ok((string) $copy->id);
    }

    public function cloneFloatingSectionToBranch(C\CloneFloatingMenuSectionToBranchCommand $command): MutationResult
    {
        $master = $this->scopedFloating($command->masterSectionId, $command->context->organizationId);
        $clone = $this->floating->cloneToBranch($master, $command->branchId);

        return $this->ok((string) $clone->id);
    }

    public function syncFloatingSectionFromMaster(C\SyncFloatingMenuSectionFromMasterCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $this->floating->syncFromMaster($section);

        return $this->ok((string) $section->id);
    }

    public function placeFloatingProduct(C\PlaceFloatingMenuProductCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $this->floating->addProducts($section, [$command->payload->productId]);

        return $this->ok((string) $section->id);
    }

    public function removeFloatingProduct(C\RemoveFloatingMenuProductCommand $command): MutationResult
    {
        $product = $this->scopedFloatingProduct($command->sectionId, $command->menuProductId, $command->context->organizationId);
        $this->floating->removeProduct($product);

        return $this->ok((string) $product->id);
    }

    public function reorderFloatingProducts(C\ReorderFloatingMenuProductsCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $this->floating->reorderProducts($section, $command->payload->menuProductIds);

        return $this->ok((string) $section->id);
    }

    public function toggleFloatingProduct(C\ToggleFloatingMenuProductCommand $command): MutationResult
    {
        $product = $this->scopedFloatingProduct($command->sectionId, $command->menuProductId, $command->context->organizationId);

        if ((bool) $product->is_active !== $command->active) {
            $this->floating->toggleProduct($product);
        }

        return $this->ok((string) $product->id);
    }

    public function toggleFloatingSku(C\ToggleFloatingMenuSkuCommand $command): MutationResult
    {
        $sku = $this->scopedFloatingSku($command->sectionId, $command->menuProductSkuId, $command->context->organizationId);

        if ((bool) $sku->is_active !== $command->active) {
            $this->floating->toggleProductSku($sku);
        }

        return $this->ok((string) $sku->id);
    }

    public function overrideFloatingSkuPrice(C\OverrideFloatingMenuSkuPriceCommand $command): MutationResult
    {
        $sku = $this->scopedFloatingSku($command->sectionId, $command->payload->skuId, $command->context->organizationId);
        $this->floating->overrideSkuPrice($sku, $command->payload->sellingPriceMinor / 100);

        return $this->ok((string) $sku->id);
    }

    public function resetFloatingSkuPrice(C\ResetFloatingMenuSkuPriceCommand $command): MutationResult
    {
        $sku = $this->scopedFloatingSku($command->sectionId, $command->menuProductSkuId, $command->context->organizationId);
        $this->floating->resetSkuPrice($sku);

        return $this->ok((string) $sku->id);
    }

    // ── lịch của khung giờ ưu đãi ────────────────────────────────────────

    public function insertFloatingSchedule(C\CreateFloatingMenuScheduleCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $schedule = $this->withSuppliedId(fn () => $this->floatingSchedules->create($section, $this->schedulePayload($command->payload)));

        return $this->ok((string) $schedule->id);
    }

    public function reviseFloatingSchedule(C\ReviseFloatingMenuScheduleCommand $command): MutationResult
    {
        $schedule = $this->scopedFloatingSchedule($command->sectionId, $command->payload->scheduleId, $command->context->organizationId);
        $this->floatingSchedules->update($schedule, $this->schedulePayload($command->payload));

        return $this->ok((string) $schedule->id);
    }

    public function removeFloatingSchedule(C\RemoveFloatingMenuScheduleCommand $command): MutationResult
    {
        $schedule = $this->scopedFloatingSchedule($command->sectionId, $command->scheduleId, $command->context->organizationId);
        $this->floatingSchedules->delete($schedule);

        return $this->ok((string) $schedule->id);
    }

    public function toggleFloatingSchedule(C\ToggleFloatingMenuScheduleCommand $command): MutationResult
    {
        $schedule = FloatingSectionSchedule::query()->whereKey($command->scheduleId)->firstOrFail();
        $this->assertFloatingScheduleScope($schedule, $command->context->organizationId);
        $this->floatingSchedules->toggleActive($schedule);

        return $this->ok((string) $schedule->id);
    }

    public function overrideFloatingScheduleTime(C\OverrideFloatingMenuScheduleTimeCommand $command): MutationResult
    {
        $schedule = FloatingSectionSchedule::query()->whereKey($command->scheduleId)->firstOrFail();
        $this->assertFloatingScheduleScope($schedule, $command->context->organizationId);

        $this->floatingSchedules->overrideTime($schedule, [
            'days_of_week' => $command->payload->daysOfWeek,
            'start_time' => $command->payload->startTime,
            'end_time' => $command->payload->endTime,
        ]);

        return $this->ok((string) $schedule->id);
    }

    public function resetFloatingScheduleTime(C\ResetFloatingMenuScheduleTimeCommand $command): MutationResult
    {
        $schedule = FloatingSectionSchedule::query()->whereKey($command->scheduleId)->firstOrFail();
        $this->assertFloatingScheduleScope($schedule, $command->context->organizationId);
        $this->floatingSchedules->resetTimeOverride($schedule);

        return $this->ok((string) $schedule->id);
    }

    public function reorderFloatingSchedules(C\ReorderFloatingMenuSchedulesCommand $command): MutationResult
    {
        $section = $this->scopedFloating($command->sectionId, $command->context->organizationId);
        $this->floatingSchedules->reorder($section, $command->scheduleIds);

        return $this->ok((string) $section->id);
    }

    // =====================================================================
    //  Việc theo lô
    // =====================================================================

    public function promoteApprovedMenus(C\PromoteApprovedMenusCommand $command): MutationResult
    {
        $promoted = 0;
        Menu::query()
            ->when($command->context->organizationId !== null, fn ($q) => $q->where('organization_id', $command->context->organizationId))
            ->where('status', 'Approved')
            ->each(function (Menu $menu) use (&$promoted): void {
                $this->menus->activate($menu);
                $promoted++;
            });

        return new MutationResult((string) ($command->context->correlationId ?: Str::uuid()), null, $promoted > 0);
    }

    public function backfillSkuPlacements(C\BackfillMenuSkuPlacementsCommand $command): MutationResult
    {
        // Việc vá dữ liệu, cố ý KHÔNG dựng đường ghi thứ hai: nó chạy qua lệnh
        // artisan đã có (`menus:repair-clone-drift`). Facade công bố nó để chỗ
        // gọi ngoài module có một cửa, không phải để chép lại thân lệnh.
        $repaired = 0;
        Menu::query()
            ->when($command->context->organizationId !== null, fn ($q) => $q->where('organization_id', $command->context->organizationId))
            ->whereNotNull('master_menu_id')
            ->each(function (Menu $menu) use (&$repaired): void {
                $this->menus->repairCloneDriftFromMaster($menu, true);
                $repaired++;
            });

        return new MutationResult((string) ($command->context->correlationId ?: Str::uuid()), null, $repaired > 0);
    }

    // =====================================================================
    //  Tra cứu phụ cho khung giờ ưu đãi
    // =====================================================================

    private function scopedFloatingProduct(string $sectionId, string $productId, ?string $organizationId): FloatingSectionProduct
    {
        $section = $this->scopedFloating($sectionId, $organizationId);

        return FloatingSectionProduct::query()
            ->where('floating_section_id', $section->id)
            ->whereKey($productId)
            ->firstOrFail();
    }

    private function scopedFloatingSku(string $sectionId, string $skuId, ?string $organizationId): FloatingSectionProductSku
    {
        $section = $this->scopedFloating($sectionId, $organizationId);

        return FloatingSectionProductSku::query()
            ->whereKey($skuId)
            ->whereIn(
                'floating_section_product_id',
                FloatingSectionProduct::query()->where('floating_section_id', $section->id)->select('id'),
            )
            ->firstOrFail();
    }

    private function scopedFloatingSchedule(string $sectionId, string $scheduleId, ?string $organizationId): FloatingSectionSchedule
    {
        $section = $this->scopedFloating($sectionId, $organizationId);

        return FloatingSectionSchedule::query()
            ->where('floating_section_id', $section->id)
            ->whereKey($scheduleId)
            ->firstOrFail();
    }

    /**
     * Ba method lịch chỉ nhận `scheduleId` — không có sectionId để ràng.
     *
     * Nên phạm vi tổ chức phải kiểm NGƯỢC qua section. Bỏ qua bước này là để
     * một `scheduleId` của tenant khác sửa được, và đó là loại lỗ hổng không ai
     * nhìn thấy cho tới khi có người thử.
     */
    private function assertFloatingScheduleScope(FloatingSectionSchedule $schedule, ?string $organizationId): void
    {
        if ($organizationId === null) {
            return;
        }

        $ok = FloatingSection::query()
            ->whereKey($schedule->floating_section_id)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $ok) {
            throw (new ModelNotFoundException)->setModel(FloatingSectionSchedule::class, [$schedule->id]);
        }
    }

    // =====================================================================
    //  Bí danh của cùng một cửa — port công bố từng động từ, chỗ ghi thì một
    // =====================================================================

    /*
     * Port có method riêng cho từng động từ (`reorderSections`, `markApproved`,
     * …), còn Command thì dùng chung (`ReplaceMenuLayoutCommand`,
     * `MenuLifecycleCommand`) và mang `operation` / `action` để phân biệt.
     *
     * Nên đây là bí danh mỏng, KHÔNG phải bảy bản sao: nếu mỗi cái tự dựng lời
     * gọi riêng thì bảy chỗ phải nhớ cùng một luật phạm vi tổ chức, và luật đó
     * sẽ trôi ở đúng cái ít ai chạm nhất.
     *
     * Đọc kèm `replaceLayout()` / `markRestored()` ở trên — chúng là thân thật.
     */

    public function reorderSections(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->replaceLayout($command);
    }

    public function reorderProducts(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->replaceLayout($command);
    }

    public function reorderLayout(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->replaceLayout($command);
    }

    public function markSubmitted(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->markRestored($command);
    }

    public function markApproved(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->markRestored($command);
    }

    public function markRejected(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->markRestored($command);
    }

    public function markActive(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->markRestored($command);
    }

    public function markInactive(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->markRestored($command);
    }

    public function markArchived(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->markRestored($command);
    }
}
