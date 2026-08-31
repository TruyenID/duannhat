<?php

namespace App\Services\Loyalty;

use App\Models\CustomerPointEntry;
use App\Models\PointReward;
use App\Models\PointRewardBranch;
use App\Omnify\Enums\PointEntryKindEnum;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #1514 — quản trị catalog đổi điểm.
 *
 * Tách khỏi `CustomerPointService` có chủ đích: bên kia là sổ cái điểm của
 * khách (append-only, tuần tự hoá bằng khoá hàng), bên này là CRUD một bảng
 * cấu hình. Gộp vào một lớp thì cái file duy nhất được phép ghi sổ cái lại
 * mọc thêm bốn phương thức chẳng liên quan gì tới bút toán.
 *
 * Lớp này KHÔNG bao giờ chạm `redeemed_count` — đó là bộ đếm do đường đổi
 * điểm sở hữu (BR-PR06). HQ hạ `stock_quantity` xuống dưới số đã phát thì
 * `remainingStock()` kẹp về 0, chứ không viết lại lịch sử.
 */
class PointRewardService
{
    /** @var list<string> */
    private const LOCALES = ['ja', 'en', 'vi'];

    /**
     * Danh sách cho màn quản trị — kể cả phần thưởng đang tắt.
     *
     * Khác `CustomerPointService::rewards()` ở chỗ đó: admin phải thấy thứ
     * mình vừa tắt, nếu không thì tắt xong là mất luôn đường bật lại.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return PointReward::query()
            ->with(['translations', 'image'])
            ->where('brand_id', $filters['brand_id'])
            ->when(
                isset($filters['is_active']),
                fn ($q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)),
            )
            ->when(
                ! empty($filters['search']),
                fn ($q) => $q->whereHas(
                    'translations',
                    fn ($t) => $t->where('name', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->orderBy('sort_order')
            ->orderBy('cost_points')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    /**
     * #1700 — nhật ký đổi thưởng của cả brand, cho màn quản trị.
     *
     * Đọc `customer_point_entries` chứ không đọc `coupons`: một lượt đổi LUÔN
     * sinh đúng một bút toán `redeem`, còn coupon thì `onDelete: SET NULL` ở
     * cả hai đầu — xoá cứng một phần thưởng hay một coupon vẫn còn dòng sổ,
     * và dòng sổ mới là thứ nói "đã có người tiêu 100 điểm ở đây".
     *
     * Phạm vi brand đi qua `point_rewards.brand_id`, KHÔNG qua
     * `customers.organization_id`: khách tự đăng ký có `organization_id = NULL`
     * (BR-PT04 — ví điểm là toàn cục), nên lọc theo khách sẽ giấu mất gần như
     * mọi lượt đổi. Cái thuộc về brand là phần thưởng, không phải người đổi.
     *
     * @param  array{brand_id: string, point_reward_id?: ?string, date_from?: ?string, date_to?: ?string, coupon_status?: ?string, search?: ?string, clock_branch_id?: ?string, per_page?: int}  $filters
     */
    public function redemptionLog(array $filters): LengthAwarePaginator
    {
        // Lọc theo NGÀY phải quy ra mốc UTC nửa mở [từ, đến) — `whereDate` so
        // với lịch của phiên DB (UTC) và đẩy mọi dòng quanh nửa đêm sang sai
        // ngày. Lượt đổi điểm không gắn chi nhánh nào (khách bấm ở
        // customer-web, không đứng ở quán), nên đồng hồ dùng ở đây là đồng hồ
        // VẬN HÀNH của brand — xem `clockBranchIdForBrand()`.
        [$from, $until] = BusinessClock::utcRangeForBusinessDates(
            $filters['clock_branch_id'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        return CustomerPointEntry::query()
            ->where('kind', PointEntryKindEnum::Redeem->value)
            // `withTrashed()` ở CẢ hai chỗ: `PointReward` xoá mềm, và `delete()`
            // của lớp này cố ý xoá mềm để lịch sử điểm giữ được tên phần
            // thưởng. Thiếu nó thì HQ xoá một phần thưởng là mọi lượt đổi của
            // nó biến mất khỏi nhật ký — đúng lúc người ta cần tra lại nhất.
            ->whereHas('pointReward', fn ($q) => $q->withTrashed()->where('brand_id', $filters['brand_id']))
            ->with([
                'customer:id,first_name,last_name,phone,email',
                'pointReward' => fn ($q) => $q->withTrashed()->with('translations'),
                'coupon:id,code,status,times_used,valid_from,valid_until,discount_type,discount_value',
            ])
            ->when(
                $filters['point_reward_id'] ?? null,
                fn ($q, $id) => $q->where('point_reward_id', $id),
            )
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('created_at', '<', $until))
            ->when($filters['coupon_status'] ?? null, fn ($q, $status) => $this->applyCouponStatus($q, (string) $status))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $search = trim((string) $filters['search']);

                $q->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })->orWhereHas('coupon', fn ($c) => $c->where('code', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Đồng hồ để hiểu bộ lọc ngày của một brand.
     *
     * Bút toán đổi điểm không có chi nhánh, nên không có "ngày nghiệp vụ" nào
     * đúng tuyệt đối. Lấy timezone của một chi nhánh bất kỳ thuộc brand (cũ
     * nhất, cho ổn định giữa các lần gọi) là xấp xỉ đúng cho trường hợp thật:
     * brand Việt Nam thì admin gõ "2026-08-03" và ý là ngày 3 giờ Hà Nội, chứ
     * không phải giờ Tokyo mặc định. Brand trải hai nước là trường hợp mà
     * KHÔNG lựa chọn nào đúng — nên API trả kèm `meta.timezone` để màn hình
     * nói rõ nó đang tính theo múi giờ nào thay vì im lặng đoán.
     */
    public function clockBranchIdForBrand(string $brandId): ?string
    {
        // `branches` KHÔNG có cột `brand_id` — liên kết brand↔chi nhánh đi qua
        // `console_brand_id` (khoá do SSO cấp, bền qua `migrate:fresh` hai
        // phía), xem `Branch::brand()`. Viết `where('brand_id', …)` ở đây thì
        // MySQL trả thẳng "Unknown column" ⇒ 500 cả endpoint.
        $consoleBrandId = DB::table('brands')->where('id', $brandId)->value('console_brand_id');

        if ($consoleBrandId === null) {
            return null;
        }

        return DB::table('branches')
            ->where('console_brand_id', $consoleBrandId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->value('id');
    }

    /**
     * Lọc theo tình trạng tấm coupon đã mint.
     *
     * `valid_until` là một MỐC UTC tuyệt đối (now + N ngày lúc mint — xem
     * `PersonalCouponMinter`), nên so sánh với `now()` ở đây là so hai instant,
     * không phải một ngày nghiệp vụ. Đó là lý do chỗ này KHÔNG cần
     * `BusinessClock` trong khi bộ lọc ngày phía trên thì cần.
     */
    private function applyCouponStatus(Builder $query, string $status): void
    {
        $now = CarbonImmutable::now();

        match ($status) {
            // Đã tiêu — `times_used` là bộ đếm nguyên tử do đường apply giữ.
            'used' => $query->whereHas('coupon', fn ($c) => $c->where('times_used', '>=', 1)),
            // Hết hạn mà chưa ai tiêu: đây là con số HQ cần khi hỏi "phát ra
            // bao nhiêu mà rơi vãi bao nhiêu".
            'expired' => $query->whereHas('coupon', fn ($c) => $c->where('times_used', '<', 1)
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', $now)),
            'unused' => $query->whereHas('coupon', fn ($c) => $c->where('times_used', '<', 1)
                ->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $now))),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $brandId, string $organizationId): PointReward
    {
        return DB::transaction(function () use ($data, $brandId, $organizationId) {
            $reward = new PointReward;
            $reward->brand_id = $brandId;
            $reward->organization_id = $organizationId;

            $this->applyAttributes($reward, $data, creating: true);
            $reward->save();

            $this->applyImage($reward, $data);

            return $reward->fresh(['translations', 'image']);
        });
    }

    /**
     * Cập nhật từng phần — trường không gửi thì giữ nguyên.
     *
     * KHÔNG có guard "đã có người đổi thì khoá thông số" như `CouponService`.
     * Không cần: coupon mint ra đã sao chép toàn bộ thông số sang bảng
     * `coupons` lúc đổi, nên sửa phần thưởng hôm nay không đụng được vào tấm
     * coupon khách đang cầm. Cái duy nhất đổi là những lượt đổi SAU đó.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PointReward $reward, array $data): PointReward
    {
        return DB::transaction(function () use ($reward, $data) {
            $this->applyAttributes($reward, $data, creating: false);
            $reward->save();

            $this->applyImage($reward, $data);

            return $reward->fresh(['translations', 'image']);
        });
    }

    public function delete(PointReward $reward): void
    {
        // Soft-delete. `customer_point_entries.point_reward_id` là SET NULL
        // khi xoá cứng, nên xoá cứng sẽ làm lịch sử điểm của khách mất tên
        // phần thưởng — dòng "đổi điểm" trơ trọi không nói được đổi lấy gì.
        $reward->delete();
    }

    /**
     * Bật/tắt một phần thưởng cho RIÊNG một chi nhánh (BR-PRB01).
     *
     * `updateOrCreate` chứ không `create`: cửa hàng bật/tắt qua lại nhiều lần
     * trong một tuần là bình thường, và unique index (point_reward_id,
     * branch_id) sẽ chặn lần thứ hai.
     *
     * Bật lại thì GIỮ dòng với `is_available = true` chứ không xoá — dấu vết
     * ai bấm lúc nào chính là thứ HQ sẽ hỏi khi doanh số đổi điểm của một cửa
     * hàng về 0 (xem header `PointRewardBranch.yaml`).
     */
    public function setBranchAvailability(
        PointReward $reward,
        string $branchId,
        bool $available,
        ?string $toggledById = null,
    ): PointRewardBranch {
        // Qua MODEL, không qua `DB::table()->updateOrInsert()`: model mới chạy
        // timestamps. Mà dấu vết ai bấm lúc nào chính là lý do dòng pivot được
        // giữ lại thay vì bị xoá khi bật lại (xem header `PointRewardBranch.yaml`).
        //
        // `toggled_by_id` điền TAY (#1723).
        //
        // Bản đầu dùng `options.audit` của Omnify để tự điền `created_by_id` /
        // `updated_by_id`. Nhưng `AuditConfig` sinh cột `bigint unsigned` cứng
        // — không có tuỳ chọn kiểu khoá — trong khi `users.id` ở repo này là
        // `char(36)`. Nhét UUID vào bigint thì MySQL truncate và MỌI lần bấm
        // công tắc đều chết bằng "Data truncated for column 'created_by_id'".
        // Test không thấy vì nó chạy sqlite, vốn có kiểu động.
        //
        // Cột giờ là Association thật (uuid, FK sang users, SET NULL), đổi lại
        // mất hook auto-fill đi kèm `audit` — nên người bấm phải truyền vào.
        // Cùng một vết với `PostFaqService::setInheritedVisibility()` (#1689).
        return PointRewardBranch::updateOrCreate(
            ['point_reward_id' => $reward->id, 'branch_id' => $branchId],
            ['is_available' => $available, 'toggled_by_id' => $toggledById],
        );
    }

    /**
     * Những chi nhánh đã TẮT phần thưởng này.
     *
     * @return list<string>
     */
    public function disabledBranchIds(PointReward $reward): array
    {
        return PointRewardBranch::query()
            ->where('point_reward_id', $reward->id)
            ->where('is_available', false)
            ->pluck('branch_id')
            ->all();
    }

    /**
     * Phần thưởng này có đang bật ở chi nhánh đó không.
     *
     * Không có dòng ⇒ true (BR-PRB01). Đây là chỗ dễ viết ngược nhất trong cả
     * tính năng: `where(...)->value('is_available')` trả `null` khi không có
     * dòng, và `(bool) null === false` sẽ tắt mọi phần thưởng ở mọi chi nhánh
     * chưa từng bấm công tắc.
     */
    public function isAvailableAtBranch(PointReward $reward, string $branchId): bool
    {
        $flag = PointRewardBranch::query()
            ->where('point_reward_id', $reward->id)
            ->where('branch_id', $branchId)
            ->value('is_available');

        return $flag === null || (bool) $flag;
    }

    // =====================================================================
    //  Nội bộ
    // =====================================================================

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyAttributes(PointReward $reward, array $data, bool $creating): void
    {
        foreach ([
            'cost_points',
            'discount_type',
            'discount_value',
            'max_discount_cap',
            'min_order_subtotal',
            'valid_days',
            'stock_quantity',
            'service_condition',
            'is_active',
            'sort_order',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $reward->{$field} = $data[$field];
            }
        }

        // BR-PR04 — `max_discount_cap` chỉ có nghĩa với percent. Dọn ở tầng
        // service chứ không chỉ ở request: đổi `percent` → `fixed` mà không
        // gửi lại cap thì request không có gì để từ chối, nhưng cột vẫn giữ
        // trần cũ và lần mint coupon sau sẽ mang theo một trần vô nghĩa.
        if (($data['discount_type'] ?? $reward->discount_type) === 'fixed') {
            $reward->max_discount_cap = null;
        }

        if ($creating) {
            $reward->is_active = $data['is_active'] ?? true;
            $reward->redeemed_count = 0;
        }

        foreach (self::LOCALES as $locale) {
            $payload = $data[$locale] ?? null;

            if (! is_array($payload)) {
                continue;
            }

            $name = isset($payload['name']) && is_string($payload['name'])
                ? trim($payload['name'])
                : null;

            // `point_reward_translations.name` là NOT NULL. Ngôn ngữ chỉ gửi
            // mô tả mà chưa từng có tên thì bỏ qua hẳn, đừng tạo dòng name=null
            // rồi chết ở tầng DB.
            if ($reward->translate($locale) === null && ($name === null || $name === '')) {
                continue;
            }

            $translation = $reward->translateOrNew($locale);

            if ($name !== null && $name !== '') {
                $translation->name = $name;
            }

            if (array_key_exists('description', $payload)) {
                $translation->description = $payload['description'];
            }
        }
    }

    /**
     * Gắn ảnh vừa upload (temp) vào phần thưởng.
     *
     * `syncFiles` lo cả make-permanent lẫn dọn ảnh cũ. Không gửi khoá
     * `image_file_id` ⇒ không đụng tới ảnh hiện có; gửi `null` ⇒ gỡ ảnh. Hai
     * ý đó khác nhau, nên kiểm bằng `array_key_exists` chứ không phải `??`.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyImage(PointReward $reward, array $data): void
    {
        if (! array_key_exists('image_file_id', $data)) {
            return;
        }

        $fileId = $data['image_file_id'];

        $reward->syncFiles($fileId === null ? [] : [$fileId], 'reward');
    }
}
