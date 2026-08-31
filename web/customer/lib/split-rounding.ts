/**
 * Pure, dependency-free split-bill rounding math.
 *
 * Extracted out of `lib/currency.ts` (which is a "use client" module pulling in
 * React + brand-context) so the money math can be unit-tested in isolation.
 *
 * IMPORTANT — this is audit-relevant, NOT display-only. The value produced by
 * `roundUpToStep(finalRemaining / numPeople, step)` is the exact amount charged
 * to each payer (sent to the backend `POST .../payments` and to Stripe as the
 * minor-unit charge). A change of rounding mode/step changes what a customer
 * actually pays, so these functions must stay exact. Keep them pure and tested.
 */

export type SplitBillRoundingMode = "auto" | "integer" | "two_decimals" | "none";

/** Currencies whose smallest circulating unit IS the integer (no minor unit). */
export const ZERO_FRACTION = new Set([
  "JPY",
  "VND",
  "KRW",
  "IDR",
  "LAK",
  "MMK",
  "UGX",
  "RWF",
  "BIF",
  "KMF",
  "PYG",
  "CLP",
  "COP",
  // plan-043 audit fix 3.5 (2026-07-14): these five were present in the
  // backend RoundingMode + pos-web lists but missing here, so a shop in one
  // of these currencies previewed split amounts at a 0.01 step while the
  // backend charged at step 1 — a guaranteed 1-unit mismatch at payment.
  "GNF",
  "VUV",
  "XAF",
  "XOF",
  "XPF",
]);

/** Currencies that use 3 decimal places (mils). */
export const THREE_DECIMAL = new Set(["KWD", "BHD", "OMR", "JOD", "TND", "IQD"]);

/**
 * Derive the rounding step for dine-in split-bill calculations.
 *
 * - auto: derive from currency (0-decimal → 1, 3-decimal → 0.001, else 0.01)
 * - integer: always round up to whole units (step = 1)
 * - two_decimals: always round up to 2 decimal places (step = 0.01)
 * - none: no rounding (step = 0) for 0-decimal currencies; behaves like
 *   two_decimals for others because Stripe requires minor units (cents).
 */
export function getRoundingStep(currency: string, mode: SplitBillRoundingMode): number {
  const code = currency.toUpperCase();
  const isZeroFraction = ZERO_FRACTION.has(code);
  const isThreeDecimal = THREE_DECIMAL.has(code);

  switch (mode) {
    case "integer":
      return 1;
    case "two_decimals":
      return 0.01;
    case "none":
      return isZeroFraction ? 0 : 0.01;
    case "auto":
    default:
      if (isZeroFraction) {
        return 1;
      }
      if (isThreeDecimal) {
        return 0.001;
      }
      return 0.01;
  }
}

/**
 * Round a value UP to the nearest multiple of `step`.
 * Handles floating point noise by normalising to 6 decimal places.
 */
export function roundUpToStep(value: number, step: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }
  if (step <= 0) {
    return value;
  }

  const ratio = value / step;
  const rounded = Math.ceil(ratio - 1e-9);
  const result = rounded * step;

  const normalised = Math.round(result * 1_000_000) / 1_000_000;
  // `Math.ceil(-1e-9)` yields `-0` for value 0, which would render as "-0".
  return normalised === 0 ? 0 : normalised;
}
