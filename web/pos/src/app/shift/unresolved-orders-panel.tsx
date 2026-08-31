/**
 * Unresolved-orders panel — /shop/:shopSlug/shift/open.
 *
 * #2696. Orders still `paying`/`checkout` that lived past the previous shift's
 * close. Shown as a SEPARATE block from {@link GapReconcilePanel}:
 *
 *   gap          → money already taken, unattributed. Cashier claims it.
 *   this panel   → an order that may still be unpaid. Cashier must collect
 *                  the rest (or on-account / void-with-reason) — not "claim".
 *
 * Mixing the two would let a cashier "attribute" money that was never taken.
 *
 * Read-only and non-blocking: empty / still loading / fetch error renders
 * nothing and never stops the cashier opening a shift.
 *
 * #2738 — "còn treo" và "còn thiếu tiền" là HAI việc. Một đơn đã thu đủ mà kẹt
 * ở `paying` vẫn phải hiện (ai đó phải bấm đóng nó) nhưng tô nó rose-alarm là
 * báo động sai, và báo động sai dạy nhân viên bỏ qua báo động thật. Backend
 * (#2721) trả `needs_close_only` cho từng đơn + `totals.pending_close_count`;
 * panel chỉ ĐỌC cờ đó, không tự suy từ `outstanding_amount === 0`.
 */

import {
  Badge,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@godxjp/ui";
import { HelpButton } from "@/help/help-button";
import { useUnresolvedOrders } from "@/hooks/api/use-till";
import { CURRENCIES, type CurrencyCode, formatMoney } from "./currency";

export function UnresolvedOrdersPanel({
  shopSlug,
  fallbackCurrency,
  t,
}: {
  shopSlug: string;
  fallbackCurrency: CurrencyCode;
  t: (k: string, p?: Record<string, string>) => string;
}) {
  const preview = useUnresolvedOrders(shopSlug);
  const orders = preview.data?.orders ?? [];

  const cur =
    (preview.data?.currency_code &&
      preview.data.currency_code in CURRENCIES &&
      CURRENCIES[preview.data.currency_code as CurrencyCode]) ||
    CURRENCIES[fallbackCurrency];

  if (!preview.data || !preview.data.previous_session || orders.length === 0) {
    return null;
  }

  // Cả rổ đã thu đủ ⇒ đây là việc dọn sổ, không phải báo động tiền. Một đơn
  // thiếu tiền là đủ để giữ nguyên cảnh báo đỏ cho cả panel. Cờ thiếu (backend
  // cũ) ⇒ `false` ⇒ giữ hành vi cảnh báo cũ, không âm thầm hạ tông.
  const needsCloseOnly = orders.every((o) => o.needs_close_only === true);
  // Trong nhánh này mọi đơn đều `needs_close_only`, nên `orders.length` chính
  // là con số đó — dùng khi backend chưa gửi tổng.
  const pendingCloseCount =
    preview.data.totals.pending_close_count ?? orders.length;

  return (
    <Card
      className={
        needsCloseOnly
          ? "mb-4 gap-0 p-0"
          : "mb-4 gap-0 border-rose-200 p-0 dark:border-rose-700/40"
      }
    >
      <CardHeader
        className={
          needsCloseOnly
            ? "flex flex-row items-center justify-between border-b bg-muted/40 px-5 py-4"
            : "flex flex-row items-center justify-between border-b border-rose-200/70 bg-rose-50/60 px-5 py-4 dark:border-rose-700/30 dark:bg-rose-950/20"
        }
      >
        <CardTitle
          className={
            needsCloseOnly
              ? "text-[15px] font-semibold text-foreground"
              : "text-[15px] font-semibold text-rose-900 dark:text-rose-100"
          }
        >
          {t(
            needsCloseOnly
              ? "shift.open.unresolved.section_close_only"
              : "shift.open.unresolved.section",
          )}
        </CardTitle>
        <div className="flex items-center gap-1">
          <Badge
            variant="secondary"
            className="text-[11px] font-medium tabular-nums"
          >
            {needsCloseOnly
              ? t("shift.open.unresolved.pending_close_badge", {
                  count: String(pendingCloseCount),
                })
              : t("shift.open.unresolved.count_badge", {
                  count: String(orders.length),
                })}
          </Badge>
          <HelpButton topic="unresolved-orders" className="size-7" />
        </div>
      </CardHeader>
      <CardContent className="px-5 py-5">
        <p className="mb-4 text-[13px] leading-relaxed text-muted-foreground">
          {t(
            needsCloseOnly
              ? "shift.open.unresolved.description_close_only"
              : "shift.open.unresolved.description",
          )}
        </p>

        <div className="overflow-hidden rounded-md border">
          {orders.map((o, i) => {
            // Từng dòng tự quyết tông — rổ có thể pha trộn (đơn thiếu tiền +
            // đơn chỉ chờ đóng), và dòng đã thu đủ không được tô như nợ.
            const rowCloseOnly = o.needs_close_only === true;
            return (
              <div
                key={o.id}
                className={
                  i > 0
                    ? "flex items-center gap-3 border-t px-3.5 py-3 text-[14px]"
                    : "flex items-center gap-3 px-3.5 py-3 text-[14px]"
                }
              >
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <div className="flex items-center gap-2">
                    <span className="truncate font-medium tabular-nums">
                      {o.order_code || o.id}
                    </span>
                    <Badge
                      variant="secondary"
                      className="text-[10px] font-medium uppercase"
                    >
                      {t(`shift.open.unresolved.status.${o.status}`)}
                    </Badge>
                    {o.table_released ? (
                      <Badge
                        variant="outline"
                        className={
                          rowCloseOnly
                            ? "text-[10px] font-medium text-muted-foreground"
                            : "border-rose-300 text-[10px] font-medium text-rose-700 dark:border-rose-600/50 dark:text-rose-300"
                        }
                      >
                        {t("shift.open.unresolved.table_released")}
                      </Badge>
                    ) : null}
                  </div>
                  <span className="text-[11px] tabular-nums text-muted-foreground">
                    {formatDateTime(o.created_at)}
                    {" · "}
                    {/* Dòng settled dùng bản KHÔNG có chữ "đã thu" (#2770):
                        cột phải ngay bên cạnh đã nói đúng chữ đó rồi. Bản JA
                        lặp nặng nhất — `¥700 / ¥700 入金済` đứng cạnh `入金済`. */}
                    {t(
                      rowCloseOnly
                        ? "shift.open.unresolved.paid_of_total_settled"
                        : "shift.open.unresolved.paid_of_total",
                      {
                        paid: formatMoney(o.paid_amount, cur),
                        total: formatMoney(o.total_amount, cur),
                      },
                    )}
                  </span>
                </div>
                <div className="text-right">
                  <div className="text-[11px] text-muted-foreground">
                    {t(
                      rowCloseOnly
                        ? "shift.open.unresolved.settled"
                        : "shift.open.unresolved.outstanding",
                    )}
                  </div>
                  {/* #2770 — dòng đã thu đủ KHÔNG in số. Cột này luôn là
                      `outstanding_amount`, nên với đơn settled nó ra `¥0` ngay
                      dưới nhãn "Đã thu đủ": đọc thành "đã thu 0 đồng", trong
                      khi đơn ấy thu 700. Cột phải là chỗ mắt liếc trước tiên,
                      và nó đang trưng một con số trông như sai.

                      Bỏ số chứ không đổi nhãn: số THẬT đã nằm ở dòng phụ
                      (`¥700 / ¥700`), nên không mất thông tin nào. Đúng mục
                      đích #2738 — thôi bắt nhân viên đọc một con số vô nghĩa,
                      không phải đổi màu cho đẹp. */}
                  {rowCloseOnly ? null : (
                    <div className="font-semibold tabular-nums text-rose-800 dark:text-rose-200">
                      {formatMoney(o.outstanding_amount, cur)}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </CardContent>
    </Card>
  );
}

function formatDateTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
