<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — bản đối ứng PHP của `PrintJobConfig`
 * (workstation `internal/service/print_service.go`).
 *
 * Đây là **bối cảnh của CỬA HÀNG**, không phải của đơn hàng: tên quán, khổ
 * giấy, tiền tệ, locale nhãn, quốc gia vận hành. Mọi emitter đọc từ đây, nên
 * một trường thiếu ở phía PHP hiện ra trên phiếu thành một dòng vắng mặt chứ
 * không thành một ngoại lệ. `PrintContractParityTest` đọc fixture do Go sinh và
 * so đúng tập trường vì lý do đó.
 *
 * ── Bốn trục ĐỘC LẬP, cấm suy diễn chéo (#1459) ──────────────────────────
 *
 * `operatingCountry` (chứng từ nào được in) · `currencyCode` (tiền) ·
 * timezone (không nằm ở đây) · `locale` (ngôn ngữ nhãn). Trước #1493 trục 1 bị
 * suy ra từ trục 4, nên một quán Việt để giao diện tiếng Nhật in ra chứng từ
 * Nhật. Đừng nối lại.
 *
 * `operatingCountry` RỖNG là trạng thái hợp lệ và có nghĩa riêng — "chưa biết"
 * (bản cũ chưa pull, hoặc Cloud cũ hơn #1490). Khi rỗng, đường in giữ nguyên
 * nhánh cũ; mặc định một quốc gia ở đây sẽ làm một quán nào đó mất chứng từ
 * luật định giữa chừng.
 */
final class PrintJobConfig
{
    /** Ký hiệu tiền mặc định khi `currency` rỗng — giữ tương thích ngược. */
    public const DEFAULT_CURRENCY_SYMBOL = '¥';

    public function __construct(
        /**
         * #2000 bước 4 — 法人名, tên PHÁP NHÂN.
         *
         * Khác `$storeSubName` (thương hiệu) và `$storeName` (chi nhánh). Ba thứ
         * khác nhau, và quy ước hoá đơn Nhật in cả ba theo đúng thứ tự đó.
         *
         * Không chỉ là thẩm mỹ: 登録番号 T+13 (#1152) thuộc về pháp nhân, nên in
         * tên thương hiệu cạnh số của pháp nhân là lệch chủ thể.
         */
        public readonly string $storeOrganization = '',
        public readonly string $storeName = '',
        public readonly string $storeSubName = '',
        public readonly string $storeAddress = '',
        /**
         * #2000 bước 3 — số điện thoại chi nhánh.
         *
         * Cloud gửi `phone` trong feed branch từ lâu; ô này là chỗ đầu tiên nó
         * có để đứng. Trước đó khối `store_info` mời khai `store_phone` mà
         * không nơi nào chứa giá trị, nên bật lên vẫn in ra trống — đúng loại
         * lỗi mà #2000 bước 1 dựng rào để bắt.
         */
        public readonly string $storePhone = '',
        /** Bề rộng NỘI DUNG tính theo cột ký tự (vd 42). */
        public readonly int $paperWidth = 0,
        /**
         * Phần trăm thuế tiêu thụ của quán, vd 10 = 10%.
         *
         * **KHÔNG ĐƯỢC DÙNG ĐỂ TÍNH THUẾ (#2067).** Ô còn ở đây vì cổng
         * `PrintContractParityTest` so tập ô với `PrintJobConfig` bên Go, và ô
         * đó bên Go cũng còn vì cùng lý do đối xứng. Không đường nào trong
         * production điền nó — `grep 'taxRate:' backend/app` trả 0 kết quả, và
         * phía máy trạm `shop_settings.tax_rate` đã rỗng từ khi plan-043 T6.2
         * DROP cột `shop_order_settings.tax_rate`.
         *
         * Nó từng được dùng đúng một chỗ: `effectiveTaxRate()`, trả 10.0 khi ô
         * này là 0 — tức LUÔN LUÔN — rồi tầng in trích 内税 ra khỏi tổng bằng
         * con số đó. Thuế ở hệ này là per-rate theo từng dòng món
         * (plan-043); một tỉ lệ áp lên một tổng đơn không tái tạo được, và 10%
         * là giả định về Nhật áp lên cả quán VN. Cả hàm lẫn hằng đã bị gỡ.
         */
        public readonly float $taxRate = 0.0,
        /** Ký hiệu tiền in trên phiếu ("¥", "₫", "$"). Rỗng → ¥. */
        public readonly string $currency = '',
        /**
         * Bề rộng VẬT LÝ của máy in (32 cho 58mm, 48 cho 80mm). Khi lớn hơn
         * `paperWidth`, nội dung được căn giữa với lề trái/phải bằng nhau.
         * 0 → không căn giữa (in sát lề trái, hành vi cũ).
         */
        public readonly int $physicalWidth = 0,
        /** "ja" | "en" | "vi" — ngôn ngữ NHÃN. Rỗng → ja. */
        public readonly string $locale = '',
        /**
         * ISO 4217 ("JPY", "VND"). Quyết định BƯỚC làm tròn của khối thuế theo
         * mức, để con số 内消費税 in ra tròn giống hệt cách engine tròn.
         */
        public readonly string $currencyCode = '',
        /** 適格請求書発行事業者登録番号 (T+13). In RA CHỈ KHI khác rỗng. */
        public readonly string $sellerRegistrationNumber = '',
        /** ISO 3166-1 alpha-2 nơi quán TỒN TẠI. Rỗng = "chưa biết" — xem doc. */
        public readonly string $operatingCountry = '',
    ) {}

    /**
     * Lề trái để một layout rộng `$contentWidth` nằm giữa khổ giấy vật lý.
     * 0 khi chưa đặt `physicalWidth` hoặc nó không rộng hơn nội dung.
     */
    public function leftMargin(int $contentWidth): int
    {
        return $this->physicalWidth > $contentWidth
            ? intdiv($this->physicalWidth - $contentWidth, 2)
            : 0;
    }

    /** Ký hiệu tiền để in trước số — quán VND ra ₫ chứ không phải ¥. */
    public function currencySymbol(): string
    {
        return $this->currency === '' ? self::DEFAULT_CURRENCY_SYMBOL : $this->currency;
    }

    /**
     * Tên trường theo cách Go đặt — khoá để đối chiếu hợp đồng.
     *
     * Giữ TÁCH BIỆT khỏi tên thuộc tính PHP có chủ đích: fixture do Go sinh
     * dùng tên trường Go, và một hàm ánh xạ tường minh làm chỗ lệch hiện ra ở
     * đúng một dòng thay vì nằm rải trong quy ước đặt tên.
     *
     * @return array<string, string|int|float>
     */
    public function toGoFieldMap(): array
    {
        return [
            'Currency' => $this->currency,
            'CurrencyCode' => $this->currencyCode,
            'Locale' => $this->locale,
            'OperatingCountry' => $this->operatingCountry,
            'PaperWidth' => $this->paperWidth,
            'PhysicalWidth' => $this->physicalWidth,
            'SellerRegistrationNumber' => $this->sellerRegistrationNumber,
            'StoreAddress' => $this->storeAddress,
            'StoreOrganization' => $this->storeOrganization,
            'StorePhone' => $this->storePhone,
            'StoreName' => $this->storeName,
            'StoreSubName' => $this->storeSubName,
            'TaxRate' => $this->taxRate,
        ];
    }
}
