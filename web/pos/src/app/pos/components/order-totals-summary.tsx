import { useTranslation } from "@/providers/app-provider";
import { formatCurrency, formatTaxAmount } from "../lib/totals";
import { orderTotals } from "../lib/order-totals";
import { showsMoneyRow } from "../lib/tax-display";
import {
  IncludedTaxNote,
  ServiceChargeRow,
  TaxBreakdownRows,
} from "./order-cart";
import type { CustomerOrder } from "../types";

/**
 * Cột số của đơn, bản THU GỌN CHỈ ĐỌC cho hộp thoại thu tiền.
 *
 * ## Vì sao nó phải tồn tại
 *
 * Luồng một-chạm đưa thu ngân thẳng từ giỏ hàng vào màn thu tiền. Trước đó họ
 * đi ngang qua màn soạn giảm giá và nhìn thấy bảng tách ở đó; giờ thì không.
 * Không có khối này, người nhận tiền **không còn nhìn thấy thuế và phí phục vụ**
 * trước khi gõ số tiền khách đưa — thứ họ vẫn đọc to cho khách nghe.
 *
 * ## Vì sao nó KHÔNG tự tính gì
 *
 * Mọi con số đến từ {@link orderTotals}, đúng một hàm mà giỏ hàng cũng dùng, và
 * ba component dòng (`TaxBreakdownRows` · `ServiceChargeRow` ·
 * `IncludedTaxNote`) là chính những component giỏ hàng vẽ. Hai màn khác nhau về
 * bố cục, giống nhau tuyệt đối về số học — không có bản sao nào để lệch.
 *
 * Đó không phải sự cẩn thận thừa: thư mục này đã trả giá hai lần cho đúng kiểu
 * lệch ấy (#2138, #2074), và một lần nữa ở dòng 端数調整 vừa rồi.
 */
export function OrderTotalsSummary({
  order,
  pricesIncludeTax,
}: {
  order: CustomerOrder;
  pricesIncludeTax: boolean;
}) {
  const { t } = useTranslation();
  const totals = orderTotals(order, pricesIncludeTax);
  const fmtTax = (v: number) => formatTaxAmount(v, totals.taxDecimals);

  return (
    <div className="rounded-xl border bg-muted/30 px-3.5 py-3 text-sm">
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <span className="text-muted-foreground">{t("pos.cart.subtotal")}</span>
          <span className="font-semibold tabular-nums text-foreground">
            {/* Subtotal do giao diện quy đổi có thể mang phần lẻ dưới đơn vị
                tiền tệ; subtotal đã lưu thì không. Xem `subtotalWasConverted`. */}
            {totals.subtotalWasConverted
              ? fmtTax(totals.subtotal)
              : formatCurrency(totals.subtotal)}
          </span>
        </div>

        {showsMoneyRow(totals.discount) && (
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">{t("pos.cart.discount")}</span>
            <span className="font-semibold tabular-nums text-destructive">
              −{formatCurrency(totals.discount)}
            </span>
          </div>
        )}

        {/* Thuế là SỐ HẠNG chỉ khi nó chưa nằm trong subtotal. Ở 内税 nó xuống
            dòng ghi chú dưới tổng, không bao giờ cộng vào cột. */}
        {!totals.taxIsInside &&
          (totals.taxGroups.length > 0 ? (
            <TaxBreakdownRows
              breakdown={totals.taxGroups}
              includeTax={false}
              taxDecimals={totals.taxDecimals}
            />
          ) : (
            showsMoneyRow(order.tax_amount) && (
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">{t("pos.cart.tax")}</span>
                <span className="font-semibold tabular-nums text-foreground">
                  {fmtTax(Number(order.tax_amount ?? 0))}
                </span>
              </div>
            )
          ))}

        {showsMoneyRow(order.service_charge) && (
          <ServiceChargeRow
            gross={totals.service.gross}
            net={totals.service.net}
            tax={totals.serviceTax}
            taxDecimals={totals.taxDecimals}
          />
        )}

        {totals.showRounding && (
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">
              {t("pos.cart.rounding_adjustment")}
            </span>
            <span className="font-semibold tabular-nums text-foreground">
              {totals.rounding >= 0 ? "+" : "−"}
              {fmtTax(Math.abs(totals.rounding))}
            </span>
          </div>
        )}
      </div>

      <div className="mt-2.5 flex items-baseline justify-between gap-3 border-t pt-2.5">
        <span className="font-bold text-foreground">{t("pos.cart.total")}</span>
        <span className="text-xl font-extrabold tabular-nums text-foreground">
          {formatCurrency(totals.total)}
        </span>
      </div>

      {totals.taxIsInside && (
        <div className="mt-1">
          <IncludedTaxNote
            breakdown={totals.taxGroups}
            taxDecimals={totals.taxDecimals}
          />
        </div>
      )}
    </div>
  );
}
