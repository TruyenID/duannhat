<?php

/**
 * Product Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Concerns\HasApprovalWorkflow;
use App\Contracts\Approvable;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\ProductStatusEnum;
use App\Omnify\Modules\Product\Models\ProductBaseModel;
use App\Omnify\Traits\HasFiles;
use App\Traits\AuditsActivity;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Product — add project-specific model logic here.
 */
class Product extends ProductBaseModel implements Approvable
{
    use AuditsActivity;
    use HasApprovalWorkflow;
    use HasFactory;
    use HasFiles;
    use PreservesTranslatableColumns;

    /**
     * Enable Astrotomic fallback: missing translation → fallback_locale (en)
     * → base column (`products.name` / `products.description`). ProductService
     * populates the base column from ja → en → vi priority on write, so the
     * last-resort property fallback always resolves to a sensible non-null
     * value even if the user entered only one language.
     *
     * Pair with config('translatable.use_property_fallback') = true.
     */
    protected $useTranslationFallback = true;

    /**
     * Project ProductStatusEnum (which carries lifecycle extras like
     * `active`/`inactive`) onto the normalized 4-state ApprovalStatusEnum
     * required by the HasApprovalWorkflow trait.
     *
     * `active` and `inactive` both project onto `Approved` because both are
     * post-approval lifecycle states — the trait only governs the path
     * up to and including approval; lifecycle is ProductService's concern.
     */
    public function getApprovalStatus(): ApprovalStatusEnum
    {
        $status = $this->status instanceof ProductStatusEnum
            ? $this->status
            : ProductStatusEnum::from($this->status);

        return match ($status) {
            ProductStatusEnum::Draft => ApprovalStatusEnum::Draft,
            ProductStatusEnum::Pending => ApprovalStatusEnum::Pending,
            ProductStatusEnum::Approved,
            ProductStatusEnum::Active,
            ProductStatusEnum::Inactive => ApprovalStatusEnum::Approved,
            ProductStatusEnum::Rejected => ApprovalStatusEnum::Rejected,
        };
    }

    /**
     * Map the normalized approval state back onto Product's own status enum.
     * Lifecycle transitions (`active`, `inactive`) bypass the trait and stay
     * in ProductService::activate / ::deactivate.
     */
    public function setApprovalStatus(ApprovalStatusEnum $status): void
    {
        $this->status = match ($status) {
            ApprovalStatusEnum::Draft => ProductStatusEnum::Draft->value,
            ApprovalStatusEnum::Pending => ProductStatusEnum::Pending->value,
            ApprovalStatusEnum::Approved => ProductStatusEnum::Approved->value,
            ApprovalStatusEnum::Rejected => ProductStatusEnum::Rejected->value,
        };
    }

    public function toppingGroups(): BelongsToMany
    {
        return $this->belongsToMany(ToppingGroup::class, 'product_topping_groups')
            ->withPivot('sort_order', 'min_select_override', 'max_select_override')
            ->withTimestamps();
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * Resolve a non-empty localized name. Works around translatable config
     * `use_fallback => false` (which makes `->name` return empty when the
     * requested locale has no translation): try the requested locale, then
     * vi → ja → en, then ANY translation with a value, then the base column.
     *
     * Returns null only when the product has no name in any locale — a source
     * data error no resolver can fix (audit + seed). Callers add their own
     * last-resort guard (SKU code / '(unknown)').
     */
    public function localizedName(?string $locale = null): ?string
    {
        $locale = $locale ?: app()->getLocale();

        foreach (array_values(array_unique([$locale, 'vi', 'ja', 'en'])) as $loc) {
            $value = $this->translate($loc)?->name;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        foreach ($this->translations as $translation) {
            if (is_string($translation->name) && trim($translation->name) !== '') {
                return $translation->name;
            }
        }

        $base = $this->getRawOriginal('name');

        return (is_string($base) && trim($base) !== '') ? $base : null;
    }

    /**
     * The latest file in the `thumbnail` collection (if any).
     *
     * Use `ofMany(...)` with the collection filter inside the subquery
     * callback so that `MAX(id)` is computed only across thumbnail files.
     * The naive `->where('collection', 'thumbnail')->latestOfMany()` form
     * runs the aggregate over *all* files attached to the product and only
     * applies the collection filter to the outer join, which returns null
     * whenever another collection (e.g. gallery) has a newer file.
     */
    public function thumbnail(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable')
            ->ofMany(['id' => 'max'], fn ($q) => $q->where('collection', 'thumbnail'));
    }

    /**
     * All files in the `gallery` collection, ordered by sort_order.
     */
    public function gallery(): MorphMany
    {
        return $this->files()
            ->where('collection', 'gallery')
            ->orderBy('sort_order');
    }

    /**
     * The main gallery image (sort_order = 0).
     *
     * Lightweight MorphOne for list endpoints (menu items, order picker)
     * that need a thumbnail without loading the full gallery. The `ofMany`
     * subquery picks the row with the lowest sort_order in the `gallery`
     * collection so eager loading stays a single JOIN per N products.
     */
    public function galleryFirst(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable')
            ->ofMany(['sort_order' => 'min'], fn ($q) => $q->where('collection', 'gallery'));
    }

    /**
     * Plan-025 — "% khách khuyên dùng" cho thẻ sản phẩm.
     *
     * `null` khi CHƯA có đánh giá nào: vừa tránh chia cho 0, vừa tránh hiển thị
     * "0%" cho một sản phẩm chưa ai chấm — hai thứ đó nói ngược nhau với khách.
     *
     * #1596 — trú ở model chứ không ở `ProductReviewService` (CustomerEngagement).
     * `review_total_count` / `review_up_count` là CỘT của `products`, tức bảng
     * của Catalog, do Catalog ghi qua `App\Services\Product\Contracts\ProductReviewAggregates`;
     * phép suy ra tỉ lệ từ chính hai cột của mình không cần rời module. Trước đó
     * nó là một static thuần số học trên `ProductReviewService`, và lời gọi DUY
     * NHẤT trong `app/` là từ `CustomerMenuService` — nên khi service đó về
     * Catalog thì static kia thành cạnh Catalog → CustomerEngagement.
     *
     * Làm tròn `round()` (half away from zero) là hành vi được ghim ở
     * `CustomerReviewTest`: 1/8 = 12.5% phải ra 13, không phải 12.
     */
    public function recommendPercent(): ?int
    {
        $total = (int) ($this->review_total_count ?? 0);

        if ($total <= 0) {
            return null;
        }

        return (int) round((int) ($this->review_up_count ?? 0) / $total * 100);
    }
}
