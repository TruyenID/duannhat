/**
 * Plan-038 T5.1 — split-bill "Theo số tiền" pure helpers.
 *
 * The tab UI is a controlled list of rows where each row carries an
 * editable amount. These helpers compute the footer summary (allocated /
 * total / drift) and validate the row set before "Thu" is enabled.
 *
 * Currency-aware: VND has 0 decimals (minor unit = 1 đ); USD/EUR have 2
 * (minor unit = 0.01). The comparison happens in minor units to dodge
 * floating-point drift.
 */

import type { PersonBillByAmount } from "@/app/pos/types";

/** Minor-unit granularity per ISO 4217 (subset used by the platform). */
export function minorUnit(currencyCode: string): number {
  switch (currencyCode.toUpperCase()) {
    case "JPY":
    case "VND":
    case "KRW":
      return 1;
    default:
      return 100; // USD / EUR / SGD / etc — 2 decimals
  }
}

export interface ByAmountFooter {
  /** Σ of row amounts (minor units). */
  sumAllocated: number;
  /** Order total, snapshot at dialog open (minor units). */
  orderTotal: number;
  /** sumAllocated - orderTotal — positive over, negative under, 0 balanced. */
  drift: number;
  /** True when drift === 0 AND every row is > 0 AND there are ≥ 2 rows. */
  balanced: boolean;
}

export function computeByAmountFooter(input: {
  rows: PersonBillByAmount[];
  orderTotal: number;
  currency: string;
}): ByAmountFooter {
  const { rows, orderTotal, currency } = input;
  const unit = minorUnit(currency);

  // Sum row amounts, snapped to currency minor unit so display values can
  // be expressed in either major or minor form without rounding drift.
  const sumAllocated = rows.reduce(
    (acc, r) => acc + snap(r.amount, unit),
    0,
  );
  const snappedTotal = snap(orderTotal, unit);
  const drift = sumAllocated - snappedTotal;

  const allPositive = rows.every((r) => snap(r.amount, unit) > 0);
  const balanced = drift === 0 && allPositive && rows.length >= 2;

  return {
    sumAllocated,
    orderTotal: snappedTotal,
    drift,
    balanced,
  };
}

/** Round to the currency's minor unit. JPY/VND snap to integer, USD to cent. */
function snap(value: number, unit: number): number {
  if (unit === 1) return Math.round(value);
  // Round-half-away-from-zero on cents to match typical POS behaviour.
  return Math.round(value * unit) / unit;
}

/** Spawn a fresh row at the end of the list. UUID v4 idempotency key. */
export function seedRow(input: {
  billIndex: number;
  totalBills: number;
  defaultLabel: string;
}): PersonBillByAmount {
  return {
    bill_index: input.billIndex,
    total_bills: input.totalBills,
    label: input.defaultLabel,
    amount: 0,
    method_id: null,
    idempotency_key: cryptoRandomUUID(),
    status: "draft",
  };
}

/** Lightweight UUID v4 — avoids importing `uuid` for one helper. */
export function cryptoRandomUUID(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  // Fallback for jsdom test envs without crypto.randomUUID.
  const bytes = new Uint8Array(16);
  for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const h = Array.from(bytes, (b) => b.toString(16).padStart(2, "0"));
  return `${h.slice(0, 4).join("")}-${h.slice(4, 6).join("")}-${h.slice(6, 8).join("")}-${h.slice(8, 10).join("")}-${h.slice(10, 16).join("")}`;
}
