<?php

namespace App\Services\Menu\Contracts;

use App\Services\DomainMutation\MutationResult;
use App\Services\Menu\Commands\ApplyShopMenuOverrideCommand;
use App\Services\Menu\Commands\BackfillMenuSkuPlacementsCommand;
use App\Services\Menu\Commands\ClearShopMenuOverrideCommand;
use App\Services\Menu\Commands\CloneFloatingMenuSectionToBranchCommand;
use App\Services\Menu\Commands\CloneMenuToBranchCommand;
use App\Services\Menu\Commands\CreateFloatingMenuScheduleCommand;
use App\Services\Menu\Commands\CreateFloatingMenuSectionCommand;
use App\Services\Menu\Commands\CreateMenuCommand;
use App\Services\Menu\Commands\CreateMenuScheduleCommand;
use App\Services\Menu\Commands\CreateMenuSectionCommand;
use App\Services\Menu\Commands\DeleteMenuScheduleCommand;
use App\Services\Menu\Commands\DuplicateFloatingMenuSectionCommand;
use App\Services\Menu\Commands\DuplicateStandaloneMenuCommand;
use App\Services\Menu\Commands\MenuLifecycleCommand;
use App\Services\Menu\Commands\OverrideFloatingMenuScheduleTimeCommand;
use App\Services\Menu\Commands\OverrideFloatingMenuSkuPriceCommand;
use App\Services\Menu\Commands\PlaceFloatingMenuProductCommand;
use App\Services\Menu\Commands\PlaceMenuProductCommand;
use App\Services\Menu\Commands\PromoteApprovedMenusCommand;
use App\Services\Menu\Commands\RemoveFloatingMenuProductCommand;
use App\Services\Menu\Commands\RemoveFloatingMenuScheduleCommand;
use App\Services\Menu\Commands\RemoveFloatingMenuSectionCommand;
use App\Services\Menu\Commands\RemoveMenuProductCommand;
use App\Services\Menu\Commands\RemoveMenuSectionCommand;
use App\Services\Menu\Commands\ReorderFloatingMenuProductsCommand;
use App\Services\Menu\Commands\ReorderFloatingMenuSchedulesCommand;
use App\Services\Menu\Commands\ReplaceMenuLayoutCommand;
use App\Services\Menu\Commands\ResetBranchMenuScheduleOverrideCommand;
use App\Services\Menu\Commands\ResetFloatingMenuScheduleTimeCommand;
use App\Services\Menu\Commands\ResetFloatingMenuSkuPriceCommand;
use App\Services\Menu\Commands\ResetMenuSkuPriceCommand;
use App\Services\Menu\Commands\ReviseFloatingMenuScheduleCommand;
use App\Services\Menu\Commands\ReviseFloatingMenuSectionCommand;
use App\Services\Menu\Commands\ReviseMenuCommand;
use App\Services\Menu\Commands\ReviseMenuSectionCommand;
use App\Services\Menu\Commands\SyncFloatingMenuSectionFromMasterCommand;
use App\Services\Menu\Commands\SyncMenuFromMasterCommand;
use App\Services\Menu\Commands\SyncMenuToppingsCommand;
use App\Services\Menu\Commands\ToggleFloatingMenuProductCommand;
use App\Services\Menu\Commands\ToggleFloatingMenuScheduleCommand;
use App\Services\Menu\Commands\ToggleFloatingMenuSkuCommand;
use App\Services\Menu\Commands\ToggleMenuProductCommand;
use App\Services\Menu\Commands\ToggleMenuSkuCommand;
use App\Services\Menu\Commands\UpdateMenuScheduleCommand;
use App\Services\Menu\Commands\UpsertBranchMenuScheduleOverrideCommand;

interface MenuMutationFacade
{
    public function create(CreateMenuCommand $command): MutationResult;

    public function cloneToBranch(CloneMenuToBranchCommand $command): MutationResult;

    public function duplicateStandalone(DuplicateStandaloneMenuCommand $command): MutationResult;

    public function syncFromMaster(SyncMenuFromMasterCommand $command): MutationResult;

    public function createSchedule(CreateMenuScheduleCommand $command): MutationResult;

    public function updateSchedule(UpdateMenuScheduleCommand $command): MutationResult;

    public function deleteSchedule(DeleteMenuScheduleCommand $command): MutationResult;

    public function upsertBranchScheduleOverride(UpsertBranchMenuScheduleOverrideCommand $command): MutationResult;

    public function resetBranchScheduleOverride(ResetBranchMenuScheduleOverrideCommand $command): MutationResult;

    public function createSection(CreateMenuSectionCommand $command): MutationResult;

    public function reviseSection(ReviseMenuSectionCommand $command): MutationResult;

    public function removeSection(RemoveMenuSectionCommand $command): MutationResult;

    public function reorderSections(ReplaceMenuLayoutCommand $command): MutationResult;

    public function placeProduct(PlaceMenuProductCommand $command): MutationResult;

    public function removeProduct(RemoveMenuProductCommand $command): MutationResult;

    public function reorderProducts(ReplaceMenuLayoutCommand $command): MutationResult;

    public function reorderLayout(ReplaceMenuLayoutCommand $command): MutationResult;

    public function toggleProduct(ToggleMenuProductCommand $command): MutationResult;

    public function toggleSku(ToggleMenuSkuCommand $command): MutationResult;

    public function resetSkuPrice(ResetMenuSkuPriceCommand $command): MutationResult;

    public function syncToppings(SyncMenuToppingsCommand $command): MutationResult;

    public function revise(ReviseMenuCommand $command): MutationResult;

    public function replaceLayout(ReplaceMenuLayoutCommand $command): MutationResult;

    public function applyShopOverride(ApplyShopMenuOverrideCommand $command): MutationResult;

    public function clearShopOverride(ClearShopMenuOverrideCommand $command): MutationResult;

    public function createFloatingSection(CreateFloatingMenuSectionCommand $command): MutationResult;

    public function reviseFloatingSection(ReviseFloatingMenuSectionCommand $command): MutationResult;

    public function removeFloatingSection(RemoveFloatingMenuSectionCommand $command): MutationResult;

    public function duplicateFloatingSection(DuplicateFloatingMenuSectionCommand $command): MutationResult;

    public function cloneFloatingSectionToBranch(CloneFloatingMenuSectionToBranchCommand $command): MutationResult;

    public function syncFloatingSectionFromMaster(SyncFloatingMenuSectionFromMasterCommand $command): MutationResult;

    public function placeFloatingProduct(PlaceFloatingMenuProductCommand $command): MutationResult;

    public function removeFloatingProduct(RemoveFloatingMenuProductCommand $command): MutationResult;

    public function reorderFloatingProducts(ReorderFloatingMenuProductsCommand $command): MutationResult;

    public function toggleFloatingProduct(ToggleFloatingMenuProductCommand $command): MutationResult;

    public function toggleFloatingSku(ToggleFloatingMenuSkuCommand $command): MutationResult;

    public function overrideFloatingSkuPrice(OverrideFloatingMenuSkuPriceCommand $command): MutationResult;

    public function resetFloatingSkuPrice(ResetFloatingMenuSkuPriceCommand $command): MutationResult;

    public function createFloatingSchedule(CreateFloatingMenuScheduleCommand $command): MutationResult;

    public function reviseFloatingSchedule(ReviseFloatingMenuScheduleCommand $command): MutationResult;

    public function removeFloatingSchedule(RemoveFloatingMenuScheduleCommand $command): MutationResult;

    public function toggleFloatingSchedule(ToggleFloatingMenuScheduleCommand $command): MutationResult;

    public function overrideFloatingScheduleTime(OverrideFloatingMenuScheduleTimeCommand $command): MutationResult;

    public function resetFloatingScheduleTime(ResetFloatingMenuScheduleTimeCommand $command): MutationResult;

    public function reorderFloatingSchedules(ReorderFloatingMenuSchedulesCommand $command): MutationResult;

    public function promoteApprovedMenus(PromoteApprovedMenusCommand $command): MutationResult;

    public function backfillSkuPlacements(BackfillMenuSkuPlacementsCommand $command): MutationResult;

    public function submit(MenuLifecycleCommand $command): MutationResult;

    public function approve(MenuLifecycleCommand $command): MutationResult;

    public function reject(MenuLifecycleCommand $command): MutationResult;

    public function activate(MenuLifecycleCommand $command): MutationResult;

    public function deactivate(MenuLifecycleCommand $command): MutationResult;

    public function archive(MenuLifecycleCommand $command): MutationResult;

    public function restore(MenuLifecycleCommand $command): MutationResult;
}
