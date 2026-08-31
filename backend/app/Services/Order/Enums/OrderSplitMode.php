<?php

declare(strict_types=1);

namespace App\Services\Order\Enums;

/**
 * #2860 — TỪ VỰNG DUY NHẤT của chia bill. Mọi tầng, mọi app.
 *
 * # Ba chế độ, và vì sao đúng ba
 *
 * Square · Toast · Lightspeed · Clover đều dừng ở đúng ba: *split evenly* ·
 * *split by item* · *split by amount*. Không phải trùng hợp — ba câu hỏi đó vét
 * hết cách một bàn khách chia tiền: chia đầu người, chia theo món ai ăn, hoặc
 * mỗi người tự khai một số.
 *
 * Trước #2860 repo này viết ba khái niệm đó bằng **bảy** cách, tách theo tầng:
 * đơn nói `by_people|by_items|custom`, thanh toán nói `equal|by_items|by_amount`,
 * kiosk tự có `full|split_even|custom|by_items`, và `even` thì được NHẬN ở hai
 * nơi mà không ai phát. Giao nhau giữa hai tầng chính: đúng MỘT giá trị.
 *
 * # Vì sao `even` chứ không phải `equal`
 *
 * Hai lý do, lý do sau nặng hơn:
 *
 *  1. *evenly* là từ ngành dùng trong UI của cả bốn hệ trên.
 *  2. **Workstation đã nhận sẵn `even`** (`case "even", "equal"` trong
 *     `print_service.go`). Fleet là hai máy Windows KHÔNG tự cập nhật, nên chọn
 *     giá trị mà chúng đã hiểu là chọn được một bộ từ vựng mới mà không bắt
 *     đường in của quán phải đợi một bản phát hành.
 *
 * # `custom` và `by_amount` là MỘT
 *
 * Chú thích ADR-1 ở `CustomerOrderController` tự nói ra điều đó: `custom` là
 * *"tôi trả X, bạn trả Y"* — đúng định nghĩa `by_amount` phía POS. Hai tên cho
 * một khái niệm, sinh ra vì hai đội viết hai đầu vào hai thời điểm.
 *
 * # Tên cũ sống ở ĐÂU
 *
 * Chỉ ở {@see self::fromWire()}, và chỉ theo chiều VÀO. Không nhánh nào phía
 * sau — không service, không renderer, không migration, không client — được
 * phép so sánh với một tên cũ. Cột trong DB chỉ chứa giá trị canonical.
 *
 * Đây KHÔNG phải nhánh tương thích ngược mà #2188 cấm. #2188 nói về **dữ liệu
 * cũ** và tính năng **chưa release**; cái ở đây là **lệch phiên bản fleet trên
 * thiết bị đã phát hành** — workstation trên hai máy Windows không tự cập nhật,
 * kiosk là app native trên tablet. Siết validator mà không nhận tên cũ là chặn
 * đứng đồng bộ bán hàng của chính những máy đó.
 *
 * Điều kiện gỡ là một PHÉP ĐO, không phải một cái hẹn:
 *
 *     php artisan devices:fleet-versions --type=workstation --min-version=<ver> --require-min
 *     php artisan devices:fleet-versions --type=kiosk       --min-version=<ver> --require-min
 *
 * CẢ HAI phải xanh (#2865): kiosk cũng phát tên cũ và cũng là app native
 * không tự cập nhật. Gỡ alias khi mới có workstation xanh sẽ làm mọi kiosk
 * chưa nâng cấp nhận 422 ở đúng bước thanh toán.
 *
 * xanh nghĩa là không còn máy nào phát tên cũ. Lúc đó xoá {@see self::WIRE_ALIASES}
 * và chính hàm `fromWire()` — chứ không phải để nó nằm lại thành di tích.
 */
enum OrderSplitMode: string
{
    /** Chia đều theo đầu người. Đi kèm `split_count` / `split_people_count`. */
    case Even = 'even';

    /** Chia theo món ai ăn. Đi kèm `item_allocations`. */
    case ByItems = 'by_items';

    /** Mỗi người tự khai một số tiền. Đi kèm `amount`. */
    case ByAmount = 'by_amount';

    /**
     * Tên cũ → canonical. CHỈ dùng ở biên vào.
     *
     * Bốn tên, ba nguồn:
     *   `equal`      — pos-web + kiosk gửi trong `metadata.split_mode`
     *   `by_people`  — customer-web gửi lên `/customer/orders/{id}/split-mode`
     *   `custom`     — customer-web, cùng endpoint
     *   `split_even` — trạng thái nội bộ của kiosk; chưa từng lên wire, nhưng
     *                  liệt kê ở đây để một bản kiosk lỡ gửi thẳng cũng không
     *                  làm hỏng một lượt bán.
     *
     * @var array<string, string>
     */
    private const WIRE_ALIASES = [
        'equal' => 'even',
        'by_people' => 'even',
        'split_even' => 'even',
        'custom' => 'by_amount',
    ];

    /** Mọi chuỗi một client được phép gửi lên — canonical ∪ tên cũ. */
    public static function acceptedWireValues(): array
    {
        return array_values(array_unique(array_merge(
            array_column(self::cases(), 'value'),
            array_keys(self::WIRE_ALIASES),
        )));
    }

    /** Luật `in:` cho `validate()`. Một chỗ sinh ra, để hai validator không trôi khỏi nhau. */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::acceptedWireValues());
    }

    /**
     * Chuẩn hoá một giá trị nhận từ client về canonical.
     *
     * `null` khi không có gì để nói (không chia bill). Ném khi chuỗi không thuộc
     * cả hai tập — fail-closed có chủ ý: một chế độ chia bill không đọc được mà
     * bị nuốt thành `null` sẽ ghi một dòng tiền không ai giải thích được sau này.
     */
    public static function fromWire(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::from(self::WIRE_ALIASES[$value] ?? $value);
    }

    /** Như {@see self::fromWire()} nhưng trả chuỗi — cho chỗ ghi thẳng vào mảng metadata. */
    public static function canonicalWire(?string $value): ?string
    {
        return self::fromWire($value)?->value;
    }
}
