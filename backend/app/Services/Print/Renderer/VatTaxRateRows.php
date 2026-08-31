<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * #2035 — khối thuế theo mức của HỌ HOÁ ĐƠN (GTGT + 適格簡易請求書).
 *
 * Đối ứng của `layoutVatRateRows` / `vatRateLinesFor` trong
 * `workstation/internal/service/print_tax_blocks.go`.
 *
 * Vì sao tách khỏi {@see BillKindPlans::taxBreakdownRows} dù cùng một khối chữ:
 * họ bill tự sở hữu phần thụt và tự ghi dòng, còn họ docs đẩy MỌI hàng chân
 * phiếu qua bộ căn của chính nó ({@see PrintRenderContext::runeRow},
 * {@see VatInvoiceJa::row}). Nên thứ dùng chung được ở đây là CẶP (nhãn, giá
 * trị), không phải dòng đã căn.
 *
 * Vì sao xuống dòng chứ không rút gọn — xem {@see Layout::NARROW_COLUMNS} và
 * docblock của `BillKindPlans::taxBreakdownRows`.
 */
final class VatTaxRateRows
{
    /**
     * Thụt THÊM của dòng thứ hai khi một mức phải xuống dòng — cùng con số với
     * họ bill để hai họ phiếu không lệch nhau hai cột.
     */
    private const WRAP_INDENT = 2;

    /**
     * Các cặp (nhãn, giá trị) của khối thuế theo mức, đã chọn xong hình dạng.
     *
     * `$measure` là phép đo CỦA NGƯỜI GỌI, không phải phép đo "đúng hơn" —
     * quyết định xuống dòng bằng một phép đo rồi lại căn bằng phép đo khác thì
     * ra một hàng không theo phép đo nào cả. Từ #2057 CẢ HAI người gọi đều đưa
     * cột hiển thị: nhãn của khối này theo locale của chính hoá đơn nên có thể
     * là chữ Nhật toàn khổ, và đếm mã điểm (phép đo cũ của GTGT) hụt 6 cột làm
     * hàng tràn ở mọi khổ giấy. Phần CÒN LẠI của chân GTGT vẫn đếm mã điểm
     * (xem docblock {@see Layout}, TR-40) — tham số này tồn tại để người gọi
     * giữ được đúng bộ căn của nó.
     *
     * `$prefix` là phần thụt riêng của chứng từ, áp cho CẢ HAI dòng để hàng
     * xuống dòng giữ nguyên lề của khối.
     *
     * Một dòng hay hai quyết MỘT lần cho cả khối (xem `taxBreakdownRows`).
     *
     * @param  list<VatInvoiceTaxLine>  $breakdown
     * @param  callable(string): int  $measure
     * @return list<array{0: string, 1: string}>
     */
    public static function layout(
        int $columns,
        callable $measure,
        string $prefix,
        TaxLabels $labels,
        string $currency,
        array $breakdown,
    ): array {
        $lines = [];

        foreach ($breakdown as $line) {
            $lines[] = [
                'label' => sprintf($labels->rateTarget, TaxLabels::formatRatePercent($line->rate)),
                'subLabel' => $labels->includedTax,
                'taxable' => $currency.Layout::formatPrice($line->taxable),
                'tax' => $currency.Layout::formatPrice($line->tax),
            ];
        }

        $inline = static fn (array $l): string => $l['taxable'].' ('.$l['subLabel'].' '.$l['tax'].')';

        // Cổng khổ hẹp đứng TRƯỚC phép đo vừa-hay-không: ở 42/48 nhánh xuống
        // dòng phải không với tới được. {@see Layout::NARROW_COLUMNS}
        $wrap = false;
        if (Layout::isNarrow($columns)) {
            foreach ($lines as $l) {
                if ($measure($prefix.$l['label']) + 1 + $measure($inline($l)) > $columns) {
                    $wrap = true;

                    break;
                }
            }
        }

        $rows = [];

        foreach ($lines as $l) {
            if (! $wrap) {
                $rows[] = [$prefix.$l['label'], $inline($l)];

                continue;
            }

            $rows[] = [$prefix.$l['label'], $l['taxable']];
            $rows[] = [$prefix.Layout::spaces(self::WRAP_INDENT).$l['subLabel'], $l['tax']];
        }

        return $rows;
    }
}
