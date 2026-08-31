/**
 * Equal-split redistribution — pure money math extracted from
 * `SplitBillEvenTab.handleAmountChange` (plan production-hardening, part
 * of godx-jp/godx-tempo#284).
 *
 * When staff manually overrides one guest's amount, the *remaining*
 * balance (order total − this row − any already-collected rows) is shared
 * equally across the other still-unpaid rows. The invariant that keeps the
 * till reconcilable is:
 *
 *     Σ(redistributed shares) === safeRemaining   (exactly, in minor units)
 *
 * i.e. after an edit the unpaid rows still add up to precisely what is left
 * to collect — no lost or invented sub-currency-unit.
 *
 * Currency-correctness (the reason this was extracted): the previous inline
 * version floored each share with `Math.floor(remaining / n)`, snapping to
 * whole *major* units. For VND/JPY/KRW (minor unit = 1) that is correct, but
 * for 2-decimal currencies (USD/EUR/CNY/THB) it silently discarded cents —
 * e.g. $9.00 across 2 guests produced $4 + $5 instead of $4.50 + $4.50.
 * Here we snap to the currency's true minor unit via `minorUnit()`, so
 * VND behaviour is byte-identical while decimal currencies keep their cents.
 * The rounding remainder always lands on the LAST redistributed row so the
 * Σ invariant holds exactly.
 */

import { minorUnit } from "@/lib/split-by-amount";

export interface EqualSplitRow {
  /** Current amount for the row, in major units (e.g. đồng / dollars). */
  amount: number;
  /**
   * True when the row can no longer change — it has been collected
   * (`succeeded`) or is mid-submit (`submitting`). Locked rows keep their
   * amount and are excluded from redistribution but still count toward the
   * already-committed sum.
   */
  locked: boolean;
}

export interface RedistributeEqualSplitInput {
  /** The full row set, in display order. */
  rows: EqualSplitRow[];
  /** Index of the row staff just edited. */
  index: number;
  /** The new amount typed into `rows[index]` (major units, already ≥ 0). */
  newAmount: number;
  /** Order total the snapshot was computed against (major units). */
  orderTotal: number;
  /** ISO 4217 currency code — drives the minor-unit snap granularity. */
  currency: string;
}

/**
 * Compute the new amount for every row after editing `rows[index]` to
 * `newAmount`. Returns a full array aligned to the input indices:
 *
 *   - `rows[index]`   → `newAmount` verbatim (staff typed it).
 *   - locked rows     → unchanged.
 *   - other unpaid    → an equal share of the remaining balance, snapped to
 *                       the currency minor unit, with the LAST such row
 *                       absorbing the rounding remainder.
 *
 * When the edited row + locked rows already meet or exceed the order total,
 * the remaining balance clamps at 0 and every redistributed row becomes 0
 * (the caller surfaces the resulting over-allocation banner).
 */
export function redistributeEqualSplit(
  input: RedistributeEqualSplitInput,
): number[] {
  const { rows, index, newAmount, orderTotal, currency } = input;
  const unit = minorUnit(currency);

  // Partition the other rows into locked (fixed) vs. redistributable.
  let lockedSum = 0;
  const redistributeIdxs: number[] = [];
  rows.forEach((r, i) => {
    if (i === index) return;
    if (r.locked) {
      lockedSum += Number(r.amount || 0);
    } else {
      redistributeIdxs.push(i);
    }
  });

  const remainingForOthers = orderTotal - newAmount - lockedSum;
  const safeRemaining = Math.max(0, remainingForOthers);
  const n = redistributeIdxs.length;

  // Work in integer minor units so the shares can't drift below the
  // currency's smallest denomination. `remMinor` is the exact pot to divide.
  const remMinor = Math.round(safeRemaining * unit);
  const baseMinor = n > 0 ? Math.floor(remMinor / n) : 0;
  // Last row absorbs the remainder → Σ === remMinor exactly.
  const lastMinor = n > 0 ? remMinor - baseMinor * (n - 1) : 0;
  const lastIdx = redistributeIdxs[redistributeIdxs.length - 1];

  return rows.map((r, i) => {
    if (i === index) return newAmount;
    if (r.locked) return Number(r.amount || 0);
    if (redistributeIdxs.includes(i)) {
      const minor = i === lastIdx ? lastMinor : baseMinor;
      return minor / unit;
    }
    return Number(r.amount || 0);
  });
}
