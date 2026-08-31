<?php

namespace App\Models;

use App\Casts\RebaseStorageUrl;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model implements TranslatableContract
{
    use HasFactory, HasUuids, SoftDeletes;
    use Translatable {
        fill as private translatableFill;
        attributesToArray as private translatableAttributesToArray;
    }

    /** @var list<string> */
    public $translatedAttributes = ['name'];

    protected $useTranslationFallback = true;

    /**
     * Keep the legacy branches.name column populated while Astrotomic writes
     * locale rows. Platform directory sync and several operational queries
     * still read the base column directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        $baseName = $attributes['name'] ?? null;
        if (! is_string($baseName) || trim($baseName) === '') {
            foreach (['ja', 'en', 'vi'] as $locale) {
                $candidate = $attributes[$locale]['name'] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $baseName = $candidate;
                    break;
                }
            }
        }

        $this->translatableFill($attributes);

        if (is_string($baseName) && trim($baseName) !== '') {
            $this->attributes['name'] = $baseName;
        }

        return $this;
    }

    /**
     * Read-path counterpart to fill() above. Astrotomic's attributesToArray()
     * ALWAYS overwrites translatedAttributes with getAttributeOrFallback(),
     * which resolves ONLY through the `translations` relation and returns
     * null when that relation has zero rows — even though fill() above just
     * guaranteed the base `name` column holds a value. A branch pulled by
     * workstation before HQ ever filled in translations used to serialize
     * name=null in the API response, so PullBranch's `if br.Name != ""`
     * guard silently skipped writing workstation_branch_name and every
     * printed slip fell back to the hardcoded "Store" label.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $array = $this->translatableAttributesToArray();

        if (($array['name'] ?? null) === null) {
            $base = $this->getRawOriginal('name');
            if (is_string($base) && trim($base) !== '') {
                $array['name'] = $base;
            }
        }

        return $array;
    }

    protected $fillable = [
        'console_branch_id',
        'console_organization_id',
        'console_brand_id',
        'code',
        'slug',
        'name',
        'is_headquarters',
        'is_active',
        'timezone',
        'currency',
        'locale',
        'invoice_registration_number',
        'address',
        'phone',
        'img_branches',
        // #936 — per-breakpoint storefront banners. `img_branches` stays the
        // legacy/fallback banner; these three win when set.
        'banner_desktop',
        'banner_tablet',
        'banner_mobile',
        // #1673 — công tắc kế thừa FAQ của HQ.
        //
        // Phải khai Ở ĐÂY dù `BranchBaseModel` đã có: model này khai đè
        // `$fillable` và `casts()` mà KHÔNG gọi parent, nên mọi cột mới do
        // omnify sinh ra đều bị che. Che một cách im lặng — `update()` không
        // báo lỗi, nó chỉ lặng lẽ không ghi gì.
        'faq_inherit_hq',
        'logo',
        'seat_capacity',
        'business_hours',
        'weekly_hours',
        'cart_timeout_minutes',
        'takeaway_payment_timeout_minutes',
        // #1674 — ghi đè tỉ lệ tích điểm cho chi nhánh; null = kế thừa brand.
        'point_earn_amount',
        'point_earn_points',
        'review_avg_rating',
        'review_total_count',
    ];

    /**
     * #936 — the per-breakpoint storefront banners, ready to spread into any
     * API payload. Values are the raw (rebased) URLs, NOT fallback-resolved:
     * clients render them through `<picture><source media>` and fall back
     * themselves (mobile → tablet → desktop → img_branches), which is the only
     * way a single response can serve every viewport.
     *
     * @return array{banner_desktop: ?string, banner_tablet: ?string, banner_mobile: ?string}
     */
    public function bannerUrls(): array
    {
        return [
            'banner_desktop' => $this->banner_desktop,
            'banner_tablet' => $this->banner_tablet,
            'banner_mobile' => $this->banner_mobile,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'console_brand_id', 'console_brand_id');
    }

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'is_active' => 'boolean',
            // #1673 — thiếu dòng này thì đọc ra int 1/0 chứ không phải bool,
            // và mọi so sánh `=== true` ở tầng trên đều sai lặng lẽ.
            'faq_inherit_hq' => 'boolean',
            'seat_capacity' => 'integer',
            'weekly_hours' => 'array',
            'takeaway_payment_timeout_minutes' => 'integer',
            'point_earn_amount' => 'decimal:2',
            'point_earn_points' => 'integer',
            'logo' => RebaseStorageUrl::class,
            'img_branches' => RebaseStorageUrl::class,
            'banner_desktop' => RebaseStorageUrl::class,
            'banner_tablet' => RebaseStorageUrl::class,
            'banner_mobile' => RebaseStorageUrl::class,
        ];
    }
}
