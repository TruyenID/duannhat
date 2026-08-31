<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerPointEntry;
use App\Models\PointReward;
use App\Omnify\Enums\PointEntryKindEnum;
use App\Services\Loyalty\Exceptions\PointRedemptionException;
use App\Services\Loyalty\ValueObjects\PointableOrder;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Promotion\Contracts\MintedCoupon;
use App\Services\Promotion\Contracts\PersonalCouponMinting;
use App\Services\Promotion\Contracts\PersonalCouponSpec;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #1441 — sổ cái điểm khách hàng.
 *
 * ĐÂY LÀ ĐƯỜNG GHI DUY NHẤT vào `customer_point_entries`. Controller không
 * insert, listener không insert — mọi thứ đi qua đây, vì mọi bút toán đều có
 * ràng buộc mà một `->create()` rải rác không giữ nổi: dấu của `points` phải
 * khớp `kind`, tích điểm phải idempotent theo đơn, và tiêu điểm phải tuần tự
 * hoá theo từng khách.
 *
 * Số dư = SUM(points). Không có cột số dư nào để lệch — xem header của
 * `schemas/Backend/Loyalty/CustomerPointEntry.yaml`.
 */
class CustomerPointService
{
    public function enabled(): bool
    {
        return (bool) config('loyalty.enabled', true);
    }

    /**
     * Khách này có tham gia chương trình thành viên không (#1780).
     *
     * Đọc thẳng một cột thay vì nạp cả model: hàm chạy trên đường tiền, mỗi đơn
     * trả xong một lần.
     *
     * Khách KHÔNG tồn tại (id rác, khách vừa bị xoá cứng) trả `false` — không
     * tích điểm cho một hàng không có thật; `CustomerPointEntry.customer_id` có
     * FK nên insert cũng sẽ chết, chỉ là chết bằng 500 thay vì im lặng bỏ qua.
     */
    private function optedIn(string $customerId): bool
    {
        $value = Customer::query()
            ->whereKey($customerId)
            ->value('loyalty_opted_in');

        return $value !== null && (bool) $value;
    }

    // =====================================================================
    //  Đọc
    // =====================================================================

    /** Số dư khả dụng — cái khách tiêu được ngay. */
    public function balance(Customer $customer): int
    {
        return (int) CustomerPointEntry::query()
            ->where('customer_id', $customer->id)
            ->sum('points');
    }

    /**
     * Tổng điểm đã tích trong đời (dùng để xét hạng).
     *
     * Loại `redeem`/`expire` ra khỏi tổng: tiêu điểm KHÔNG được làm khách tụt
     * hạng — hạng là thước đo mức độ gắn bó, không phải số dư ví. Nhưng
     * `revoke` (hoàn tiền) thì có tính, vì doanh số đó đã bị trả lại thật.
     */
    public function lifetimeEarned(Customer $customer): int
    {
        return (int) CustomerPointEntry::query()
            ->where('customer_id', $customer->id)
            ->whereIn('kind', [
                PointEntryKindEnum::Earn->value,
                PointEntryKindEnum::Revoke->value,
                PointEntryKindEnum::Adjust->value,
            ])
            ->sum('points');
    }

    /**
     * Hạng hiện tại + hạng kế tiếp, suy ra từ `lifetimeEarned` và bảng mốc ở
     * `config('loyalty.tiers')`.
     *
     * @return array{current: array<string, mixed>, next: ?array<string, mixed>, lifetime_points: int, points_to_next: ?int}
     */
    public function tier(Customer $customer): array
    {
        $lifetime = $this->lifetimeEarned($customer);
        $tiers = collect(config('loyalty.tiers', []))
            ->sortBy('min_lifetime_points')
            ->values();

        $current = $tiers->last(fn ($t) => $lifetime >= (int) ($t['min_lifetime_points'] ?? 0))
            ?? $tiers->first();
        $next = $tiers->first(fn ($t) => (int) ($t['min_lifetime_points'] ?? 0) > $lifetime);

        return [
            'current' => $current,
            'next' => $next,
            'lifetime_points' => $lifetime,
            'points_to_next' => $next === null
                ? null
                : max(0, (int) $next['min_lifetime_points'] - $lifetime),
        ];
    }

    /** Lịch sử bút toán, mới nhất trước. */
    public function history(Customer $customer, int $perPage = 20, int $page = 1): array
    {
        $paginator = CustomerPointEntry::query()
            ->where('customer_id', $customer->id)
            // #1700 — `times_used` + `valid_until` đi kèm để màn quản trị nói
            // được tấm coupon còn dùng được không. Customer-web chỉ đọc `code`
            // nên phần thêm này không đổi gì bên đó.
            //
            // #1713 — `withTrashed()` trên phần thưởng, CÓ CHỦ Ý cho cả hai màn.
            // `PointReward` xoá mềm, `pointReward()` là belongsTo trần, nên
            // thiếu dòng này thì HQ gỡ một phần thưởng là mọi dòng "đổi thưởng"
            // cũ mất tên — kể cả trong sổ của chính khách. Sổ ghi một việc ĐÃ
            // xảy ra; xoá món khỏi catalog không xoá được việc khách từng đổi
            // nó, và một ô trống thì tệ hơn hẳn cái tên đã bị gỡ.
            //
            // Đây cũng là điều `PointRewardService::redemptionLog()` đã làm từ
            // #1700 — trước khi sửa, hai màn quản trị nói hai kiểu về cùng một
            // sự kiện.
            ->with([
                'customerOrder:id,order_code',
                'pointReward' => fn ($q) => $q->withTrashed()->with('translations'),
                'coupon:id,code,times_used,valid_until',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: min($perPage, 50), page: $page);

        return [
            'data' => $paginator->getCollection(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Catalog đổi điểm đang bật.
     *
     * KHÔNG lọc theo số dư — FE tự làm mờ món chưa đủ điểm. Cũng KHÔNG lọc
     * theo tồn kho: hết hàng vẫn trả về kèm cờ để thẻ hiện "Out of stock"
     * (BR-PR05). Ẩn hẳn thì khách tưởng phần thưởng biến mất.
     *
     * `$branchId` loại những phần thưởng mà chi nhánh đó đã tự tắt. Điều kiện
     * phải là `whereDoesntHave` chứ không phải join: không có dòng pivot nghĩa
     * là CÒN BẬT (BR-PRB01), nên một inner join sẽ làm mọi phần thưởng biến
     * mất ở mọi chi nhánh chưa từng tắt gì — tức là gần như mọi nơi.
     */
    public function rewards(?string $brandId = null, ?string $branchId = null): Collection
    {
        return PointReward::query()
            ->with(['translations', 'image'])
            ->where('is_active', true)
            ->when($brandId, fn ($q, $id) => $q->where('brand_id', $id))
            ->when($branchId, fn ($q, $id) => $q->whereDoesntHave(
                'branches',
                fn ($b) => $b->where('branches.id', $id)
                    ->where('point_reward_branches.is_available', false),
            ))
            ->orderBy('sort_order')
            ->orderBy('cost_points')
            ->get();
    }

    // =====================================================================
    //  Ghi
    // =====================================================================

    /**
     * Tích điểm cho một đơn đã trả tiền xong.
     *
     * Idempotent: unique index (customer_order_id, kind) là cái chốt thật, chứ
     * không phải câu `exists()` phía trên nó. `OrderPaid` bắn từ nhiều nguồn
     * (webhook Stripe + xác nhận đồng bộ của customer-web cùng đóng một đơn),
     * nên hai tiến trình có thể qua được `exists()` cùng lúc; cái thua cuộc ăn
     * lỗi unique và được nuốt ở đây — không nhân đôi điểm, không 500.
     */
    public function earnForOrder(PointableOrder $order): ?CustomerPointEntry
    {
        if (! $this->enabled() || $order->customerId === null) {
            return null;
        }

        if (! $this->optedIn($order->customerId)) {
            return null;
        }

        $points = $this->pointsForOrder($order);
        if ($points <= 0) {
            return null;
        }

        try {
            return CustomerPointEntry::create([
                'customer_id' => $order->customerId,
                'organization_id' => $order->organizationId,
                'customer_order_id' => $order->orderId,
                'kind' => PointEntryKindEnum::Earn->value,
                'points' => $points,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null; // đã tích rồi — đúng như mong đợi
            }
            throw $e;
        }
    }

    /**
     * Thu hồi điểm đã tích khi đơn bị hoàn/huỷ sau đó.
     *
     * Số điểm thu hồi lấy từ bút toán `earn` đã ghi, KHÔNG tính lại từ đơn:
     * đơn lúc này đã bị sửa (void món, hoàn tiền một phần) nên tính lại sẽ ra
     * một con số khác với cái đã cho khách.
     */
    public function revokeForOrder(PointableOrder $order): ?CustomerPointEntry
    {
        $earned = CustomerPointEntry::query()
            ->where('customer_order_id', $order->orderId)
            ->where('kind', PointEntryKindEnum::Earn->value)
            ->first();

        if ($earned === null || $earned->points <= 0) {
            return null;
        }

        try {
            return CustomerPointEntry::create([
                'customer_id' => $earned->customer_id,
                'organization_id' => $earned->organization_id,
                'customer_order_id' => $order->orderId,
                'kind' => PointEntryKindEnum::Revoke->value,
                'points' => -1 * (int) $earned->points,
                'note' => 'order '.($order->orderCode ?? $order->orderId),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null; // đã thu hồi rồi
            }
            throw $e;
        }
    }

    /**
     * Đổi điểm lấy phần thưởng → mint một Coupon CÁ NHÂN cho đúng khách này.
     *
     * Toàn bộ nằm trong một transaction có `lockForUpdate` trên hàng khách:
     * đó là thứ duy nhất ngăn hai tab bấm đổi cùng lúc tiêu quá số dư. Kiểm
     * số dư ngoài lock là kiểm một con số đã cũ.
     *
     * Phần thưởng CÓ GIỚI HẠN cần thêm một khoá thứ hai, trên chính hàng phần
     * thưởng: tồn kho là tài nguyên dùng chung GIỮA các khách, mà hai khách
     * khác nhau đi qua hai khoá `customers` khác nhau — cả hai đều đọc thấy
     * tồn = 1 và cả hai đều lấy được món cuối cùng.
     *
     * #962 — khoá `coupon` trong mảng trả về giờ là
     * {@see MintedCoupon} (DTO) chứ không phải `App\Models\Coupon`. Bảy trường
     * customer-web đọc vẫn còn nguyên; cái mất là quyền lần theo relation của
     * một model thuộc module khác từ tầng controller.
     *
     * @return array{entry: CustomerPointEntry, coupon: MintedCoupon, balance: int}
     *
     * @throws PointRedemptionException
     */
    public function redeem(Customer $customer, PointReward $reward): array
    {
        if (! $this->enabled()) {
            throw PointRedemptionException::disabled();
        }

        return DB::transaction(function () use ($customer, $reward) {
            // Tuần tự hoá theo từng khách. Khoá hàng `customers` chứ không
            // khoá sổ cái: sổ chỉ có INSERT nên không có hàng nào để khoá cho
            // tới khi đã quá muộn.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            // Chỉ khoá hàng phần thưởng khi nó CÓ giới hạn tồn. Phần thưởng
            // không giới hạn thì không có gì để tranh nhau, và khoá nó lại
            // biến mọi lượt đổi cùng một phần thưởng thành một hàng đợi.
            $fresh = PointReward::query()
                ->whereKey($reward->id)
                ->when($reward->stock_quantity !== null, fn ($q) => $q->lockForUpdate())
                ->first();

            if ($fresh === null || ! $fresh->is_active) {
                throw PointRedemptionException::rewardUnavailable();
            }

            $cost = (int) $fresh->cost_points;
            if ($cost <= 0) {
                throw PointRedemptionException::rewardUnavailable();
            }

            // BR-PR05/06 — kiểm tồn TRƯỚC khi trừ điểm. Ngược lại thì khách
            // mất điểm mà không nhận được gì.
            if ($fresh->isOutOfStock()) {
                throw PointRedemptionException::rewardOutOfStock();
            }

            $balance = $this->balance($customer);
            if ($balance < $cost) {
                throw PointRedemptionException::insufficientPoints($balance, $cost);
            }

            $coupon = $this->mintCouponFor($customer, $fresh);

            // `increment()` phát `SET redeemed_count = redeemed_count + 1` —
            // nguyên tử ngay cả với phần thưởng không giới hạn (đường không
            // khoá), nên bộ đếm dùng để thống kê vẫn đúng ở mọi phần thưởng.
            $fresh->increment('redeemed_count');

            $entry = CustomerPointEntry::create([
                'customer_id' => $customer->id,
                'organization_id' => $fresh->organization_id,
                'point_reward_id' => $fresh->id,
                'coupon_id' => $coupon->id,
                'kind' => PointEntryKindEnum::Redeem->value,
                'points' => -1 * $cost,
            ]);

            return [
                'entry' => $entry,
                'coupon' => $coupon,
                'balance' => $balance - $cost,
            ];
        });
    }

    // =====================================================================
    //  Nội bộ
    // =====================================================================

    /**
     * Quy đổi tiền của một đơn ra điểm.
     *
     * Tỉ lệ đọc thành MỘT CÂU — "`amount` tiền = `points` điểm" — chứ không
     * phải một con số: mẫu số cố định 1 điểm không khai được chính sách kiểu
     * "100 yên = 2 điểm" mà không phải quy về phân số (#1674).
     *
     * Ba tầng, tầng hẹp nhất thắng — cùng khuôn với `cart_timeout_minutes`:
     *   ① `branches.point_earn_amount` + `point_earn_points` — chi nhánh tự
     *      đặt. Đây là tầng ĐÚNG cho chi nhánh ở nước khác, vì đơn vị tiền
     *      sống ở `shop_order_settings.currency_code`, tức ở chi nhánh.
     *   ② `brands.*` cùng tên — mặc định của brand, HQ chỉnh trong admin.
     *   ③ `config('loyalty.earn')` theo ĐƠN VỊ TIỀN của chi nhánh bán hàng —
     *      JPY và VND lệch nhau hai bậc độ lớn nên một tỉ lệ chung là sai với
     *      ít nhất một nước. Tầng này ngầm định mẫu số 1 điểm.
     *
     * Mỗi tầng chỉ được dùng khi CẢ HAI giá trị dương; nửa cặp KHÔNG phải một
     * tỉ lệ nên nó rơi xuống tầng dưới thay vì thành "0 điểm" (API chặn nửa
     * cặp ở 422, đây là hàng rào thứ hai cho dữ liệu cũ hoặc sửa tay trong DB).
     *
     * Làm tròn XUỐNG: cho không một điểm lẻ thì dễ, đòi lại thì không.
     */
    public function pointsForOrder(PointableOrder $order): int
    {
        $basis = config('loyalty.earn.basis', 'subtotal') === 'total'
            ? $order->totalAmount
            : max(0.0, $order->subtotal - $order->discountAmount);

        if ($basis <= 0) {
            return 0;
        }

        [$amount, $points] = $this->earnRateFor($order);

        if ($amount <= 0 || $points <= 0) {
            return 0;
        }

        return (int) floor($basis / $amount * $points);
    }

    /**
     * Tỉ lệ tích điểm áp cho một đơn: `[số tiền, số điểm]`.
     *
     * @return array{float, int}
     */
    private function earnRateFor(PointableOrder $order): array
    {
        // ① chi nhánh
        if ($order->branchId !== null) {
            $branch = Branch::query()
                ->whereKey($order->branchId)
                ->first(['point_earn_amount', 'point_earn_points']);

            $rate = $this->usableRate($branch?->point_earn_amount, $branch?->point_earn_points);

            if ($rate !== null) {
                return $rate;
            }
        }

        // ② brand
        if ($order->brandId !== null) {
            $brand = Brand::query()
                ->whereKey($order->brandId)
                ->first(['point_earn_amount', 'point_earn_points']);

            $rate = $this->usableRate($brand?->point_earn_amount, $brand?->point_earn_points);

            if ($rate !== null) {
                return $rate;
            }
        }

        // ③ mặc định hệ thống theo đơn vị tiền
        $currency = $order->branchId === null
            ? null
            : app(BranchCurrency::class)->codeFor($order->branchId);

        $rates = (array) config('loyalty.earn.amount_per_point', []);

        return [
            (float) ($rates[$currency] ?? config('loyalty.earn.default_amount_per_point', 100)),
            1,
        ];
    }

    /**
     * Một tầng chỉ dùng được khi CẢ HAI vế dương. Trả null để gọi bên trên
     * rơi xuống tầng sau — nửa cặp không phải "tỉ lệ 0 điểm".
     *
     * @return array{float, int}|null
     */
    private function usableRate(mixed $amount, mixed $points): ?array
    {
        $amount = (float) ($amount ?? 0);
        $points = (int) ($points ?? 0);

        return ($amount > 0 && $points > 0) ? [$amount, $points] : null;
    }

    /**
     * Mint coupon cá nhân từ một phần thưởng.
     *
     * #962 — `coupons` thuộc Pricing, nên phép ĐÚC chuyển sang
     * {@see PersonalCouponMinting}; lớp này chỉ còn dịch một `PointReward`
     * (bảng của nó) thành điều khoản giảm giá. Lý do tấm này KHÔNG đi đường
     * `CouponService::create()` của HQ nằm trong docblock của bên hiện thực,
     * cùng chỗ với đoạn code nó nói về.
     */
    private function mintCouponFor(Customer $customer, PointReward $reward): MintedCoupon
    {
        $names = [];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $name = $reward->translate($locale)?->name ?? $reward->name;
            if ($name !== null) {
                $names[$locale] = (string) $name;
            }
        }

        return app(PersonalCouponMinting::class)->mint(new PersonalCouponSpec(
            customerId: (string) $customer->id,
            pointRewardId: (string) $reward->id,
            organizationId: $reward->organization_id === null ? null : (string) $reward->organization_id,
            brandId: $reward->brand_id === null ? null : (string) $reward->brand_id,
            discountType: $reward->discount_type instanceof \BackedEnum
                ? (string) $reward->discount_type->value
                : ($reward->discount_type === null ? null : (string) $reward->discount_type),
            discountValue: $reward->discount_value === null ? null : (string) $reward->discount_value,
            maxDiscountCap: $reward->max_discount_cap === null ? null : (string) $reward->max_discount_cap,
            minOrderSubtotal: $reward->min_order_subtotal === null ? null : (string) $reward->min_order_subtotal,
            validDays: (int) $reward->valid_days,
            namesByLocale: $names,
        ));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 = integrity constraint violation (MySQL / Postgres).
        // SQLite (dùng ở test) trả 23000 kèm "UNIQUE constraint failed".
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
