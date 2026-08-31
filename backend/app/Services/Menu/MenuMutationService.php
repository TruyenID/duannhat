<?php

declare(strict_types=1);

namespace App\Services\Menu;

use App\Services\DomainMutation\MutationResult;
use App\Services\Menu\Commands as C;
use App\Services\Menu\Contracts\MenuMutationFacade;
use App\Services\Menu\Contracts\MenuPersistencePort;

/**
 * API GHI công bố của Menu (#1550).
 *
 * Mỏng có chủ ý, đúng khuôn `ProductService implements ProductMutationFacade`:
 * mỗi method uỷ quyền cho một method của {@see MenuPersistencePort}. Chỗ nào
 * cũng chỉ một dòng, và đó là điểm — mọi quyết định nằm ở persistence, nên
 * không có chỗ thứ hai để một luật trôi vào.
 *
 * Vì sao vẫn cần lớp này khi nó chỉ chuyển tiếp: `MenuPersistencePort` là
 * `Internal`, còn facade là thứ module khác được phép gọi. Bỏ nó đi thì ranh
 * giới chỉ còn là quy ước, và quy ước thì #962 đã đo là không giữ được.
 *
 * Tên method khác nhau ở hai bên là CỐ Ý: facade nói theo ngôn ngữ nghiệp vụ
 * (`create`, `approve`, `archive`), persistence nói theo việc nó làm với dữ
 * liệu (`insertMenu`, `markApproved`, `markArchived`).
 */
final class MenuMutationService implements MenuMutationFacade
{
    public function __construct(private readonly MenuPersistencePort $persistence) {}

    public function create(C\CreateMenuCommand $command): MutationResult
    {
        return $this->persistence->insertMenu($command);
    }

    public function cloneToBranch(C\CloneMenuToBranchCommand $command): MutationResult
    {
        return $this->persistence->cloneToBranch($command);
    }

    public function duplicateStandalone(C\DuplicateStandaloneMenuCommand $command): MutationResult
    {
        return $this->persistence->duplicateStandalone($command);
    }

    public function syncFromMaster(C\SyncMenuFromMasterCommand $command): MutationResult
    {
        return $this->persistence->syncFromMaster($command);
    }

    public function createSchedule(C\CreateMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->insertSchedule($command);
    }

    public function updateSchedule(C\UpdateMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->replaceSchedule($command);
    }

    public function deleteSchedule(C\DeleteMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->removeSchedule($command);
    }

    public function upsertBranchScheduleOverride(C\UpsertBranchMenuScheduleOverrideCommand $command): MutationResult
    {
        return $this->persistence->upsertBranchScheduleOverride($command);
    }

    public function resetBranchScheduleOverride(C\ResetBranchMenuScheduleOverrideCommand $command): MutationResult
    {
        return $this->persistence->resetBranchScheduleOverride($command);
    }

    public function createSection(C\CreateMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->insertSection($command);
    }

    public function reviseSection(C\ReviseMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->reviseSection($command);
    }

    public function removeSection(C\RemoveMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->removeSection($command);
    }

    public function reorderSections(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->persistence->reorderSections($command);
    }

    public function placeProduct(C\PlaceMenuProductCommand $command): MutationResult
    {
        return $this->persistence->placeProduct($command);
    }

    public function removeProduct(C\RemoveMenuProductCommand $command): MutationResult
    {
        return $this->persistence->removeProduct($command);
    }

    public function reorderProducts(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->persistence->reorderProducts($command);
    }

    public function reorderLayout(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->persistence->reorderLayout($command);
    }

    public function toggleProduct(C\ToggleMenuProductCommand $command): MutationResult
    {
        return $this->persistence->toggleProduct($command);
    }

    public function toggleSku(C\ToggleMenuSkuCommand $command): MutationResult
    {
        return $this->persistence->toggleSku($command);
    }

    public function resetSkuPrice(C\ResetMenuSkuPriceCommand $command): MutationResult
    {
        return $this->persistence->resetSkuPrice($command);
    }

    public function syncToppings(C\SyncMenuToppingsCommand $command): MutationResult
    {
        return $this->persistence->syncToppings($command);
    }

    public function revise(C\ReviseMenuCommand $command): MutationResult
    {
        return $this->persistence->applyRevision($command);
    }

    public function replaceLayout(C\ReplaceMenuLayoutCommand $command): MutationResult
    {
        return $this->persistence->replaceLayout($command);
    }

    public function applyShopOverride(C\ApplyShopMenuOverrideCommand $command): MutationResult
    {
        return $this->persistence->applyShopOverride($command);
    }

    public function clearShopOverride(C\ClearShopMenuOverrideCommand $command): MutationResult
    {
        return $this->persistence->clearShopOverride($command);
    }

    public function createFloatingSection(C\CreateFloatingMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->insertFloatingSection($command);
    }

    public function reviseFloatingSection(C\ReviseFloatingMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->reviseFloatingSection($command);
    }

    public function removeFloatingSection(C\RemoveFloatingMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->removeFloatingSection($command);
    }

    public function duplicateFloatingSection(C\DuplicateFloatingMenuSectionCommand $command): MutationResult
    {
        return $this->persistence->duplicateFloatingSection($command);
    }

    public function cloneFloatingSectionToBranch(C\CloneFloatingMenuSectionToBranchCommand $command): MutationResult
    {
        return $this->persistence->cloneFloatingSectionToBranch($command);
    }

    public function syncFloatingSectionFromMaster(C\SyncFloatingMenuSectionFromMasterCommand $command): MutationResult
    {
        return $this->persistence->syncFloatingSectionFromMaster($command);
    }

    public function placeFloatingProduct(C\PlaceFloatingMenuProductCommand $command): MutationResult
    {
        return $this->persistence->placeFloatingProduct($command);
    }

    public function removeFloatingProduct(C\RemoveFloatingMenuProductCommand $command): MutationResult
    {
        return $this->persistence->removeFloatingProduct($command);
    }

    public function reorderFloatingProducts(C\ReorderFloatingMenuProductsCommand $command): MutationResult
    {
        return $this->persistence->reorderFloatingProducts($command);
    }

    public function toggleFloatingProduct(C\ToggleFloatingMenuProductCommand $command): MutationResult
    {
        return $this->persistence->toggleFloatingProduct($command);
    }

    public function toggleFloatingSku(C\ToggleFloatingMenuSkuCommand $command): MutationResult
    {
        return $this->persistence->toggleFloatingSku($command);
    }

    public function overrideFloatingSkuPrice(C\OverrideFloatingMenuSkuPriceCommand $command): MutationResult
    {
        return $this->persistence->overrideFloatingSkuPrice($command);
    }

    public function resetFloatingSkuPrice(C\ResetFloatingMenuSkuPriceCommand $command): MutationResult
    {
        return $this->persistence->resetFloatingSkuPrice($command);
    }

    public function createFloatingSchedule(C\CreateFloatingMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->insertFloatingSchedule($command);
    }

    public function reviseFloatingSchedule(C\ReviseFloatingMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->reviseFloatingSchedule($command);
    }

    public function removeFloatingSchedule(C\RemoveFloatingMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->removeFloatingSchedule($command);
    }

    public function toggleFloatingSchedule(C\ToggleFloatingMenuScheduleCommand $command): MutationResult
    {
        return $this->persistence->toggleFloatingSchedule($command);
    }

    public function overrideFloatingScheduleTime(C\OverrideFloatingMenuScheduleTimeCommand $command): MutationResult
    {
        return $this->persistence->overrideFloatingScheduleTime($command);
    }

    public function resetFloatingScheduleTime(C\ResetFloatingMenuScheduleTimeCommand $command): MutationResult
    {
        return $this->persistence->resetFloatingScheduleTime($command);
    }

    public function reorderFloatingSchedules(C\ReorderFloatingMenuSchedulesCommand $command): MutationResult
    {
        return $this->persistence->reorderFloatingSchedules($command);
    }

    public function promoteApprovedMenus(C\PromoteApprovedMenusCommand $command): MutationResult
    {
        return $this->persistence->promoteApprovedMenus($command);
    }

    public function backfillSkuPlacements(C\BackfillMenuSkuPlacementsCommand $command): MutationResult
    {
        return $this->persistence->backfillSkuPlacements($command);
    }

    public function submit(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markSubmitted($command);
    }

    public function approve(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markApproved($command);
    }

    public function reject(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markRejected($command);
    }

    public function activate(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markActive($command);
    }

    public function deactivate(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markInactive($command);
    }

    public function archive(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markArchived($command);
    }

    public function restore(C\MenuLifecycleCommand $command): MutationResult
    {
        return $this->persistence->markRestored($command);
    }
}
