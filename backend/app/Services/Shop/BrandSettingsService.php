<?php

namespace App\Services\Shop;

use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Organization;
use App\Services\FileUploadService;
use App\Services\Loyalty\Contracts\MembershipTierBackgrounds;
use App\Services\Order\Contracts\OrderPrepTimeDefaults;

/**
 * Plan-047 thin-controller/fat-service — the brand-settings write logic extracted
 * from HQ/HqBrandSettingsController::update. Owns the brand-column update and the
 * BrandOrderPolicy upserts (each field can arrive alone), DRYing the three
 * repeated "firstOrNew + resolve local org id" blocks the controller inlined.
 */
class BrandSettingsService
{
    /**
     * #2047 — hai khoá logo là id File, nên cần `FileUploadService` để giữ file
     * vĩnh viễn lúc ghi và giải URL lúc đọc. Cùng lý do `MembershipTierBackgrounds`
     * tồn tại: đường nào lưu id File thì đường đó phải `make-permanent`, nếu
     * không `omnify:cleanup-files` sẽ quét mất file sau 12h.
     */
    public function __construct(
        private readonly MembershipTierBackgrounds $tierBackgrounds,
        private readonly FileUploadService $files,
    ) {}

    /**
     * #2047 — hai cột logo lưu id File (đường mới) thay cho URL tuyệt đối.
     *
     * @var list<string>
     */
    private const LOGO_FILE_ID_KEYS = [
        'customer_header_logo_file_id',
        'customer_order_logo_file_id',
    ];

    /**
     * Apply the settings present in $data (only the keys the client actually
     * sent) and return the resolved settings payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Brand $brand, array $data): array
    {
        // #1772 — map ảnh nền theo hạng. Chuẩn hoá TRƯỚC khi ghi (lọc khoá lạ,
        // map rỗng → null) rồi giữ file lại; thứ tự này quan trọng vì `retain`
        // chỉ được phép giữ đúng những file thật sự vào DB.
        if (array_key_exists('customer_tier_card_backgrounds', $data)) {
            $data['customer_tier_card_backgrounds'] = $this->tierBackgrounds
                ->sanitize($data['customer_tier_card_backgrounds']);

            $this->tierBackgrounds->retain($data['customer_tier_card_backgrounds']);
        }

        // #2047 — GIỮ file logo lại trước khi ghi. Đây chính là bước mà đường cũ
        // thiếu: admin-web gọi make-permanent theo kiểu "best-effort" và nuốt lỗi,
        // nên dòng `files` ở lại `status=temporary` và `omnify:cleanup-files` xoá
        // file vật lý sau 12h trong khi cột brand vẫn trỏ vào đó. Làm ở SERVER
        // nghĩa là không client nào có thể quên bước này.
        $logoFileIds = array_values(array_filter(
            array_map(
                static fn (string $key): mixed => $data[$key] ?? null,
                self::LOGO_FILE_ID_KEYS,
            ),
            static fn ($id): bool => is_string($id) && $id !== '',
        ));

        if ($logoFileIds !== []) {
            $this->files->makePermanentByIds($logoFileIds);
        }

        $brand->update(array_intersect_key($data, array_flip([
            'cart_timeout_minutes',
            'takeaway_payment_timeout_minutes',
            'customer_header_logo_url',
            'customer_header_logo_file_id',
            'invoice_registration_number',
            'customer_order_logo_url',
            'customer_order_logo_file_id',
            'customer_order_subtitle',
            'customer_tier_card_backgrounds',
            // #1674 — cặp tỉ lệ tích điểm. Ghi cả cặp hoặc không ghi gì:
            // validator của controller đã chặn nửa cặp bằng `required_with`
            // hai chiều, nên tới đây hai khoá luôn đi cùng nhau.
            'point_earn_amount',
            'point_earn_points',
        ])));

        if (array_key_exists('default_confirmation_timeout_minutes', $data)) {
            $policy = $this->resolvePolicy($brand);
            $policy->default_confirmation_timeout_minutes = (int) $data['default_confirmation_timeout_minutes'];
            $policy->save();
        }

        // #1160 — brand default prep minutes per item. Empty string → null so
        // clearing the HQ field releases the default and every shop falls back
        // to DEFAULT_PREP_MINUTES_PER_ITEM, rather than persisting 0 (which
        // would promise instant food brand-wide).
        if (array_key_exists('default_prep_minutes_per_item', $data)) {
            $policy = $this->resolvePolicy($brand);
            $value = $data['default_prep_minutes_per_item'];
            $policy->default_prep_minutes_per_item = ($value === null || $value === '')
                ? null
                : (int) $value;
            $policy->save();
        }

        // #491 — HQ default table status after payment.
        if (array_key_exists('default_table_status_after_payment', $data)) {
            $policy = $this->resolvePolicy($brand);
            $policy->default_table_status_after_payment = $data['default_table_status_after_payment'];
            $policy->save();
        }

        // #2937 — ngưỡng lệch tiền mặt trước khi đối soát ba chân kêu.
        //
        // 0 là giá trị HỢP LỆ = "báo mọi lệch", không phải "chưa cấu hình".
        // Cổng `BranchCashVarianceTolerance` phân biệt hai thứ đó bằng NULL vs
        // 0; ép 0 về mặc định ở đây sẽ âm thầm cướp mất lựa chọn của brand.
        if (array_key_exists('cash_variance_tolerance_minor', $data)) {
            $policy = $this->resolvePolicy($brand);
            $policy->cash_variance_tolerance_minor = (int) $data['cash_variance_tolerance_minor'];
            $policy->save();
        }

        // #890 — shop may edit HQ-origin tables.
        if (array_key_exists('allow_shop_edit_hq_tables', $data)) {
            $policy = $this->resolvePolicy($brand);
            $policy->allow_shop_edit_hq_tables = (bool) $data['allow_shop_edit_hq_tables'];
            $policy->save();
        }

        // Ngôn ngữ in mặc định cả chuỗi. Empty string → null so clearing the HQ
        // form releases the constraint instead of persisting "" (which every
        // whitelist downstream would reject anyway).
        if (array_key_exists('default_print_label_locale', $data)) {
            $policy = $this->resolvePolicy($brand);
            $policy->default_print_label_locale = $data['default_print_label_locale'] ?: null;
            $policy->save();
        }

        return $this->settingsPayload($brand->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(Brand $brand): array
    {
        $policy = BrandOrderPolicy::query()->where('brand_id', $brand->id)->first();

        return [
            'cart_timeout_minutes' => $brand->cart_timeout_minutes,
            'takeaway_payment_timeout_minutes' => $brand->takeaway_payment_timeout_minutes,
            // #2047 — URL giải LÚC ĐỌC từ id File, rơi về cột URL cũ khi brand
            // chưa chuyển sang đường mới. Giữ NGUYÊN tên trường `*_logo_url` là
            // có chủ ý: customer-web chỉ tiêu thụ URL và không cần biết nguồn,
            // nên nó không phải đổi một dòng nào.
            'customer_header_logo_url' => $this->logoUrl($brand, 'customer_header_logo_file_id', 'customer_header_logo_url'),
            'customer_order_logo_url' => $this->logoUrl($brand, 'customer_order_logo_file_id', 'customer_order_logo_url'),
            // Id là thứ admin PATCH ngược lại (GET/PATCH đối xứng), giống hệt
            // cặp khoá tier-card ở dưới. URL ở trên CHỈ để hiển thị.
            'customer_header_logo_file_id' => $brand->customer_header_logo_file_id,
            'customer_order_logo_file_id' => $brand->customer_order_logo_file_id,
            'invoice_registration_number' => $brand->invoice_registration_number,
            'customer_order_subtitle' => $brand->customer_order_subtitle,
            // #1772 — hai khoá, hai vai trò khác nhau. Map id là thứ admin PATCH
            // ngược lại (GET/PATCH đối xứng); map URL chỉ để xem trước và được
            // giải ra lúc đọc, nên KHÔNG được gửi lại — gửi lại là ghim host.
            'customer_tier_card_backgrounds' => $this->tierBackgrounds
                ->sanitize($brand->customer_tier_card_backgrounds) ?? [],
            'customer_tier_card_background_urls' => $this->tierBackgrounds->urls($brand),
            // Thang hạng đi kèm để màn cài đặt vẽ đúng những hạng ĐANG có, theo
            // đúng thứ tự. Hardcode 4 hạng bên admin-web thì thêm/bớt một hạng
            // ở `config/loyalty.php` là màn hình lệch với thực tế mà không ai
            // hay — chính là lớp lỗi câm mà validator ở đường ghi đang chặn.
            'membership_tiers' => $this->tierBackgrounds->tierKeys(),
            // #1674 — "point_earn_amount tiền = point_earn_points điểm".
            // null cả cặp = brand chưa cấu hình ⇒ tích điểm rơi về mặc định hệ
            // thống theo đơn vị tiền của chi nhánh (`config('loyalty.earn')`).
            // KHÔNG trả về giá trị mặc định đó ở đây: nó phụ thuộc đơn vị tiền
            // của từng CHI NHÁNH, mà payload này ở cấp brand — điền sẵn một con
            // số vào ô trống sẽ khiến người dùng lưu nhầm nó thành cấu hình thật.
            // `decimal:2` trả về CHUỖI ("100.00"); ép về số để ô nhập bên admin
            // không phải đoán kiểu.
            'point_earn_amount' => $brand->point_earn_amount === null
                ? null
                : (float) $brand->point_earn_amount,
            'point_earn_points' => $brand->point_earn_points,
            'default_confirmation_timeout_minutes' => $policy?->default_confirmation_timeout_minutes ?? 3,
            // #1160 — null preserved: HQ hasn't set a brand default, so shops
            // resolve to OrderPrepTimeDefaults::MINUTES_PER_ITEM (#962 7b — cổng
            // Ordering công bố; trước đây đọc hằng số qua EffectiveOrderPolicyService).
            // `effective_*` is what a shop that overrides nothing actually gets.
            'default_prep_minutes_per_item' => $policy?->default_prep_minutes_per_item,
            'effective_prep_minutes_per_item' => $policy?->default_prep_minutes_per_item
                ?? OrderPrepTimeDefaults::MINUTES_PER_ITEM,
            // #491 — HQ default table status after payment (free|cleaning).
            'default_table_status_after_payment' => $policy?->default_table_status_after_payment ?? 'free',
            // #890 — shop may edit HQ-origin tables (default: locked).
            'allow_shop_edit_hq_tables' => (bool) ($policy?->allow_shop_edit_hq_tables ?? false),
            // #2937 — mặc định khớp `CashDrawerReconciliationService::DEFAULT_TOLERANCE_MINOR`
            // để màn hình hiện đúng thứ hệ thống đang dùng khi brand chưa đặt.
            'cash_variance_tolerance_minor' => (int) ($policy?->cash_variance_tolerance_minor ?? 100),
            // Ngôn ngữ in mặc định cả chuỗi. null = HQ không ép; mỗi shop tự
            // fallback về mặc định chi nhánh của mình.
            'default_print_label_locale' => $policy?->default_print_label_locale,
        ];
    }

    /**
     * #2047 — URL của một logo brand, giải LÚC ĐỌC.
     *
     * Ưu tiên id File (ảnh do hệ thống quản lý) rồi mới tới cột URL NGOÀI, nên
     * brand chưa tải ảnh lên vẫn hiển thị bình thường.
     *
     * #2599 — ruling chủ dự án 2026-08-12: cột `brands.*_logo_url` **là đường
     * ghi hợp lệ đang dùng**, không phải nợ chờ xoá. `HqBrandSettingsController`
     * (L169 · L178) vẫn nhận ghi vào nó, nên một phép đo kiểu "0 brand còn phụ
     * thuộc cột cũ" không bao giờ ổn định được: đo ra 0 hôm nay thì mai một HQ
     * dán URL vào là khác 0. Tên cũ (`$legacyUrlColumn`) nói ngược điều đó và đã
     * làm hai lượt rà tưởng đây là nhánh chờ dọn.
     *
     * Trả `null` khi id trỏ tới File đã bị xoá, KHÔNG rơi ngược về cột URL
     * ngoài: cột đó gần như chắc chắn trỏ tới đúng file vừa mất, nên fallback
     * chỉ đổi một ô trống lấy một ảnh vỡ.
     */
    public function logoUrl(?Brand $brand, string $fileIdColumn, string $externalUrlColumn): ?string
    {
        if (! $brand) {
            return null;
        }

        $fileId = $brand->{$fileIdColumn};

        if (is_string($fileId) && $fileId !== '') {
            return $this->files->urlsByIds([$fileId])[$fileId] ?? null;
        }

        $externalUrl = $brand->{$externalUrlColumn};

        return is_string($externalUrl) && $externalUrl !== '' ? $externalUrl : null;
    }

    /**
     * The brand's order-policy row, ready to write. For a not-yet-existing row,
     * resolve organization_id from the LOCAL organizations mirror keyed by the
     * brand's console_organization_id (brand_order_policies.organization_id FKs
     * into organizations.id, NOT the SSO console id on the Brand).
     */
    private function resolvePolicy(Brand $brand): BrandOrderPolicy
    {
        $policy = BrandOrderPolicy::query()->firstOrNew(['brand_id' => $brand->id]);

        if (! $policy->exists) {
            $policy->organization_id = Organization::query()
                ->where('console_organization_id', $brand->console_organization_id)
                ->value('id');
        }

        return $policy;
    }
}
