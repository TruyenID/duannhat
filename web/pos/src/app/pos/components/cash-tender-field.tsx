/**
 * CashTenderField — "khách đưa bao nhiêu / thối lại bao nhiêu" for ONE
 * split-bill row.
 *
 * Rendered by all three split tabs (chia đều · theo số tiền · theo món) and
 * ONLY when the row's chosen method has `requires_tendered` — a card or
 * transfer row has no cash to count, and showing the box there would invite a
 * number that means nothing on the slip.
 *
 * Deliberately compact: it sits inside a person card that already carries a
 * method picker and a Thu button. One line of input + chips + one line of
 * verdict.
 *
 * All money math lives in `../lib/cash-tender` so the three tabs and this
 * component cannot drift from one another.
 */

import { Button, Input } from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import {
  MAX_TENDERED_AMOUNT,
  addQuickTender,
  cashQuickTenders,
  computeCashTender,
  formatQuickTenderLabel,
} from "../lib/cash-tender";
import { formatCurrency, getCurrencySymbol } from "../lib/totals";

export interface CashTenderFieldProps {
  /** What this guest owes — the row's share. */
  amount: number;
  /** Row's tender text. `null` = untouched, i.e. "đưa đúng". */
  value: string | null;
  onChange: (next: string | null) => void;
  disabled?: boolean;
  /** ISO 4217 override; defaults to the active session currency. */
  currency?: string;
  /** 1-based guest number — only used to label the input for screen readers. */
  guestIndex?: number;
  className?: string;
}

export function CashTenderField({
  amount,
  value,
  onChange,
  disabled = false,
  currency,
  guestIndex,
  className,
}: CashTenderFieldProps) {
  const { t } = useTranslation();
  const tender = computeCashTender(value, amount, currency);
  const chips = cashQuickTenders(currency);

  return (
    <div
      data-slot="cash-tender-field"
      className={cn("bg-muted/30 space-y-2 rounded-lg border p-2.5", className)}
    >
      <div className="flex items-center gap-2">
        <span className="text-muted-foreground shrink-0 text-xs font-semibold">
          {t("pos.split_bill.cash.tendered")}
        </span>
        <div className="flex min-w-0 flex-1 items-center gap-1">
          <Input
            type="number"
            inputMode="decimal"
            min={0}
            // An untouched field SHOWS the exact share — what would be recorded
            // if the cashier just hits Thu. A placeholder instead would leave
            // the recorded number invisible until after the fact.
            value={value ?? String(amount)}
            onChange={(e) => onChange(e.target.value)}
            onFocus={(e) => e.target.select()}
            disabled={disabled}
            aria-label={t("pos.split_bill.cash.tendered_aria", {
              n: guestIndex ?? 1,
            })}
            className={cn(
              "bg-background h-9 min-w-0 flex-1 rounded-md text-right text-sm font-bold tabular-nums",
              "[appearance:textfield] [&::-webkit-inner-spin-button]:m-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:m-0 [&::-webkit-outer-spin-button]:appearance-none",
              !tender.valid && "border-red-400 focus-visible:ring-red-400/40",
            )}
          />
          <span className="text-muted-foreground shrink-0 text-xs font-semibold">
            {getCurrencySymbol(currency)}
          </span>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-1">
        {chips.map((chip) => (
          <Button
            key={chip}
            type="button"
            variant="outline"
            size="sm"
            disabled={disabled}
            onClick={() => onChange(addQuickTender(value, chip))}
            className="h-7 rounded-md px-2 text-[11px] font-semibold tabular-nums"
          >
            +{formatQuickTenderLabel(chip)}
          </Button>
        ))}
        {value !== null && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={disabled}
            onClick={() => onChange(null)}
            className="h-7 rounded-md px-2 text-[11px] font-semibold"
          >
            {t("pos.split_bill.cash.reset")}
          </Button>
        )}
      </div>

      {/* Verdict line — always present so the row's height doesn't jump as the
          cashier types, and so "thối 0" is a stated fact rather than silence.
          Each failure says what is actually wrong: a box holding letters is not
          "short by the whole bill", and an over-cap figure is not "short by 0". */}
      {tender.problem === "none" ? (
        <div className="flex items-center justify-between gap-2 text-xs">
          <span className="text-muted-foreground">
            {t("pos.split_bill.cash.change")}
          </span>
          <span
            className={cn(
              "font-bold tabular-nums",
              tender.change > 0
                ? "text-emerald-600 dark:text-emerald-400"
                : "text-muted-foreground",
            )}
          >
            {formatCurrency(tender.change, currency)}
          </span>
        </div>
      ) : tender.problem === "short" ? (
        <div className="flex items-center justify-between gap-2 text-xs">
          <span className="font-semibold text-red-600 dark:text-red-400">
            {t("pos.split_bill.cash.shortfall")}
          </span>
          <span className="font-bold tabular-nums text-red-600 dark:text-red-400">
            {formatCurrency(tender.shortfall, currency)}
          </span>
        </div>
      ) : (
        <div className="text-xs font-semibold text-red-600 dark:text-red-400">
          {tender.problem === "too_large"
            ? t("pos.split_bill.cash.too_large", {
                max: formatCurrency(MAX_TENDERED_AMOUNT, currency),
              })
            : t("pos.split_bill.cash.invalid")}
        </div>
      )}
    </div>
  );
}
