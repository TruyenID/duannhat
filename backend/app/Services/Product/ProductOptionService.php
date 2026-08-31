<?php

namespace App\Services\Product;

use App\Exceptions\OptionInUseException;
use App\Exceptions\OptionValueInUseException;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductOptionService
{
    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @return Collection<int, ProductOption>
     */
    public function listForProduct(string $productId): Collection
    {
        return ProductOption::query()
            ->where('product_id', $productId)
            ->with('values')
            ->orderBy('position')
            ->get();
    }

    public function findById(string $id): ProductOption
    {
        return ProductOption::with('values')->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    /**
     * @param  array{product_id: string, key: string, name: string, position: int, is_active?: bool}  $data
     */
    public function create(array $data): ProductOption
    {
        return DB::transaction(function () use ($data) {
            $trashed = ProductOption::withTrashed()
                ->where('product_id', $data['product_id'])
                ->where('key', $data['key'])
                ->whereNotNull('deleted_at')
                ->first();
            if ($trashed !== null) {
                if (($data['id'] ?? null) !== $trashed->id) {
                    throw ValidationException::withMessages([
                        'option_id' => ['A soft-deleted option already owns this product key under a different command ID.'],
                    ]);
                }
                $trashed->restore();
                unset($data['id'], $data['product_id']);
                $trashed->update($data);

                return $trashed->fresh('values');
            }

            $this->validatePosition($data['product_id'], $data['position']);
            $this->validateKeyUnique($data['product_id'], $data['key']);

            $id = $data['id'] ?? null;
            unset($data['id']);
            $option = new ProductOption;
            $option->fill($data);
            if ($id !== null) {
                $option->forceFill(['id' => $id]);
            }
            $option->save();

            return $option->load('values');
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(ProductOption $option, array $data): ProductOption
    {
        return DB::transaction(function () use ($option, $data) {
            $hasSkus = $this->optionHasSkuReferences($option);

            if ($hasSkus && array_key_exists('key', $data) && $data['key'] !== $option->key) {
                throw ValidationException::withMessages([
                    'key' => "Cannot change key: option '{$option->key}' is referenced by existing SKUs.",
                ]);
            }

            if (array_key_exists('key', $data) && $data['key'] !== $option->key) {
                $this->validateKeyUnique($option->product_id, $data['key'], $option->id);
            }

            if (array_key_exists('position', $data) && (int) $data['position'] !== $option->position) {
                $this->reorderPosition($option, (int) $data['position'], $hasSkus);
                // Position was already persisted inside reorderPosition; remove it
                // from $data so the subsequent update() call does not re-write it
                // and accidentally trigger another unique-constraint round-trip.
                unset($data['position']);
            }

            if (! empty($data)) {
                $option->update($data);
            }

            return $option->load('values');
        });
    }

    /**
     * Move this option to $newPosition, swapping with any option already
     * there.  When SKUs exist, the corresponding slot columns are also swapped
     * so display stays aligned with positions.
     *
     * Because option_signature is now alphabetically sorted (order-independent),
     * swapping slots never changes any signature and no SKUs are recreated.
     */
    private function reorderPosition(ProductOption $option, int $newPosition, bool $hasSkus): void
    {
        if ($newPosition < 1 || $newPosition > 3) {
            throw ValidationException::withMessages([
                'position' => "Position must be 1, 2, or 3 (got {$newPosition}).",
            ]);
        }

        $oldPosition = $option->position;

        $conflicting = ProductOption::where('product_id', $option->product_id)
            ->where('position', $newPosition)
            ->where('id', '!=', $option->id)
            ->first();

        // Swap SKU slot columns so that position N always reads option_valueN_id.
        // With sorted signatures this is a no-op for SKU identity, but it keeps
        // the slot → position invariant intact for display queries.
        if ($hasSkus) {
            $this->swapSkuSlots($option->product_id, $oldPosition, $newPosition);
        }

        if ($conflicting) {
            // Atomic position swap via a temporary sentinel (0) to avoid the
            // UNIQUE(product_id, position) constraint during intermediate state:
            //   1. conflicting → 0  (frees $newPosition)
            //   2. this option → $newPosition
            //   3. conflicting → $oldPosition  (now vacated)
            DB::table('product_options')
                ->where('id', $conflicting->id)
                ->update(['position' => 0]);

            DB::table('product_options')
                ->where('id', $option->id)
                ->update(['position' => $newPosition]);
            $option->position = $newPosition;

            DB::table('product_options')
                ->where('id', $conflicting->id)
                ->update(['position' => $oldPosition]);
        } else {
            DB::table('product_options')
                ->where('id', $option->id)
                ->update(['position' => $newPosition]);
            $option->position = $newPosition;
        }
    }

    /**
     * Swap option_value{posA}_id ↔ option_value{posB}_id across every SKU
     * for the given product.
     *
     * MySQL evaluates SET clauses left-to-right, so a single UPDATE cannot
     * safely swap two columns (the second assignment sees the already-written
     * first value).  We read original values in PHP and write them swapped via
     * saveQuietly() — observer is intentionally bypassed because the sorted
     * signature is invariant under slot reordering.
     */
    private function swapSkuSlots(string $productId, int $posA, int $posB): void
    {
        $colA = "option_value{$posA}_id";
        $colB = "option_value{$posB}_id";

        ProductSku::withTrashed()
            ->where('product_id', $productId)
            ->each(function (ProductSku $sku) use ($colA, $colB): void {
                [$a, $b] = [$sku->{$colA}, $sku->{$colB}];
                $sku->{$colA} = $b;
                $sku->{$colB} = $a;
                $sku->saveQuietly();
            });
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    // =========================================================================
    //  Batch sync: rename option + upsert/remove values in one transaction
    // =========================================================================

    /**
     * Apply a full diff against an option: update its display name, rename
     * existing value labels, insert new values, and remove values whose id was
     * dropped from $values. Wrapped in a transaction so a 409 on any value
     * leaves the whole option untouched.
     *
     * Rules (mirror the per-row endpoints):
     *  - `name` updates the option's display name (key/slug never changes).
     *  - Each $values[i] with an `id` updates the existing value's `label`
     *    only. The slug (`value`) is FK-referenced by SKUs — we never reslug
     *    on rename, that triggers the 409 we just fixed.
     *  - Each $values[i] without an `id` is treated as create (slug derived
     *    from label via the caller; we trust whatever `value` they send).
     *  - Any existing value whose id is missing from $values is soft-deleted.
     *    If that value is still referenced by SKUs, we collect the blocker and
     *    throw OptionValueInUseException with the union of all blockers — the
     *    FE shows one conflict dialog instead of N.
     *  - After value rows settle, missing SKU combinations are generated for
     *    newly added values (same behaviour as expandOption).
     *
     * @param  array{
     *     name?: string,
     *     values: array<int, array{id?: string, value?: string, label: string}>,
     * }  $data
     * @return array{
     *     option: ProductOption,
     *     created_skus: \Illuminate\Support\Collection<int, ProductSku>,
     *     blocking_removals: array<int, array{value_id: string, value: string, blocking_skus: array<int, array{id: string, sku: string}>}>,
     * }
     */
    public function syncValues(ProductOption $option, array $data, ProductSkuService $skuService): array
    {
        return DB::transaction(function () use ($option, $data, $skuService) {
            // 1. Rename option display name (key/slug stays fixed at create).
            if (array_key_exists('name', $data) && $data['name'] !== $option->name) {
                $option->update(['name' => $data['name']]);
            }

            $option->loadMissing('values');
            $existingById = $option->values->keyBy('id');
            $submitted = $data['values'];
            $submittedIds = collect($submitted)
                ->pluck('id')
                ->filter()
                ->all();

            // 2. Soft-delete values that disappeared from the submission. Block
            //    if any are still referenced by SKUs — the FE force-delete flow
            //    handles that path separately.
            $toRemove = $existingById->reject(fn ($v) => in_array($v->id, $submittedIds, true));
            $blockingRemovals = [];

            foreach ($toRemove as $value) {
                $blockingSkus = $this->blockingSkusForValue($value, $option);
                if (! empty($blockingSkus)) {
                    $blockingRemovals[] = [
                        'value_id' => $value->id,
                        'value' => $value->value,
                        'blocking_skus' => $blockingSkus,
                    ];

                    continue;
                }

                $value->delete();
            }

            if (! empty($blockingRemovals)) {
                throw new OptionValueInUseException(
                    'Cannot remove values that are still referenced by SKUs.',
                    collect($blockingRemovals)->flatMap(fn ($r) => $r['blocking_skus'])->unique('id')->values()->all(),
                );
            }

            // 3. Update existing labels + insert new values. The submitted
            //    array order is authoritative — we re-number `position` 1..N
            //    using the index so the FE can drag-reorder values without a
            //    second round-trip.
            foreach ($submitted as $index => $row) {
                $position = $index + 1;

                if (! empty($row['id'])) {
                    $existing = $existingById->get($row['id']);
                    if (! $existing) {
                        continue;
                    }

                    $changes = [];
                    if ($existing->label !== $row['label']) {
                        $changes['label'] = $row['label'];
                    }
                    if ((int) $existing->position !== $position) {
                        $changes['position'] = $position;
                    }
                    if (! empty($changes)) {
                        $existing->update($changes);
                    }

                    continue;
                }

                $slug = $row['value'] ?? Str::slug($row['label'], '_');

                // Same restore-soft-deleted-row pattern as ProductOptionValueService::create.
                $trashed = ProductOptionValue::withTrashed()
                    ->where('option_id', $option->id)
                    ->where('value', $slug)
                    ->whereNotNull('deleted_at')
                    ->first();

                if ($trashed) {
                    if (! empty($row['new_id']) && $row['new_id'] !== $trashed->id) {
                        throw ValidationException::withMessages([
                            'values' => ['A soft-deleted value already owns this option slug under a different command ID.'],
                        ]);
                    }
                    $trashed->restore();
                    $trashed->update([
                        'label' => $row['label'],
                        'position' => $position,
                        'is_active' => true,
                    ]);

                    continue;
                }

                if (ProductOptionValue::where('option_id', $option->id)->where('value', $slug)->exists()) {
                    // #2488 — nói bằng NHÃN người dùng gõ, không phải slug nội bộ.
                    //
                    // Nhãn tiếng Nhật băm ra `value_i51sxu`, và "Value
                    // 'value_i51sxu' already exists" là một chuỗi người dùng
                    // chưa từng thấy — cho một nhãn mà trên màn hình họ nhìn
                    // thấy rõ là ĐANG THIẾU (giá trị sống nhưng SKU đã bị xoá).
                    // Không có đường nào từ câu đó tới hành động đúng, nên khách
                    // của Betoya đã bịa tên `翠ジン -` để lách qua: một giá trị
                    // rác + một SKU ¥0 trên production là cái giá của thông điệp
                    // này.
                    $label = trim((string) ($row['label'] ?? '')) !== '' ? $row['label'] : $slug;
                    throw ValidationException::withMessages([
                        'values' => ["A value named '{$label}' already exists for this option."],
                    ]);
                }

                if (! empty($row['new_id']) && ProductOptionValue::withTrashed()->whereKey($row['new_id'])->exists()) {
                    throw ValidationException::withMessages([
                        'values' => ['The requested option value ID is already in use.'],
                    ]);
                }

                $newValue = new ProductOptionValue;
                $newValue->fill([
                    'option_id' => $option->id,
                    'value' => $slug,
                    'label' => $row['label'],
                    'position' => $position,
                    'is_active' => true,
                ]);
                if (! empty($row['new_id'])) {
                    $newValue->forceFill(['id' => $row['new_id']]);
                }
                $newValue->save();
            }

            // 4. Generate missing SKU combinations so new values surface as
            //    SKUs immediately. No-op when nothing was added.
            $product = $option->product()->firstOrFail();
            $createdSkus = $skuService->generateMissingCombinations($product);

            return [
                'option' => $option->fresh('values'),
                'created_skus' => $createdSkus,
                'blocking_removals' => [],
            ];
        });
    }

    /**
     * Same FK-check as ProductOptionValueService::blockingSkusFor but operates
     * on a known option to avoid an extra loadMissing per call.
     *
     * @return array<int, array{id: string, sku: string}>
     */
    private function blockingSkusForValue(ProductOptionValue $value, ProductOption $option): array
    {
        $fkColumn = "option_value{$option->position}_id";

        return ProductSku::where('product_id', $option->product_id)
            ->where($fkColumn, $value->id)
            ->select(['id', 'sku'])
            ->get()
            ->map(fn (ProductSku $sku) => ['id' => $sku->id, 'sku' => $sku->sku])
            ->all();
    }

    public function delete(ProductOption $option): bool
    {
        $blockingSkus = $this->blockingSkusFor($option);

        if (! empty($blockingSkus)) {
            throw new OptionInUseException(
                "Cannot delete option '{$option->key}': it is referenced by existing SKUs. "
                    .'Remove or reassign those SKUs first.',
                $blockingSkus,
            );
        }

        return DB::transaction(function () use ($option) {
            $option->values()->delete();

            return $option->delete();
        });
    }

    // =========================================================================
    //  Expand: add a new option to a product that already has SKUs
    // =========================================================================

    /**
     * Add a new option (with values) to a product and update existing SKUs so
     * they remain consistent. Existing SKUs get the chosen default value
     * assigned for the new option position, then missing combinations are
     * generated via {@see ProductSkuService::generateMissingCombinations()}.
     *
     * @param  array{
     *     product_id: string,
     *     key: string,
     *     name: string,
     *     position: int,
     *     is_active?: bool,
     *     values: array<int, array{value: string, label: string}>,
     *     default_value_index: int,
     *     generate_combinations?: bool,
     * }  $data
     * @return array{option: ProductOption, updated_skus: int, created_skus: \Illuminate\Support\Collection}
     */
    public function expandOption(array $data, ProductSkuService $skuService): array
    {
        return DB::transaction(function () use ($data, $skuService) {
            $productId = $data['product_id'];
            $position = (int) $data['position'];

            // 1. Validate position is available
            $this->validatePosition($productId, $position);
            $this->validateKeyUnique($productId, $data['key']);

            // 2. Create the option
            $option = new ProductOption;
            $option->fill([
                'product_id' => $productId,
                'key' => $data['key'],
                'name' => $data['name'],
                'position' => $position,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $option->forceFill(['id' => $data['id']]);
            $option->save();

            // 3. Create option values
            $values = [];
            foreach ($data['values'] as $i => $valueData) {
                $value = new ProductOptionValue;
                $value->fill([
                    'option_id' => $option->id,
                    'value' => $valueData['value'],
                    'label' => $valueData['label'],
                    'position' => $i + 1,
                    'is_active' => true,
                ]);
                $value->forceFill(['id' => $valueData['id']]);
                $value->save();
                $values[] = $value;
            }

            // 4. Assign default value to existing SKUs.
            //    Multiple SKUs may share the same "remaining" option values
            //    (e.g. two default SKUs with all NULLs) — after adding the
            //    default value they'd have identical signatures. We process
            //    row-by-row and deactivate duplicates to avoid unique
            //    constraint violations.
            $defaultValue = $values[$data['default_value_index']];
            $fkColumn = "option_value{$position}_id";

            $skusToUpdate = ProductSku::where('product_id', $productId)
                ->whereNull($fkColumn)
                ->get();

            $updatedCount = 0;
            $seenSignatures = ProductSku::withTrashed()
                ->where('product_id', $productId)
                ->whereNotNull($fkColumn)
                ->pluck('option_signature')
                ->flip()
                ->all();

            foreach ($skusToUpdate as $sku) {
                $sku->{$fkColumn} = $defaultValue->id;
                $newSignature = ProductSku::computeOptionSignature(
                    $sku->option_value1_id,
                    $sku->option_value2_id,
                    $sku->option_value3_id,
                );

                if (isset($seenSignatures[$newSignature])) {
                    // Duplicate — deactivate to preserve FK references
                    $sku->is_active = false;
                    $sku->option_signature = $newSignature.'__dup_'.substr($sku->id, -8);
                    $sku->saveQuietly();

                    continue;
                }

                $seenSignatures[$newSignature] = true;
                $sku->option_signature = $newSignature;
                $sku->saveQuietly();
                $updatedCount++;
            }

            // 5. Generate missing combinations if requested (default: true)
            $createdSkus = collect();
            if ($data['generate_combinations'] ?? true) {
                $product = Product::findOrFail($productId);
                $createdSkus = $skuService->generateMissingCombinations($product);
            }

            return [
                'option' => $option->load('values'),
                'updated_skus' => $updatedCount,
                'created_skus' => $createdSkus,
            ];
        });
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Validate that position is 1-3 and not already taken by another option.
     */
    private function validatePosition(string $productId, int $position, ?string $excludeId = null): void
    {
        if ($position < 1 || $position > 3) {
            throw ValidationException::withMessages([
                'position' => "Position must be 1, 2, or 3 (got {$position}).",
            ]);
        }

        $query = ProductOption::where('product_id', $productId)
            ->where('position', $position);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'position' => "Position {$position} is already taken by another option for this product.",
            ]);
        }
    }

    /**
     * Validate that the option key is unique within the product.
     */
    private function validateKeyUnique(string $productId, string $key, ?string $excludeId = null): void
    {
        $query = ProductOption::where('product_id', $productId)
            ->where('key', $key);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'key' => "Option key '{$key}' is already used by another option for this product.",
            ]);
        }
    }

    /**
     * Check if any active (non-trashed) SKU references values from this option.
     */
    private function optionHasSkuReferences(ProductOption $option): bool
    {
        return ! empty($this->blockingSkusFor($option));
    }

    /**
     * Return the SKUs that block deleting this option (id + sku code).
     *
     * @return array<int, array{id: string, sku: string}>
     */
    private function blockingSkusFor(ProductOption $option): array
    {
        $valueIds = $option->values()->pluck('id');

        if ($valueIds->isEmpty()) {
            return [];
        }

        $fkColumn = "option_value{$option->position}_id";

        return ProductSku::where('product_id', $option->product_id)
            ->whereIn($fkColumn, $valueIds)
            ->select(['id', 'sku'])
            ->get()
            ->map(fn (ProductSku $sku) => ['id' => $sku->id, 'sku' => $sku->sku])
            ->all();
    }
}
