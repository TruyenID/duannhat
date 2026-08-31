/**
 * Cash tendered / change for ONE split-bill row.
 *
 * Every split mode (chia đều · theo số tiền · theo món) collects from several
 * people in sequence, and each of them can hand over a note bigger than their
 * share. Before this existed all three tabs posted `tendered_amount = amount`,
 * so every DB row and every printed slip said "khách đưa đúng, thối 0" no
 * matter what actually crossed the counter.
 *
 * This is the whole money rule, in one pure place, because it has to be
 * identical in three tabs and it is the number a customer reads on paper:
 *
 *   change    = tendered − amount        (never negative)
 *   shortfall = amount   − tendered      (never negative)
 *   valid     ⇔ tendered ≥ amount
 *
 * The servers agree independently — workstation `local_pos.go` and Cloud
 * `OrderPaymentService` both derive `change = tendered − amount − tip` and
 * REFUSE a tender below `amount + tip`. So an invalid tender here is a 422
 * there; the UI must not let it be submitted. (Split-bill rows carry no tip,
 * which is why tip is absent from these formulas — see `SplitPaymentBody`.)
 *
 * Rounding: amounts are snapped to the currency's minor unit before
 * subtracting, so a 2-decimal currency cannot produce 6.999999999 change.
 * VND/JPY (unit = 1) are unaffected.
 */

import { minorUnit } from "@/lib/split-by-amount";
import { getActiveCurrency } from "./totals";

/**
 * Hard ceiling on a recorded tender: Cloud's workstation payment route
 * validates `tendered_amount` as `max:99999999`, so a bigger figure cannot
 * survive the trip. A workstation would accept it locally and then dead-letter
 * the sync UP forever — a fat-fingered extra digit silently detaching a real
 * payment from Cloud. Refusing it at the keyboard is the only place it costs
 * nothing.
 */
export const MAX_TENDERED_AMOUNT = 99_999_999;

/**
 * Why a tender is unusable. The UI needs this to say something TRUE: an
 * unparseable box is not "short by the whole bill", and an over-cap figure is
 * not "short by 0" — both of which a single boolean produced.
 */
export type CashTenderProblem = "none" | "unparseable" | "short" | "too_large";

export interface CashTenderState {
  /**
   * What will be sent as `tendered_amount`. Equals `amount` when the field is
   * untouched/blank ("khách đưa đúng"), null when the text cannot be parsed.
   */
  tendered: number | null;
  /** Cash to hand back. 0 unless the tender exceeds the share. */
  change: number;
  /** How much is still missing. 0 unless the tender is below the share. */
  shortfall: number;
  /** False blocks "Thu" — submitting would be a guaranteed server 422. */
  valid: boolean;
  /** True when nothing was typed, i.e. the exact share is being tendered. */
  exact: boolean;
  /** `"none"` exactly when `valid`. Drives which message the row shows. */
  problem: CashTenderProblem;
}

/** Snap to the currency's smallest accountable unit (mirrors equal-split). */
function snap(value: number, unit: number): number {
  return Math.round(value * unit) / unit;
}

/**
 * Resolve a row's tender field.
 *
 * `raw` is the row's editable text, or `null` when the cashier has not touched
 * it. Untouched deliberately means "exact" rather than "unset": that keeps the
 * one-tap flow that existed before this feature (pick method → Thu) working
 * unchanged, and it is also what the field visibly shows.
 */
export function computeCashTender(
  raw: string | null | undefined,
  amount: number,
  currency?: string,
): CashTenderState {
  const unit = minorUnit(currency ?? getActiveCurrency());
  const owed = snap(Math.max(0, Number(amount) || 0), unit);

  const text = (raw ?? "").trim();
  if (text === "") {
    return {
      tendered: owed,
      change: 0,
      shortfall: 0,
      valid: true,
      exact: true,
      problem: "none",
    };
  }

  const parsed = Number(text);
  if (!Number.isFinite(parsed) || parsed < 0) {
    // Garbage in the box is NOT silently treated as "exact" — that would post
    // a number the cashier never agreed to. Block, and let the row say why.
    return {
      tendered: null,
      change: 0,
      shortfall: owed,
      valid: false,
      exact: false,
      problem: "unparseable",
    };
  }

  const tendered = snap(parsed, unit);
  if (tendered > MAX_TENDERED_AMOUNT) {
    return {
      tendered,
      change: 0,
      shortfall: 0,
      valid: false,
      exact: false,
      problem: "too_large",
    };
  }
  if (tendered < owed) {
    return {
      tendered,
      change: 0,
      shortfall: snap(owed - tendered, unit),
      valid: false,
      exact: false,
      problem: "short",
    };
  }

  return {
    tendered,
    change: snap(tendered - owed, unit),
    shortfall: 0,
    valid: true,
    exact: tendered === owed,
    problem: "none",
  };
}

/**
 * Denominations offered as one-tap "add to tender" chips. Same table the
 * regular PaymentDialog uses (#555 L1 — a VND shop must not be shown ₫50.000
 * tiles on a JPY till). Unknown currencies fall back to VND, matching the rest
 * of pos-web.
 */
const QUICK_TENDER_BY_CURRENCY: Record<string, number[]> = {
  VND: [50_000, 100_000, 200_000, 500_000],
  JPY: [1_000, 2_000, 5_000, 10_000],
  KRW: [5_000, 10_000, 50_000, 100_000],
  USD: [5, 10, 20, 50],
  EUR: [5, 10, 20, 50],
};

export function cashQuickTenders(currency?: string): number[] {
  const code = (currency ?? getActiveCurrency()).toUpperCase();
  return QUICK_TENDER_BY_CURRENCY[code] ?? QUICK_TENDER_BY_CURRENCY.VND!;
}

/** Compact chip label: 500000 → "500k", 1000000 → "1M", 20 → "20". */
export function formatQuickTenderLabel(amount: number): string {
  if (amount >= 1_000_000) return `${amount / 1_000_000}M`;
  if (amount >= 1_000) return `${amount / 1_000}k`;
  return String(amount);
}

/**
 * Next tender value after tapping a denomination chip. Additive on purpose —
 * cash arrives as a stack of notes ("200k + 200k + 50k"), so a tap adds rather
 * than replaces. An untouched field starts from the chip alone, NOT from the
 * exact share: the cashier is now counting real notes, and starting at the
 * share would silently inflate the recorded tender by the bill.
 */
export function addQuickTender(raw: string | null | undefined, chip: number): string {
  const current = Number((raw ?? "").trim());
  const base = Number.isFinite(current) && current > 0 ? current : 0;
  return String(base + chip);
}
