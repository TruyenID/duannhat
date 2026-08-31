<?php

namespace App\Services\Promotion;

use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Omnify\Enums\CouponDiscountTypeEnum;
use App\Omnify\Enums\CouponStatusEnum;
use App\Omnify\Modules\Coupon\Services\CouponServiceBase;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Promotion\Contracts\CouponPricing;
use App\Support\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Plan-019 — CouponService.
 *
 * Owns the lifecycle of brand-scoped, code-driven, order-level coupons:
 *  - HQ CRUD + pause/resume.
 *  - Apply / release / preview at the shop POS + customer-web checkout.
 *  - Atomic counter via lockForUpdate (Decision 1) — DB CHECK constraint
 *    was a defensive add we ended up skipping (see plan-019 NOTES.md).
 *  - Status is partly stored (draft / paused) and partly DERIVED at read
 *    time (scheduled / active / expired / exhausted) by computeStatus()
 *    so the system never needs a transition cron (Decision 3).
 *
 * Stacking integration: apply() rejects if the cart already has at least
 * one item under a `MenuPromotion.stacking_mode = exclusive_with_coupons`
 * (Decision B5). The matching reverse guard lives in
 * CustomerOrderService::addItems (T2.6).
 */
class CouponService extends CouponServiceBase implements CouponPricing
{
    // =========================================================================
    //  Eager loads
    // =========================================================================

    protected function applyListEagerLoads($query): void
    {
        $query->with(['translations', 'branches:id,name'])
            ->withCount(['redemptions as active_redemptions_count' => function ($q) {
                $q->whereNull('released_at');
            }]);
    }

    protected function applyFindByIdEagerLoads($query): void
    {
        $query->with(['translations', 'branches:id,name'])
            ->withCount(['redemptions as active_redemptions_count' => function ($q) {
                $q->whereNull('released_at');
            }]);
    }

    // =========================================================================
    //  HQ CRUD overrides
    // =========================================================================

    /**
     * @param  array{
     *     organization_id?: string,
     *     brand_id?: string,
     *     branch_id?: string,
     *     status?: string,
     *     discount_type?: string,
     *     search?: string,
     *     valid_at?: string,
     *     sort?: string,
     *     per_page?: int,
     *     with_trashed?: bool,
     * }  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Coupon::query()
            ->with(['translations', 'branches:id,name'])
            ->withCount(['redemptions as active_redemptions_count' => function ($q) {
                $q->whereNull('released_at');
            }]);

        // #1441 — coupon cá nhân (mint mỗi lượt khách đổi điểm) mặc định KHÔNG
        // nằm trong danh sách quản trị. Chúng không phải chiến dịch của HQ,
        // không sửa được, và sinh ra theo số lượt đổi — để lẫn vào đây thì
        // vài tuần sau màn coupon của HQ chỉ còn là nhật ký đổi điểm.
        // `include_point_rewards` để tra cứu khi cần đối soát.
        if (empty($filters['include_point_rewards'])) {
            // #1700 — một ngoại lệ hẹp cho ĐÚNG NGUYÊN MÃ. Tình huống thật là
            // khách đọc "PTWMYSBM7V" qua điện thoại và nhân viên cần biết tấm
            // đó có thật, còn hạn, đã tiêu chưa. Gõ đủ cả mã thì đã biết mình
            // tìm cái gì; cái mà #1441 cấm là để chúng TRÔI vào danh sách,
            // không phải cấm tra cứu.
            //
            // Chỉ khớp tuyệt đối, không `like`: một mảnh như "PT" mà cũng kéo
            // coupon cá nhân vào thì đúng bằng việc bỏ bộ lọc đi.
            $exactCode = $this->exactCodeTerm($filters['search'] ?? null);

            $query->where(function ($q) use ($exactCode) {
                $q->whereNull('point_reward_id');

                if ($exactCode !== null) {
                    $q->orWhere('code', $exactCode);
                }
            });
        }

        $query->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id));
        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        $query->when($filters['discount_type'] ?? null, fn ($q, $v) => $q->where('discount_type', $v));

        // Branch eligibility: brand-wide (no pivot rows) OR pivot includes branch_id.
        $query->when($filters['branch_id'] ?? null, function ($q, $branchId) {
            $q->where(function ($q) use ($branchId) {
                $q->whereDoesntHave('branches')
                    ->orWhereHas('branches', fn ($bq) => $bq->where('branches.id', $branchId));
            });
        });

        // Storable status filter
        $query->when(in_array($filters['status'] ?? null, ['draft', 'paused'], true), function ($q) use ($filters) {
            $q->where('status', $filters['status']);
        });

        // Derived status filter (scheduled / active / expired / exhausted)
        $now = $filters['valid_at'] ?? now();
        $query->when($filters['status'] ?? null, function ($q, $status) use ($now) {
            match ($status) {
                'scheduled' => $q->where('status', '!=', CouponStatusEnum::Paused->value)->where('valid_from', '>', $now),
                'expired' => $q->where('valid_until', '<', $now),
                'exhausted' => $q->whereNotNull('usage_limit_total')
                    ->whereColumn('times_used', '>=', 'usage_limit_total'),
                'active' => $q->where('status', '!=', CouponStatusEnum::Paused->value)
                    ->where('valid_from', '<=', $now)
                    ->where('valid_until', '>=', $now)
                    ->where(function ($q) {
                        $q->whereNull('usage_limit_total')->orWhereColumn('times_used', '<', 'usage_limit_total');
                    }),
                default => null,
            };
        });

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('code', 'like', '%'.strtoupper($search).'%')
                    ->orWhereTranslationLike('name', "%{$search}%");
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowed = ['code', 'created_at', 'updated_at', 'discount_type', 'status', 'valid_from', 'valid_until', 'times_used'];
        $column = in_array($column, $allowed, true) ? $column : 'created_at';
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    /**
     * Từ khoá tìm kiếm có phải là MỘT MÃ ĐẦY ĐỦ không.
     *
     * Bảo thủ có chủ ý: chỉ chữ-số-gạch, tối thiểu 4 ký tự. Mã cá nhân là
     * `PT` + 8 ký tự nên luôn qua; còn "bia", "giảm 10%" hay một mảnh hai ký
     * tự thì không, và danh sách chiến dịch của HQ giữ nguyên như trước.
     */
    private function exactCodeTerm(?string $search): ?string
    {
        $term = strtoupper(trim((string) $search));

        return preg_match('/^[A-Z0-9_-]{4,}$/', $term) === 1 ? $term : null;
    }

    public function create(array $data): Coupon
    {
        $branchIds = $data['applicable_branch_ids'] ?? [];
        unset($data['applicable_branch_ids']);
        $data['code'] = strtoupper((string) $data['code']);

        $coupon = parent::create($data);

        if ($branchIds !== []) {
            $coupon->branches()->sync($branchIds);
        }
        $coupon->logAudit('coupon_created', ['code' => $coupon->code]);

        return $coupon->fresh(['translations', 'branches']);
    }

    public function update(Coupon $coupon, array $data): Coupon
    {
        $this->assertNotLocked($coupon, $data);

        $branchIds = $data['applicable_branch_ids'] ?? null;
        unset($data['applicable_branch_ids']);

        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);
        }

        $updated = parent::update($coupon, $data);

        if ($branchIds !== null) {
            $updated->branches()->sync($branchIds);
        }
        $updated->logAudit('coupon_updated', ['code' => $updated->code]);

        return $updated->fresh(['translations', 'branches']);
    }

    public function delete(Coupon $coupon): bool
    {
        if ($coupon->times_used > 0) {
            throw CouponException::alreadyRedeemed();
        }

        return DB::transaction(function () use ($coupon) {
            $result = $coupon->delete();
            $coupon->logAudit('coupon_deleted', ['code' => $coupon->code]);

            return $result;
        });
    }

    public function restore(Coupon $coupon): Coupon
    {
        $coupon->restore();
        $coupon->logAudit('coupon_restored', ['code' => $coupon->code]);

        return $coupon->fresh(['translations', 'branches']);
    }

    public function pause(Coupon $coupon): Coupon
    {
        return DB::transaction(function () use ($coupon) {
            $coupon->update(['status' => CouponStatusEnum::Paused->value]);
            $coupon->logAudit('coupon_paused');

            return $coupon->fresh(['translations', 'branches']);
        });
    }

    public function resume(Coupon $coupon): Coupon
    {
        return DB::transaction(function () use ($coupon) {
            $coupon->update(['status' => CouponStatusEnum::Draft->value]);
            $coupon->logAudit('coupon_resumed');

            return $coupon->fresh(['translations', 'branches']);
        });
    }

    /**
     * Paginated redemption history for a coupon (HQ detail page).
     *
     * Eager-loads the customer + customerOrder relations so the resource
     * layer can surface `customer_name` and `order_code` as flat fields
     * without an N+1. Both released and active rows are returned — the
     * UI tab is an audit history view, callers filter client-side.
     */
    public function listRedemptions(Coupon $coupon, int $perPage = 25): LengthAwarePaginator
    {
        return CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->with(['customer:id,first_name,last_name,phone', 'customerOrder:id,order_code'])
            ->orderByDesc('redeemed_at')
            ->paginate($perPage);
    }

    // =========================================================================
    //  Apply / Release / Preview
    // =========================================================================

    /**
     * Read-only validation pass. Mirrors apply() but never locks, never
     * increments, never inserts. Returns a structured array describing
     * is_valid / discount / error_code.
     *
     * @return array{
     *     is_valid: bool,
     *     code?: string,
     *     name?: string,
     *     discount_type?: string,
     *     discount_applied_amount?: float,
     *     error_code?: string,
     *     meta?: array<string, mixed>,
     * }
     */
    public function preview(
        string $code,
        string $brandId,
        string $branchId,
        ?string $customerId,
        float $subtotal,
    ): array {
        $coupon = Coupon::where('brand_id', $brandId)
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->first();

        if ($coupon === null) {
            return ['is_valid' => false, 'error_code' => 'coupon_not_found'];
        }

        $now = CarbonImmutable::now();

        try {
            // Reuse the same validation chain. apply() needs a CustomerOrder
            // for `branch_id`/`subtotal`/per-customer count; preview emulates
            // by passing those through manually.
            $this->validatePreview($coupon, $branchId, $subtotal, $customerId, $now);
        } catch (CouponException $e) {
            return [
                'is_valid' => false,
                'error_code' => $e->errorCode,
                'meta' => $e->meta,
            ];
        }

        return [
            'is_valid' => true,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type,
            'discount_applied_amount' => $this->computeDiscount(
                $coupon,
                $subtotal,
                $this->resolveCurrencyForBranch($branchId),
            ),
        ];
    }

    // =========================================================================
    //  Computations
    // =========================================================================

    /**
     * Derive the display status (draft / paused / scheduled / active /
     * expired / exhausted) from the storable column + lifecycle inputs.
     */
    public function computeStatus(Coupon $coupon, ?DateTimeInterface $at = null): string
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $status = $this->statusValue($coupon);

        // Paused short-circuits — admin explicitly hid the coupon.
        if ($status === CouponStatusEnum::Paused->value) {
            return CouponStatusEnum::Paused->value;
        }

        // Every other storable value falls through to time + counter
        // derivation. DESIGN.md endpoint #7 (resume) requires this:
        // resume sets status=draft and "computed_status sẽ trở về
        // active/scheduled/expired/exhausted tùy điều kiện thời gian
        // + counter". The CouponStatus.yaml comment said storable=draft
        // always returned 'draft' — that was wrong; this implementation
        // matches the endpoint contract.
        if ($at->lt($coupon->valid_from)) {
            return 'scheduled';
        }
        if ($at->gt($coupon->valid_until)) {
            return 'expired';
        }
        if ($coupon->usage_limit_total !== null && $coupon->times_used >= $coupon->usage_limit_total) {
            return 'exhausted';
        }

        return 'active';
    }

    public function statusValue(Coupon $coupon): string
    {
        $value = $coupon->status;

        return $value instanceof CouponStatusEnum ? $value->value : (string) $value;
    }

    /**
     * Compute the discount this coupon would apply to a given subtotal.
     * fixed → min(value, subtotal). percent → min(subtotal * value/100,
     * cap ?? infinity). Round half-up to 2 decimals.
     */
    public function computeDiscount(Coupon $coupon, float $subtotal, ?string $currencyCode = null): float
    {
        // Zero-decimal currencies (JPY, VND, KRW, …) have no fractional units,
        // so discounts must be whole units. Round to zero decimals — FE shows
        // e.g. ¥535, BE must agree or the customer / admin views drift
        // (BE 534.70 vs FE 535). The currency comes from the order's
        // ShopOrderSetting (multi-tenant), NOT a global Stripe config —
        // otherwise a VND shop would round to 2 decimals and drift by the sub-unit.
        $decimals = $this->isZeroDecimalCurrency($currencyCode) ? 0 : 2;

        if ($this->discountTypeValue($coupon) === CouponDiscountTypeEnum::Fixed->value) {
            return round(min((float) $coupon->discount_value, $subtotal), $decimals);
        }

        $raw = $subtotal * ((float) $coupon->discount_value) / 100;
        if ($coupon->max_discount_cap !== null) {
            $raw = min($raw, (float) $coupon->max_discount_cap);
        }

        return round($raw, $decimals);
    }

    /**
     * Zero-decimal currencies (JPY, VND, KRW, …) charge in whole units.
     * Resolved against the ORDER's currency via the shared RoundingMode
     * canonical set (step >= 1 ⇒ integer-only currency), never a global
     * Stripe config — so each tenant rounds coupon discounts to its own
     * currency's minor unit. Null falls back to VND (project default),
     * which is itself zero-decimal.
     */
    private function isZeroDecimalCurrency(?string $currencyCode): bool
    {
        return RoundingMode::step('auto', $currencyCode) >= 1.0;
    }

    /**
     * Resolve the ISO 4217 currency for an order from its branch's
     * ShopOrderSetting. Null when the branch has no setting row — callers
     * pass this straight into {@see computeDiscount}, which falls back to
     * the project-default VND.
     */
    public function resolveCurrencyForBranch(?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        return app(BranchCurrency::class)->codeFor($branchId);
    }

    /**
     * Coupon::discount_type can come back as either a CouponDiscountTypeEnum
     * (when the row is loaded via Eloquent and the cast fires) or a raw
     * string (when constructed directly via `new Coupon([...])` for a unit
     * test before save). Normalise to the enum's `.value`.
     */
    private function discountTypeValue(Coupon $coupon): string
    {
        $value = $coupon->discount_type;

        return $value instanceof CouponDiscountTypeEnum ? $value->value : (string) $value;
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    protected function validatePreview(Coupon $coupon, string $branchId, float $subtotal, ?string $customerId, CarbonImmutable $now): void
    {
        if ($this->statusValue($coupon) === CouponStatusEnum::Paused->value) {
            throw CouponException::paused();
        }
        if ($now->lt($coupon->valid_from)) {
            throw CouponException::notStarted($coupon->valid_from);
        }
        if ($now->gt($coupon->valid_until)) {
            throw CouponException::expired($coupon->valid_until);
        }
        if ($subtotal < (float) $coupon->min_order_subtotal) {
            throw CouponException::minSubtotalNotMet((float) $coupon->min_order_subtotal);
        }
        if ($coupon->usage_limit_total !== null && $coupon->times_used >= $coupon->usage_limit_total) {
            throw CouponException::exhausted();
        }

        $this->assertBranchEligible($coupon, $branchId);
        $this->assertCustomerEligible($coupon, $customerId);
    }

    public function assertBranchEligible(Coupon $coupon, string $branchId): void
    {
        $hasWhitelist = $coupon->branches()->exists();
        if (! $hasWhitelist) {
            return;
        }
        if (! $coupon->branches()->where('branches.id', $branchId)->exists()) {
            throw CouponException::branchNotEligible();
        }
    }

    public function assertCustomerEligible(Coupon $coupon, ?string $customerId): void
    {
        // #1441 — coupon CÁ NHÂN (mint khi khách đổi điểm) chỉ đúng chủ dùng
        // được. Kiểm trước mọi thứ khác, kể cả trước nhánh
        // `usage_limit_per_customer === 0` bên dưới: quyền sở hữu không phải
        // là một biến thể của hạn mức sử dụng, và một coupon cá nhân bị đặt
        // nhầm hạn mức 0 vẫn không được phép rơi thành vé vô danh.
        if ($coupon->customer_id !== null) {
            if ($customerId === null) {
                throw CouponException::customerRequired();
            }
            if ($coupon->customer_id !== $customerId) {
                throw CouponException::notOwnedByCustomer();
            }
        }

        if ($coupon->usage_limit_per_customer === 0) {
            return;
        }
        if ($customerId === null) {
            throw CouponException::customerRequired();
        }

        $usedCount = CouponRedemption::where('coupon_id', $coupon->id)
            ->where('customer_id', $customerId)
            ->whereNull('released_at')
            ->count();

        if ($usedCount >= $coupon->usage_limit_per_customer) {
            throw CouponException::alreadyUsedByCustomer();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotCoupon(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'name' => $coupon->getTranslationsArray('name'),
            'discount_type' => $coupon->discount_type,
            'discount_value' => (string) $coupon->discount_value,
            'max_discount_cap' => $coupon->max_discount_cap !== null ? (string) $coupon->max_discount_cap : null,
            'min_order_subtotal' => (string) $coupon->min_order_subtotal,
        ];
    }

    /**
     * BR-COUP-LOCK — once a coupon has been redeemed, the immutable
     * fields can no longer be edited. The remaining whitelist is
     * documented in DESIGN.md endpoint #4.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertNotLocked(Coupon $coupon, array $data): void
    {
        if ($coupon->times_used === 0) {
            return;
        }

        $locked = ['code', 'discount_type', 'discount_value', 'max_discount_cap', 'min_order_subtotal', 'usage_limit_per_customer'];
        foreach ($locked as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $coupon->{$field}) {
                throw CouponException::lockedField($field);
            }
        }

        // usage_limit_total: only allowed to INCREASE.
        if (array_key_exists('usage_limit_total', $data)) {
            $new = $data['usage_limit_total'];
            $current = $coupon->usage_limit_total;
            $isShrink = ($current !== null && ($new === null || (int) $new < (int) $current));
            if ($isShrink) {
                throw CouponException::lockedField('usage_limit_total');
            }
        }

        // valid_until: only allowed to extend (>=).
        if (array_key_exists('valid_until', $data)) {
            $new = CarbonImmutable::parse((string) $data['valid_until']);
            if ($new->lt($coupon->valid_until)) {
                throw CouponException::lockedField('valid_until');
            }
        }
    }
}
