import type { CustomerOrder, TaxBreakdownEntry } from "../types";
import {
  itemTaxTotal,
  roundingAdjustment,
  serviceChargeDisplay,
  serviceTaxTotal,
  showsRoundingAdjustment,
  taxSitsInsideSubtotal,
  visibleTaxGroups,
  type ServiceChargeDisplay,
} from "./tax-display";

/** Every figure the money column shows, already resolved for the display mode. */
export interface OrderTotals {
  /** "Tạm tính" as displayed — gross when the view converts net→gross. */
  subtotal: number;
  /**
   * true khi `subtotal` là con số DO GIAO DIỆN QUY ĐỔI (net + itemTax), nên nó
   * có thể mang phần lẻ dưới đơn vị tiền tệ và phải render theo `taxDecimals`.
   *
   * KHÔNG dùng `taxIsInside` cho việc này: ảnh chụp 内税 cũng có thuế nằm trong
   * subtotal, nhưng đó là con số đã lưu nguyên đồng — render nó với phần thập
   * phân sẽ ra "¥400,0". Và KHÔNG suy ra bằng `subtotal !== stored`: khi itemTax
   * bằng 0 (đơn 非課税) phép so đó trả về false trong lúc quy đổi vẫn đang bật.
   */
  subtotalWasConverted: boolean;
  discount: number;
  /** 税込 headline + its 税抜 sub-line. See {@link serviceChargeDisplay}. */
  service: ServiceChargeDisplay;
  /** The service slice of `tax_amount` — sits INSIDE `service.gross`. */
  serviceTax: number;
  /** Σ per-rate ITEM tax (excludes the service slice). */
  itemTax: number;
  /** Per-rate groups worth drawing (#2138 keeps zero-rated ones). */
  taxGroups: TaxBreakdownEntry[];
  /** true → tax is already inside `subtotal`; render it as an 内税 note, never as an addend. */
  taxIsInside: boolean;
  hasBreakdown: boolean;
  /** 端数調整 — the payable total minus the exact sum of the rows above. */
  rounding: number;
  showRounding: boolean;
  /** Fraction digits for TAX figures (plan-045 option-B); null = currency default. */
  taxDecimals: number | null;
  /** Payable total — always the server's `total_amount`, never re-derived. */
  total: number;
}

/**
 * Cột số của một đơn, tính MỘT LẦN cho mọi màn hình vẽ nó.
 *
 * Hai màn cần đúng bộ số này — giỏ hàng và hộp thoại thu tiền — và chúng phải
 * không bao giờ lệch nhau. Bài học đã trả giá hai lần trong chính thư mục này:
 * `visibleTaxGroups` và `showsMoneyRow` ra đời vì cùng một luật từng sống thành
 * nhiều bản sao inline trong một component 1.500 dòng, nơi không test nào với
 * tới và mỗi bản lặng lẽ rẽ một hướng (#2138 / #2074).
 *
 * Nên ở đây chỉ có PHÉP TÍNH, không có JSX. Mỗi màn tự vẽ theo bố cục của nó,
 * nhưng không màn nào được tự tính lại một con số.
 *
 * @param pricesIncludeTax  Tuỳ chọn HIỂN THỊ của quán (`prices_include_tax`) —
 *   không phải ảnh chụp `is_tax_included` của đơn. Hai thứ khác nhau, và chỗ lẫn
 *   chúng chính là lỗi làm dòng 端数調整 hiện `−tax_amount` trên mọi đơn 内税.
 * @param serviceChargeOverride  Phí phục vụ ƯỚC LƯỢNG phía client dùng cho màn
 *   xem trước lúc soạn giảm giá (server chốt lại con số thật khi áp mã). Bỏ
 *   trống = dùng `order.service_charge` đã lưu.
 */
export function orderTotals(
  order: CustomerOrder,
  pricesIncludeTax: boolean,
  serviceChargeOverride?: number | null,
): OrderTotals {
  const subtotal = Number(order.subtotal ?? 0) || 0;
  const hasBreakdown = (order.tax_breakdown ?? []).length > 0;

  // Quy đổi net→gross CHỈ khi engine lưu net mà quán lại hiện 税込.
  const showGrossSummary = pricesIncludeTax && hasBreakdown && !order.is_tax_included;
  const itemTax = itemTaxTotal(order.tax_breakdown);

  // Tách được phần thuế của phí phục vụ chỉ khi server có gửi breakdown; không
  // có thì để nguyên 0 và dòng thuế phẳng gánh tất cả — không gán nhầm cho phí.
  const serviceTax = hasBreakdown
    ? serviceTaxTotal(order.tax_amount, order.tax_breakdown)
    : 0;

  return {
    subtotal: showGrossSummary ? subtotal + itemTax : subtotal,
    subtotalWasConverted: showGrossSummary,
    discount: Number(order.discount_amount ?? 0) || 0,
    service: serviceChargeDisplay(
      serviceChargeOverride ?? order.service_charge,
      serviceTax,
      order.is_tax_included,
    ),
    serviceTax,
    itemTax,
    taxGroups: visibleTaxGroups(order.tax_breakdown)
      .slice()
      .sort((a, b) => Number(a.rate) - Number(b.rate)),
    taxIsInside: taxSitsInsideSubtotal(order.is_tax_included, showGrossSummary),
    hasBreakdown,
    rounding: roundingAdjustment(order),
    showRounding: showsRoundingAdjustment(roundingAdjustment(order)),
    taxDecimals: order.tax_rounding_decimals ?? null,
    total: Number(order.total_amount ?? 0) || 0,
  };
}
