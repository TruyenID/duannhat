<?php

namespace App\Services\Product;

use App\Exceptions\OptionValueInUseException;
use App\Exceptions\SkuInMenuException;
use App\Models\MenuProductSku;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductOptionValueService
{
    public function __construct(
        private readonly MenuService $menus,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @return Collection<int, ProductOptionValue>
     */
    public function listForOption(string $optionId): Collection
    {
        return ProductOptionValue::query()
            ->where('option_id', $optionId)
            ->orderBy('position')
            ->get();
    }

    public function findById(string $id): ProductOptionValue
    {
        return ProductOptionValue::with('option.product')->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    /**
     * @param  array{option_id: string, value: string, label: string, position?: int, is_active?: bool}  $data
     */
    public function create(array $data): ProductOptionValue
    {
        return DB::transaction(function () use ($data) {
            if (! isset($data['position'])) {
                $data['position'] = ProductOptionValue::where('option_id', $data['option_id'])
                    ->max('position') + 1;
            }

            // The unique(option_id, value) constraint does not include deleted_at,
            // so a soft-deleted row still holds the slot. Restore it instead of
            // creating a new row (consistent with the SKU / MenuProductSku restore
            // pattern in generateMissingCombinations and syncNewSkusToMenuBranches).
            $trashed = ProductOptionValue::withTrashed()
                ->where('option_id', $data['option_id'])
                ->where('value', $data['value'])
                ->whereNotNull('deleted_at')
                ->first();

            if ($trashed) {
                if (isset($data['id']) && $data['id'] !== $trashed->id) {
                    throw ValidationException::withMessages([
                        'value_id' => ['A soft-deleted value already owns this option slug under a different command ID.'],
                    ]);
                }
                $trashed->restore();
                unset($data['id'], $data['option_id']);
                $trashed->update($data);

                return $trashed->fresh();
            }

            if (ProductOptionValue::where('option_id', $data['option_id'])
                ->where('value', $data['value'])
                ->exists()) {
                // #2488 — cùng lý do với ProductOptionService::syncValues: nhãn
                // tiếng Nhật băm ra `value_<hash>`, nên báo lỗi bằng slug là báo
                // bằng một chuỗi người dùng chưa từng gõ.
                $label = trim((string) ($data['label'] ?? '')) !== '' ? $data['label'] : $data['value'];
                throw ValidationException::withMessages([
                    'value' => ["A value named '{$label}' already exists for this option."],
                ]);
            }

            $id = $data['id'] ?? null;
            unset($data['id']);
            $value = new ProductOptionValue;
            $value->fill($data);
            if ($id !== null) {
                $value->forceFill(['id' => $id]);
            }
            $value->save();

            return $value;
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(ProductOptionValue $value, array $data): ProductOptionValue
    {
        return DB::transaction(function () use ($value, $data) {
            // Slug (`value`) is only editable while no SKU references it.
            // `label` is always editable (translatable display string).
            if (array_key_exists('value', $data) && $data['value'] !== $value->value) {
                $blockingSkus = $this->blockingSkusFor($value);

                if (! empty($blockingSkus)) {
                    throw new OptionValueInUseException(
                        "Cannot change slug: value '{$value->value}' is referenced by existing SKUs.",
                        $blockingSkus,
                    );
                }
            }

            $value->update($data);

            return $value->fresh();
        });
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(ProductOptionValue $value): bool
    {
        $blockingSkus = $this->blockingSkusFor($value);

        if (! empty($blockingSkus)) {
            throw new OptionValueInUseException(
                "Cannot delete value '{$value->value}': it is referenced by existing SKUs. "
                    .'Remove or reassign those SKUs first.',
                $blockingSkus,
            );
        }

        return $value->delete();
    }

    /**
     * Force-delete: soft-delete every active SKU referencing this value
     * (and their MenuProductSku rows), then soft-delete the value itself.
     * All in one transaction so a mid-way failure leaves no orphans.
     *
     * Blocked if any affected SKU is still assigned to a menu — the user
     * must remove the SKU from the menu before force-deleting the value.
     * This is consistent with {@see ProductSkuService::delete()}.
     */
    public function forceDelete(ProductOptionValue $value): void
    {
        DB::transaction(function () use ($value) {
            $value->loadMissing('option');
            $fkColumn = "option_value{$value->option->position}_id";

            $affectedSkus = ProductSku::where($fkColumn, $value->id)->get();

            $blockingMenus = $affectedSkus->flatMap(function (ProductSku $sku): \Illuminate\Support\Collection {
                return MenuProductSku::where('product_sku_id', $sku->id)
                    ->with('menuProduct.menu:id,name')
                    ->get()
                    ->map(fn (MenuProductSku $mps) => [
                        'id' => $mps->menuProduct->menu->id,
                        'name' => $mps->menuProduct->menu->name,
                    ]);
            })->unique('id')->values()->all();

            if (! empty($blockingMenus)) {
                throw new SkuInMenuException(
                    'Cannot delete: linked SKUs are still assigned to menus. Remove from the menu first.',
                    $blockingMenus,
                );
            }

            $affectedSkus->each(function (ProductSku $sku): void {
                $this->menus->deleteMenuProductSkusForProductSku($sku);
                $sku->delete();
            });

            $value->delete();
        });
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Return the SKUs that block modifying or deleting this value.
     *
     * @return array<int, array{id: string, sku: string}>
     */
    private function blockingSkusFor(ProductOptionValue $value): array
    {
        $value->loadMissing('option');
        $position = $value->option->position;
        $fkColumn = "option_value{$position}_id";

        return ProductSku::where($fkColumn, $value->id)
            ->select(['id', 'sku'])
            ->get()
            ->map(fn (ProductSku $sku) => ['id' => $sku->id, 'sku' => $sku->sku])
            ->all();
    }
}
