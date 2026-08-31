/**
 * DenominationCounter — Plan 030 shared between Shift Open + Close screens.
 *
 * Renders a 3-column denomination table (mệnh giá / qty stepper / subtotal)
 * grouped Tiền giấy/紙幣 + Tiền xu/硬貨, plus a prominent total bar
 * (primary-soft, large tabular total). Caller is expected to wrap this in a
 * Card / CardContent — the component itself renders no chrome of its own.
 *
 * Currency formatting is delegated to ./currency so Open + Close stay
 * pixel-identical.
 */

import { useMemo } from "react";
import { Button, Input } from "@godxjp/ui";
import { MinusIcon, PlusIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import type { Denomination } from "@/services/till-service";
import {
  type CurrencyConfig,
  denomLabel,
  formatMoney,
  getCurrencyConfig,
} from "./currency";

export interface DenominationCounterProps {
  denominations: Denomination[];
  values: Record<string, number>;
  onChange: (next: Record<string, number>, total: number) => void;
  disabled?: boolean;
  /** Optional currency override; defaults to the first denomination's currency_code. */
  currencyCode?: string;
  /**
   * Where the total bar sits relative to the denomination grid. "bottom"
   * (default) is the running-total-below layout the shift-open screen uses;
   * "top" puts it directly under the card header (shift-close asks for the
   * balance line first).
   */
  totalPosition?: "top" | "bottom";
}

export function DenominationCounter({
  denominations,
  values,
  onChange,
  disabled,
  currencyCode,
  totalPosition = "bottom",
}: DenominationCounterProps) {
  const { t } = useTranslation();
  const cur = useMemo(
    () => getCurrencyConfig(currencyCode ?? denominations[0]?.currency_code),
    [currencyCode, denominations],
  );

  const grouped = useMemo(() => {
    const notes = denominations.filter((d) => d.kind === "note");
    const coins = denominations.filter((d) => d.kind === "coin");
    return { notes, coins };
  }, [denominations]);

  function commit(id: string, qty: number) {
    if (Number.isNaN(qty)) qty = 0;
    if (qty < 0) qty = 0;
    qty = Math.floor(qty);
    const next = { ...values, [id]: qty };
    const total = denominations.reduce(
      (sum, d) => sum + d.value * (next[d.id] ?? 0),
      0,
    );
    const rounded =
      cur.decimals > 0
        ? Math.round(total * Math.pow(10, cur.decimals)) /
          Math.pow(10, cur.decimals)
        : Math.round(total);
    onChange(next, rounded);
  }

  const totals = useMemo(() => {
    let total = 0;
    let totalCount = 0;
    let paperCount = 0;
    let coinCount = 0;
    for (const d of denominations) {
      const q = values[d.id] ?? 0;
      total += d.value * q;
      totalCount += q;
      if (d.kind === "note") paperCount += q;
      else coinCount += q;
    }
    if (cur.decimals > 0) {
      const f = Math.pow(10, cur.decimals);
      total = Math.round(total * f) / f;
    }
    return { total, totalCount, paperCount, coinCount };
  }, [denominations, values, cur.decimals]);

  if (denominations.length === 0) {
    return (
      <div className="rounded-md border bg-card px-3.5 py-6 text-center text-[13px] text-muted-foreground">
        {t("shift.open.count.empty_loading")}
      </div>
    );
  }

  // Total bar — positioned above or below the grid per `totalPosition`.
  const totalBar = (
    <div className="flex items-center justify-between gap-4 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3.5">
      <div className="min-w-0 flex flex-col gap-0.5">
        <div className="text-[13px] font-semibold text-primary">
          {t("shift.open.total.label")}
        </div>
        <div className="text-[12px] tabular-nums text-muted-foreground">
          <span>
            {totals.totalCount.toLocaleString(cur.locale)}{" "}
            {t("shift.open.total.unit_total")}
          </span>{" "}
          ・{" "}
          <span>
            {totals.paperCount.toLocaleString(cur.locale)}{" "}
            {t("shift.open.total.unit_paper")}
          </span>{" "}
          ・{" "}
          <span>
            {totals.coinCount.toLocaleString(cur.locale)}{" "}
            {t("shift.open.total.unit_coin")}
          </span>
        </div>
      </div>
      <div
        className="text-[26px] font-bold tabular-nums tracking-tight text-primary"
        aria-live="polite"
      >
        {formatMoney(totals.total, cur)}
      </div>
    </div>
  );

  return (
    <div>
      {totalPosition === "top" && <div className="mb-4">{totalBar}</div>}
      <div className="overflow-hidden rounded-md border">
        {/* Table header */}
        <div className="grid grid-cols-[1.4fr_1.6fr_1fr] gap-3 border-b bg-muted/40 px-3.5 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          <div>
            {t("shift.open.count.col_denom")}
            <span className="ml-1 normal-case tracking-normal text-muted-foreground/70">
              ({cur.code})
            </span>
          </div>
          <div className="text-center">{t("shift.open.count.col_qty")}</div>
          <div className="text-right">
            {t("shift.open.count.col_subtotal")}
          </div>
        </div>

        {grouped.notes.length > 0 && (
          <Group
            label={t("shift.open.count.group_notes")}
            rows={grouped.notes}
            cur={cur}
            values={values}
            commit={commit}
            disabled={disabled}
            t={t}
          />
        )}
        {grouped.coins.length > 0 && (
          <Group
            label={t("shift.open.count.group_coins")}
            rows={grouped.coins}
            cur={cur}
            values={values}
            commit={commit}
            disabled={disabled}
            t={t}
          />
        )}
      </div>

      {totalPosition !== "top" && <div className="mt-4">{totalBar}</div>}
    </div>
  );
}

function Group({
  label,
  rows,
  cur,
  values,
  commit,
  disabled,
  t,
}: {
  label: string;
  rows: Denomination[];
  cur: CurrencyConfig;
  values: Record<string, number>;
  commit: (id: string, qty: number) => void;
  disabled?: boolean;
  t: (k: string, p?: Record<string, string>) => string;
}) {
  return (
    <>
      <div className="border-b bg-muted/40 px-3.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </div>
      {rows.map((d) => {
        const q = values[d.id] ?? 0;
        const sub = d.value * q;
        const zero = q === 0;
        const dl = denomLabel(d, cur);
        return (
          <div
            key={d.id}
            className="grid grid-cols-[1.4fr_1.6fr_1fr] items-center gap-3 border-b px-3.5 py-2.5 last:border-b-0"
          >
            <div className="flex items-center gap-2">
              <span className="text-[15px] font-semibold tabular-nums">
                {dl}
              </span>
            </div>
            <div className="flex items-center justify-center gap-1.5">
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => commit(d.id, q - 1)}
                disabled={disabled || q <= 0}
                aria-label={t("shift.open.count.dec")}
                className="h-8 w-8"
              >
                <MinusIcon className="size-4" />
              </Button>
              <Input
                type="text"
                inputMode="numeric"
                value={String(q)}
                onChange={(e) => {
                  const raw = e.target.value.replace(/[^\d]/g, "");
                  commit(d.id, raw === "" ? 0 : parseInt(raw, 10));
                }}
                disabled={disabled}
                aria-label={t("shift.open.count.qty_label", { denom: dl })}
                className="h-8 w-16 text-center text-[14px] font-semibold tabular-nums"
              />
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => commit(d.id, q + 1)}
                disabled={disabled}
                aria-label={t("shift.open.count.inc")}
                className="h-8 w-8"
              >
                <PlusIcon className="size-4" />
              </Button>
            </div>
            <div
              className={cn(
                "text-right tabular-nums",
                zero
                  ? "font-normal text-muted-foreground"
                  : "font-medium text-foreground",
              )}
            >
              {formatMoney(sub, cur)}
            </div>
          </div>
        );
      })}
    </>
  );
}
