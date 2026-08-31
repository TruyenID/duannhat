"use client";

import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatCurrency } from "@/lib/currency";
import { formatDateTime } from "@/lib/date";
import type { CustomerOrder } from "@/services/order-service";

export interface OrderChargeSummaryProps {
  order: CustomerOrder;
  /**
   * Render the paid / outstanding rows and the per-payment breakdown.
   * Both shop and HQ detail views set this — the data (`payments[]`) is
   * eager-loaded on order detail responses for both scopes.
   */
  showPayments?: boolean;
}

function num(value: number | string | null | undefined): number {
  return Number(value ?? 0);
}

export function OrderChargeSummary({ order, showPayments = true }: OrderChargeSummaryProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  // #555 M7 — the currency follows the ORDER (ISO code resolved from its
  // branch), never a hardcoded ¥: a VND shop's order must render ₫.
  const currency = order.currency ?? "JPY";
  const money = (v: number | string | null | undefined) => formatCurrency(v, locale, currency);

  // plan-045 option-B — TAX figures carry sub-unit precision per the order's
  // tax_rounding_decimals (e.g. ¥93.50 on a JPY shop with decimals=2); render
  // them with that many fraction digits while whole-yen figures (subtotal /
  // total) use `money`. The payable total stays whole; the remainder is the
  // 端数調整 row below.
  const taxDecimals = order.tax_rounding_decimals ?? 0;
  const taxMoney = (v: number | string | null | undefined) =>
    formatCurrency(v, locale, currency, taxDecimals);

  const discount = num(order.discount_amount);
  const serviceCharge = num(order.service_charge);
  const tax = num(order.tax_amount);
  const tip = num(order.total_tip);
  // plan-043 audit B-III.8 — the service-charge tax residual (owns no line).
  // Only rendered when the backend supplies it (fully-stamped orders).
  const serviceChargeTax =
    order.service_charge_tax == null ? null : num(order.service_charge_tax);

  // plan-043 — per-rate tax breakdown. When present (and non-empty), render one
  // line per rate; otherwise fall back to the single legacy tax line below.
  const taxBreakdown = (order.tax_breakdown ?? []).filter(
    (b) => num(b.tax) > 0 || num(b.taxable) > 0
  );
  const hasBreakdown = taxBreakdown.length > 0;
  const taxIncluded = order.is_tax_included === true;
  // Format a rate like 10 or 8.5 without a trailing ".00".
  const fmtRate = (r: number | string) => String(num(r));

  // plan-045 — total refunded (server-computed, mode-correct): sum the signed
  // `refund` condition amounts (each negative). We read this aggregate from the
  // condition ledger rather than dumping the raw rows into the UI — the raw
  // rows would just duplicate the tax breakdown / discount / refund lines.
  const refundTotal = (order.conditions ?? [])
    .filter((c) => c.type === "refund")
    .reduce((sum, c) => sum + num(c.amount), 0);
  const hasRefund = refundTotal < 0;

  // plan-045 — the immutable tax-rounding rule snapshotted onto the order,
  // shown as a small note under the tax figure for reconciliation transparency.
  // rev-B — the resource normalizes the snapshot to round/ceil/floor (legacy
  // half_up/round_up/round_down included) and coerces decimals to 0, so this
  // renders a known label for every order.
  const roundingMode = order.tax_rounding_mode ?? null;
  const knownRoundingMode =
    roundingMode === "round" || roundingMode === "ceil" || roundingMode === "floor";
  const showRoundingNote = knownRoundingMode && (hasBreakdown || tax > 0);
  const roundingDecimalsLabel = t("order.tax_rounding_decimals_digits", {
    n: String(order.tax_rounding_decimals ?? 0),
  });

  // plan-045 option-B — 端数調整 (tax-rounding remainder). The exact pre-round
  // grand total is subtotal − discount + service_charge(base) + tax_amount
  // (precise); the payable total_amount is rounded to whole yen. This books the
  // difference so the column still sums to the total.
  const roundingAdj =
    num(order.total_amount) - (num(order.subtotal) - discount + serviceCharge + tax);
  const showRoundingAdj = Math.abs(roundingAdj) >= 0.005;

  const succeededPayments = (order.payments ?? []).filter((p) => p.status === "succeeded");
  const paid = succeededPayments.reduce((sum, p) => sum + num(p.amount), 0);
  const remaining = num(order.total_amount) - paid;

  return (
    <dl
      data-slot="order-charge-summary"
      className="grid grid-cols-[140px_1fr] gap-x-4 gap-y-2 text-sm"
    >
      <dt className="text-muted-foreground">{t("order.subtotal")}</dt>
      <dd className="tabular-nums">{money(order.subtotal)}</dd>

      {discount > 0 && (
        <>
          <dt className="text-muted-foreground">
            {t("order.discount")}
            {order.coupon_code_snapshot && (
              <span className="ml-1.5 inline-flex items-center rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium">
                {order.coupon_code_snapshot}
              </span>
            )}
          </dt>
          <dd className="text-red-600 tabular-nums">−{money(discount)}</dd>
        </>
      )}

      {serviceCharge > 0 && (
        <>
          <dt className="text-muted-foreground">{t("order.service_charge")}</dt>
          <dd className="tabular-nums">{money(serviceCharge)}</dd>
        </>
      )}

      {serviceChargeTax != null && serviceChargeTax > 0 && (
        <>
          <dt className="pl-3 text-xs text-muted-foreground">
            {t("order.service_charge_tax")}
          </dt>
          <dd className="text-xs tabular-nums">{taxMoney(serviceChargeTax)}</dd>
        </>
      )}

      {hasBreakdown ? (
        <>
          <dt className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
            {t("order.tax")}
            <span
              className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${
                taxIncluded ? "bg-emerald-50 text-emerald-700" : "bg-amber-50 text-amber-700"
              }`}
            >
              {taxIncluded ? t("order.tax_included") : t("order.tax_excluded")}
            </span>
          </dt>
          <dd className="space-y-0.5 tabular-nums">
            {taxBreakdown.map((b, i) => (
              <div key={`${b.rate}-${i}`} className="flex flex-wrap items-baseline gap-x-2">
                <span className="text-xs text-muted-foreground">
                  {t("order.tax_rate_row", { rate: fmtRate(b.rate), taxable: money(b.taxable) })}
                </span>
                <span>{taxMoney(b.tax)}</span>
              </div>
            ))}
          </dd>
        </>
      ) : (
        tax > 0 && (
          <>
            <dt className="text-muted-foreground">{t("order.tax")}</dt>
            <dd className="tabular-nums">{taxMoney(tax)}</dd>
          </>
        )
      )}

      {showRoundingNote && (
        <>
          <dt className="pl-3 text-xs text-muted-foreground">{t("order.tax_rounding_label")}</dt>
          <dd className="text-xs text-muted-foreground">
            {t(`order.tax_rounding_mode_${roundingMode}`)} · {roundingDecimalsLabel}
          </dd>
        </>
      )}

      {tip > 0 && (
        <>
          <dt className="text-muted-foreground">{t("order.tip")}</dt>
          <dd className="tabular-nums">{money(tip)}</dd>
        </>
      )}

      {showRoundingAdj && (
        <>
          <dt className="text-xs text-muted-foreground">
            {t("order.rounding_adjustment")}
          </dt>
          <dd className="text-xs tabular-nums text-muted-foreground">
            {roundingAdj >= 0 ? "+" : "−"}
            {taxMoney(Math.abs(roundingAdj))}
          </dd>
        </>
      )}

      <dt className="font-semibold text-muted-foreground">{t("order.total")}</dt>
      <dd className="font-semibold tabular-nums">{money(order.total_amount)}</dd>

      {/* plan-045 — refunds are ALREADY netted into subtotal / tax / total (the
          engine folds the negative refund lines in), and the refund lines
          themselves render in the line-item list. So this is an INFORMATIONAL
          footnote below the total — not a running-total deduction — telling the
          operator how much of this order was refunded. */}
      {hasRefund && (
        <>
          <dt className="text-xs text-muted-foreground">{t("order.refund_total")}</dt>
          <dd className="text-xs text-muted-foreground tabular-nums">
            −{money(Math.abs(refundTotal))}
          </dd>
        </>
      )}

      {showPayments && remaining > 0 && paid > 0 && (
        <>
          <dt className="text-muted-foreground">{t("order.paid")}</dt>
          <dd className="tabular-nums">{money(paid)}</dd>
          <dt className="font-semibold text-red-600">{t("order.remaining")}</dt>
          <dd className="font-semibold text-red-600 tabular-nums">{money(remaining)}</dd>
        </>
      )}

      {showPayments && succeededPayments.length > 0 && (
        <>
          <dt className="text-muted-foreground">
            {t("order.payment")}
            {succeededPayments.length > 1 && (
              <span className="ml-1.5 inline-flex items-center rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-primary">
                {succeededPayments.length}
              </span>
            )}
          </dt>
          <dd className="space-y-1">
            {succeededPayments.map((p) => {
              const label = p.payment_method?.name ?? p.payment_method?.code ?? t("order.payment");
              const flowLabel =
                p.note === "split_bill"
                  ? t("order.flow.split_bill")
                  : p.note === "full_payment"
                    ? t("order.flow.full_payment")
                    : null;
              return (
                <div key={p.id} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                  <span className="font-medium">{label}</span>
                  {flowLabel && (
                    <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium">
                      {flowLabel}
                    </span>
                  )}
                  <span className="tabular-nums">{money(p.amount)}</span>
                  {p.paid_at && (
                    <span className="text-xs text-muted-foreground">
                      · {formatDateTime(p.paid_at, locale, timezone)}
                    </span>
                  )}
                  {p.reference_no && (
                    <span
                      className="font-mono text-[10px] text-muted-foreground/70"
                      title={p.reference_no}
                    >
                      (
                      {p.reference_no.length > 16
                        ? `${p.reference_no.slice(0, 16)}…`
                        : p.reference_no}
                      )
                    </span>
                  )}
                </div>
              );
            })}
          </dd>
        </>
      )}
    </dl>
  );
}
