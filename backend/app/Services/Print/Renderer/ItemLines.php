<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Illuminate\Support\Facades\Log;

/**
 * plan-053 T5.1d slice 2 (#1932) — khối dòng món của một phiếu dựng từ đơn hàng.
 *
 * Đối ứng của `printRunnerItem` + `printVariantLine` + `printToppingLines` +
 * `printNoteLines` (workstation `print_service.go`). Bốn hàm ấy luôn đi cùng
 * nhau và cùng chia một con số — `indentW`, độ thụt của mọi dòng con dưới tên
 * món — nên chúng ở chung một class thay vì bốn hàm rời truyền `indentW` cho
 * nhau.
 *
 * ── Vì sao slice DOCS port cái này ───────────────────────────────────────
 *
 * `emitDebtItems` (phiếu ghi nợ) gọi thẳng `printRunnerItem`. Họ bill cũng gọi
 * nó cho block `items` của mình, nên đây là helper CHUNG — được port ở slice
 * docs vì slice docs cần nó trước. Họ bill dùng lại class này, đừng chép ra
 * bản thứ hai: hai bản sẽ trôi khỏi nhau ở đúng chỗ khó thấy nhất (độ thụt của
 * dòng topping), và cái lộ ra là một tờ giấy hơi lệch chứ không phải một lỗi.
 *
 * ── Phiếu ghi nợ KHÔNG in dấu ※ ─────────────────────────────────────────
 *
 * `$reduced` là tham số chứ không phải thứ class này tự suy: PHIẾU GHI NỢ là
 * bản ghi công nợ, không phải biên lai インボイス, nên nó truyền `false` (xem
 * {@see DocsKindPlans}). Suy dấu ※ ở đây sẽ dán nó lên cả phiếu nợ.
 *
 * ── Đường dự phòng đọc topping TỪ GHI CHÚ là thật, không phải di sản chết ──
 *
 * App Handy mã hoá topping vào ô ghi chú ("+ Tên ¥giá" / "- Tên"). Một đơn từ
 * Handy có `toppings` rỗng nhưng `note` đầy, và bỏ nhánh này thì bếp không thấy
 * topping khách đã gọi.
 */
final class ItemLines
{
    /** Dấu phân tách " · " (U+00B7) mà frontend nối tên biến thể vào tên món. */
    private const VARIANT_SEPARATOR = " \u{00B7} ";

    /** Tiền tố của mọi dòng con (topping, biến thể). */
    private const SUB_PREFIX = '-- ';

    /**
     * Bề rộng mà MỌI số tiền trong bảng món được đệm tới, để các ký hiệu tiền
     * xếp thành một CỘT thẳng.
     *
     * Canh phải từng giá riêng lẻ đặt dấu ¥ của "¥0" thụt vào bốn cột so với ¥
     * của "¥1,000" — mắt phải dò lại cột ở mỗi dòng. Đệm mọi giá tới giá rộng
     * nhất TRÊN TỜ ĐÓ rồi mới canh phải thì các ký hiệu thẳng hàng.
     *
     * Đo trên cả danh sách, kể cả topping, nên cột không nhảy giữa bảng. Đối
     * ứng `slipPriceWidth` bên Go — TR-40 so hai đường theo byte.
     *
     * @param  list<PrintRenderItem>  $items
     */
    public static function priceColumnWidth(array $items, string $currency): int
    {
        $widest = 0;

        foreach ($items as $item) {
            if ($item->quantity <= 0 || trim($item->menuItemName) === '') {
                continue;
            }

            $widest = max($widest, Layout::displayWidth($currency.Layout::formatPrice($item->unitPrice * $item->quantity)));

            foreach ($item->toppings as $topping) {
                if (trim($topping->name) === '') {
                    continue;
                }

                $value = $topping->unitPrice * $topping->quantity;
                $price = $topping->modifierType === 'remove' && $value !== 0
                    ? '-¥'.Layout::formatPrice($value)
                    : $currency.Layout::formatPrice($value);
                $widest = max($widest, Layout::displayWidth($price));
            }
        }

        return $widest;
    }

    /**
     * Một dòng món + biến thể + topping + ghi chú.
     *
     * @param  bool  $reduced  dòng này chịu thuế suất giảm ⇒ gắn ※ sau tên món
     */
    public static function emit(
        Escpos $encoder,
        int $width,
        int $priceColWidth,
        PrintRenderItem $item,
        string $currency,
        bool $reduced,
        string $locale,
        string $notePrefix,
    ): void {
        // Số lượng 0 hoặc tên rỗng là dữ liệu hỏng, và in nó ra thành một dòng
        // trống giữa bảng món thì người đọc phiếu không có cách nào biết là đã
        // mất một món hay chưa bao giờ có món đó.
        if ($item->quantity <= 0 || trim($item->menuItemName) === '') {
            return;
        }

        $name = self::stripVariantSuffix($item->menuItemName);

        if ($reduced) {
            $name .= ' '.TaxLabels::forLocale($locale)->reducedMarker;
        }

        $qty = (string) $item->quantity;
        $price = Layout::padRight($currency.Layout::formatPrice($item->unitPrice * $item->quantity), $priceColWidth);

        // Đo bằng MÃ ĐIỂM, giống Go: số lượng luôn là chữ số ASCII nên hai phép
        // đo trùng nhau — giữ đúng hàm Go dùng để khỏi phải chứng minh lại.
        $indentWidth = Layout::runeLength($qty) + 2;

        self::wrappedName($encoder, $width, $qty, $name, $price);
        self::variantLine($encoder, $indentWidth, $item);
        self::toppingLines($encoder, $width, $priceColWidth, $indentWidth, $item, $currency, $locale, $notePrefix);
    }

    /**
     * "2  Tên món ................ ¥1,200", tên dài thì xuống dòng và thụt vào
     * đúng cột tên.
     */
    private static function wrappedName(Escpos $encoder, int $width, string $qty, string $name, string $price): void
    {
        $indentWidth = Layout::runeLength($qty) + 2;
        $nameColumn = max($width - $indentWidth - Layout::displayWidth($price), 1);
        $continuation = max($width - $indentWidth, 1);

        $lines = Layout::wrapNameLines($name, $nameColumn, $continuation);

        // Dòng đầu đệm tên tới hết cột tên để giá căn phải; các dòng sau chỉ
        // thụt vào, KHÔNG đệm — đệm chúng sẽ tạo một cột giá rỗng đầy khoảng
        // trắng đuôi, vô hình trên màn hình và lộ ra trên giấy nhiệt.
        $encoder->line($qty.'  '.Layout::padRight($lines[0], $nameColumn).$price);

        foreach (array_slice($lines, 1) as $line) {
            $encoder->line(Layout::spaces($indentWidth).$line);
        }
    }

    /**
     * Biến thể SKU ("-- Size L") — in TRƯỚC topping vì nó là một phần danh tính
     * của món, không phải thứ khách thêm vào.
     */
    private static function variantLine(Escpos $encoder, int $indentWidth, PrintRenderItem $item): void
    {
        $variant = self::variantOf($item);

        if ($variant === '') {
            return;
        }

        $encoder->line(Layout::spaces($indentWidth).self::SUB_PREFIX.$variant);
    }

    /**
     * Ưu tiên cột `sku_variant_name` có cấu trúc; rơi về phần đuôi " · " của
     * tên hiển thị, vì {@see stripVariantSuffix} vừa cắt phần đuôi đó khỏi tên
     * món — không có nhánh này thì biến thể mất hẳn.
     */
    private static function variantOf(PrintRenderItem $item): string
    {
        $structured = trim($item->skuVariantName);

        if ($structured !== '') {
            return $structured;
        }

        $at = strpos($item->menuItemName, self::VARIANT_SEPARATOR);

        return $at === false
            ? ''
            : trim(substr($item->menuItemName, $at + strlen(self::VARIANT_SEPARATOR)));
    }

    private static function stripVariantSuffix(string $name): string
    {
        $at = strpos($name, self::VARIANT_SEPARATOR);

        return $at === false ? $name : substr($name, 0, $at);
    }

    /**
     * Dòng đối soát tiền topping — khớp `printToppingWaiver` của Go (#2812).
     *
     * Nó khép khoảng cách giữa số tiền các dòng topping ĐÃ IN và số quán thật sự
     * thu (`toppingSubtotal`), tức phần được miễn ở bậc miễn phí.
     *
     * Bốn lối ra sớm, mỗi lối một lý do khác nhau:
     *
     *   - `toppingSubtotal === 0`  → không có gì để đối soát;
     *   - chênh lệch bằng 0        → hai bên đã khớp, in thêm một dòng "-0" chỉ
     *                                làm phiếu dài ra;
     *   - chênh lệch ÂM            → các dòng in ra ÍT hơn số đã thu, tức hai
     *                                nguồn bất đồng theo chiều mà một khoản miễn
     *                                phí không tạo ra được. Phiếu BỎ dòng thay vì
     *                                in một khoản phụ thu dưới nhãn "miễn phí"
     *                                (#2067 — tầng in IN dữ liệu đơn, không ĐỊNH
     *                                GIÁ một lượt bán). Ghi log để có người soi.
     *   - `$reconcilable === false` (kiểm ở nơi gọi) → có dòng topping không lên
     *                                được giấy hoặc mang khoản trừ engine chưa áp,
     *                                nên tổng đã in không so được với gì.
     */
    private static function toppingWaiverLine(
        Escpos $encoder,
        int $width,
        int $indentWidth,
        PrintRenderItem $item,
        int $printedPerUnit,
        int $lineQty,
        string $currency,
        string $locale,
    ): void {
        if ($item->toppingSubtotal === 0) {
            return;
        }

        $waivedPerUnit = $printedPerUnit - $item->toppingSubtotal;

        if ($waivedPerUnit === 0) {
            return;
        }

        if ($waivedPerUnit < 0) {
            Log::warning('print: topping rows total less than the charged topping subtotal — no reconciling row printed', [
                // Go log kèm `item.ID`; `PrintRenderItem` của PHP không mang id
                // (DTO chỉ chở thứ cần để VẼ). Không bịa một id ở đây — log là
                // chẩn đoán, không phải byte trên phiếu, nên nó không ảnh hưởng
                // parity; thiếu id thì tra bằng tên món + số tiền.
                'menu_item_name' => $item->menuItemName,
                'printed_per_unit' => $printedPerUnit,
                'topping_subtotal' => $item->toppingSubtotal,
                'detail' => 'các dòng topping đã in và customer_order_items.topping_subtotal bất đồng theo chiều mà một khoản miễn phí không tạo ra được (#2067).',
            ]);

            return;
        }

        $label = PrintLabels::forLocale($locale)->toppingWaived;
        $amount = '-'.$currency.Layout::formatPrice($waivedPerUnit * $lineQty);
        $nameWidth = max($width - $indentWidth - Layout::displayWidth(self::SUB_PREFIX) - Layout::displayWidth($amount), 1);

        $encoder->line(Layout::spaces($indentWidth).self::SUB_PREFIX.Layout::padRight($label, $nameWidth).$amount);
    }

    private static function toppingLines(
        Escpos $encoder,
        int $width,
        int $priceColWidth,
        int $indentWidth,
        PrintRenderItem $item,
        string $currency,
        string $locale,
        string $notePrefix,
    ): void {
        // #2812 — số lượng topping nhân theo số lượng DÒNG, khớp `printToppingLines`
        // của Go. Người gọi (runner/kitchen) đã thoát sớm khi qty <= 0, nên kẹp về 1
        // ở đây chỉ chắn lời gọi trực tiếp: một dòng dị dạng in topping theo giá
        // niêm yết vẫn hơn in bằng 0 — số trông sai thì có người báo, số bị âm thầm
        // làm rỗng thì không.
        $lineQty = max($item->quantity, 1);

        if ($item->toppings !== []) {
            // Cộng dồn số tiền mà CHÍNH các dòng đã in đặt lên giấy, tính trên MỘT
            // đơn vị món, để dòng waiver bên dưới khép được khoảng cách với số quán
            // thật sự thu. Cộng từ đúng giá trị đã in, KHÔNG tính lại — tính lại là
            // một ý kiến thứ hai, mà cả điểm của dòng đó là hai bên phải khớp.
            $printedPerUnit = 0;
            $reconcilable = true;

            foreach ($item->toppings as $topping) {
                $name = self::collapseMirroredName(trim($topping->name));

                if ($name === '') {
                    // Không có gì lên giấy cho dòng này, nhưng engine vẫn định giá
                    // nó. Phiếu vì thế không đối soát được với `toppingSubtotal`,
                    // nên item này không in dòng waiver.
                    if ($topping->unitPrice !== 0) {
                        $reconcilable = false;
                    }

                    continue;
                }

                $totalQty = $topping->quantity * $lineQty;

                // "×2" chỉ có nghĩa với thứ được THÊM. Một modifier "bỏ hành"
                // không có số lượng để nhân.
                if ($totalQty > 1 && $topping->modifierType !== 'remove') {
                    $name = $totalQty.' x '.$name;
                }

                // Modifier giá 0 VẪN in giá. Ô trống đọc như "số tiền nạp
                // hỏng"; in số 0 nói nó miễn phí — đúng điều tờ phiếu của quán
                // làm. Vì thế không còn rào bằng `unitPrice !== 0`; phần đối
                // soát bên dưới KHÔNG đổi nghĩa vì giá 0 cộng vào
                // `printedPerUnit` đúng bằng 0, và một "remove" giá 0 vẫn phải
                // KHÔNG xoá `$reconcilable` — trừ đi số không không phải là
                // khoản trừ mà engine bỏ sót.
                $price = $currency.Layout::formatPrice($topping->unitPrice * $totalQty);

                if ($topping->modifierType === 'remove') {
                    if ($topping->unitPrice !== 0) {
                        // Theo hợp đồng schema, "remove" mang extra_price 0 (bỏ món
                        // gì không đổi giá), nên nhánh này không tới được với dòng
                        // đúng dạng. Khi nó TỚI được, dòng in một khoản trừ mà engine
                        // chưa bao giờ áp — engine cộng `unitPrice` không dấu — nên
                        // item thôi đối soát được và không có dòng waiver.
                        $reconcilable = false;
                        // "-" + tiền tệ của quán, KHÔNG phải "-¥" cứng: mọi khoản
                        // khác trên phiếu đều theo tiền của quán, và một quán VND in
                        // ký hiệu yên ở đúng một dòng là dòng không ai còn tin.
                        $price = '-'.$currency.Layout::formatPrice($topping->unitPrice * $totalQty);
                    }
                } else {
                    $printedPerUnit += $topping->unitPrice * max($topping->quantity, 1);
                }

                // Đệm tới bề rộng giá RỘNG NHẤT trên tờ rồi mới canh phải, để
                // dấu tiền của dòng topping thẳng cột với dòng món ngay trên.
                $price = Layout::padRight($price, $priceColWidth);

                $nameWidth = max($width - $indentWidth - Layout::displayWidth(self::SUB_PREFIX) - Layout::displayWidth($price), 1);

                $encoder->line(Layout::spaces($indentWidth).self::SUB_PREFIX.Layout::padRight($name, $nameWidth).$price);
            }

            if ($reconcilable) {
                self::toppingWaiverLine($encoder, $width, $indentWidth, $item, $printedPerUnit, $lineQty, $currency, $locale);
            }

            $note = trim($item->note);

            if ($note !== '') {
                self::noteLines($encoder, $width, $indentWidth, $note, $notePrefix);
            }

            return;
        }

        $note = trim($item->note);

        if ($note === '') {
            return;
        }

        [$parsed, $freeNote] = self::parseNoteAsToppings($note);

        foreach ($parsed as $topping) {
            $price = '';

            if ($topping['price'] !== 0) {
                $price = ($topping['modifier_type'] === 'remove' ? '-¥' : $currency)
                    .Layout::formatPrice($topping['price']);
            }

            $nameWidth = max($width - $indentWidth - Layout::displayWidth(self::SUB_PREFIX) - Layout::displayWidth($price), 1);

            $encoder->line(Layout::spaces($indentWidth).self::SUB_PREFIX.Layout::padRight($topping['name'], $nameWidth).$price);
        }

        if ($freeNote !== '') {
            self::noteLines($encoder, $width, $indentWidth, $freeNote, $notePrefix);
        }
    }

    /**
     * "   Ghi chu: …" — ngắt theo TỪ, dòng tiếp theo thụt vào dưới chữ, không
     * dưới nhãn.
     *
     * Cắt cứng giữa từ là thứ máy in sẽ tự làm nếu dòng vượt bề rộng, và nó
     * cũng phá luôn lề trái/phải của cả khối. Ngắt trước ở đây là cách giữ khối
     * chữ nằm trong khung.
     */
    private static function noteLines(
        Escpos $encoder,
        int $width,
        int $indentWidth,
        string $note,
        string $notePrefix,
    ): void {
        $prefixWidth = Layout::displayWidth($notePrefix);
        $textWidth = max($width - $indentWidth - $prefixWidth, 1);
        $continuationIndent = $indentWidth + $prefixWidth;

        foreach (explode("\n", $note) as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $lines = Layout::wrapNameLines($paragraph, $textWidth, $textWidth);

            if ($lines === []) {
                continue;
            }

            $encoder->line(Layout::spaces($indentWidth).$notePrefix.$lines[0]);

            foreach (array_slice($lines, 1) as $line) {
                $encoder->line(Layout::spaces($continuationIndent).$line);
            }
        }
    }

    /**
     * Tách topping do app Handy mã hoá trong ô ghi chú.
     *
     * Dòng có tiền tố `+`/`-` là topping; MỌI dòng khác là ghi chú thật của
     * khách ("it cay, khong hanh") và phải hiện ra dưới nhãn "Ghi chu:" chứ
     * không bị giấu thành một dòng "-- it cay".
     *
     * @return array{0: list<array{name: string, modifier_type: string, price: int}>, 1: string}
     */
    private static function parseNoteAsToppings(string $note): array
    {
        $toppings = [];
        $freeLines = [];

        foreach (explode("\n", $note) as $raw) {
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $name = self::trimPrefix(self::trimPrefix($line, '+ '), '+');
                $price = 0;

                // `strrpos` theo BYTE, giống `strings.LastIndex` bên Go: '¥'
                // dài 2 byte UTF-8 nhưng cả hai bên cắt ở cùng một chỗ.
                $at = strrpos($name, '¥');

                if ($at !== false) {
                    $price = self::leadingInt(str_replace(',', '', substr($name, $at + strlen('¥'))));
                    $name = trim(substr($name, 0, $at));
                }

                $toppings[] = ['name' => $name, 'modifier_type' => 'add', 'price' => $price];

                continue;
            }

            if (str_starts_with($line, '-')) {
                $toppings[] = [
                    'name' => trim(self::trimPrefix(self::trimPrefix($line, '- '), '-')),
                    'modifier_type' => 'remove',
                    'price' => 0,
                ];

                continue;
            }

            $freeLines[] = $line;
        }

        return [$toppings, implode("\n", $freeLines)];
    }

    /** Đối ứng `strings.TrimPrefix` — cắt MỘT lần, không lặp. */
    private static function trimPrefix(string $s, string $prefix): string
    {
        return str_starts_with($s, $prefix) ? substr($s, strlen($prefix)) : $s;
    }

    /**
     * Số nguyên ở ĐẦU chuỗi, 0 khi không có — đối ứng `fmt.Sscanf(s, "%d")`,
     * vốn bỏ qua phần đuôi và để nguyên giá trị cũ khi không đọc được.
     */
    private static function leadingInt(string $s): int
    {
        return preg_match('/^\s*[-+]?\d+/', $s, $m) === 1 ? (int) $m[0] : 0;
    }

    /**
     * "Nuoc mam · Nuoc mam" → "Nuoc mam".
     *
     * Đơn tạo trước workstation-app#101 lưu tên topping nhân đôi kiểu này (một
     * topping SKU mặc định có tên SKU trùng tên sản phẩm). Đường in ghi ra
     * nguyên văn, nên không có hàm này thì phiếu in topping hai lần và đẩy nó
     * xuống một dòng thứ hai KHÔNG có tiền tố. Một topping "Sản phẩm · Biến
     * thể" thật (hai phần khác nhau) được giữ nguyên.
     */
    private static function collapseMirroredName(string $name): string
    {
        if (! str_contains($name, self::VARIANT_SEPARATOR)) {
            return $name;
        }

        $parts = explode(self::VARIANT_SEPARATOR, $name);
        $first = trim($parts[0]);

        foreach ($parts as $part) {
            if (mb_strtolower(trim($part)) !== mb_strtolower($first)) {
                return $name;
            }
        }

        return $first;
    }
}
