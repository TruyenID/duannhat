import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/**
 * Parse an optional money/number input string. Returns `null` when the field
 * is blank (empty or whitespace) so an absent value is sent as `null`, not 0.
 *
 * Guards against the `Number("") === 0` trap: a blank price field must mean
 * "no price entered" (→ null), not "costs ¥0" — otherwise a zero unit cost is
 * silently stamped onto an inbound purchase lot and corrupts inventory
 * valuation / COGS downstream.
 */
export function parseOptionalPrice(raw: string | null | undefined): number | null {
  const trimmed = (raw ?? "").trim();
  if (trimmed === "") return null;
  const n = Number(trimmed);
  return Number.isFinite(n) ? n : null;
}

/**
 * Validate a REQUIRED price field's raw input string.
 *
 * `0` is a real price — giveaways, free toppings and ¥0 combo lines are ordinary
 * catalog rows, and the backend agrees (`skus.*.selling_price` is
 * `nullable|numeric|min:0`). The trap this guards is that `0` is falsy in JS, so
 * the obvious `if (!price)` / `price <= 0` shape reads a legitimate free item as
 * "nothing entered" and greys out Save with no explanation (#2024).
 *
 * Blank still fails: `product_skus.selling_price` is NOT NULL, so an empty field
 * must be caught here rather than coming back as a raw SQLSTATE 23000 toast.
 * Negative and non-numeric fail too.
 *
 * Kept as one exported predicate because the create-product form checks the same
 * rule in three places (per-SKU validation, the no-variant base price, and the
 * submit-time defence in depth) and Save is disabled unless all three agree —
 * fixing only one of three inline copies leaves the button stubbornly grey.
 */
export function isValidRequiredPrice(raw: string | null | undefined): boolean {
  const trimmed = (raw ?? "").trim();
  if (trimmed === "") return false;
  const n = Number(trimmed);
  return Number.isFinite(n) && n >= 0;
}

export function stripHtml(html: string | null | undefined): string {
  if (!html) return "";
  return (
    html
      .replace(/<[^>]*>/g, " ")
      .replace(/&nbsp;/g, " ")
      .replace(/&lt;/g, "<")
      .replace(/&gt;/g, ">")
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      // Decode &amp; LAST so an already-escaped entity (e.g. literal text
      // "&lt;" encoded as "&amp;lt;") isn't double-decoded into "<".
      .replace(/&amp;/g, "&")
      .replace(/\s+/g, " ")
      .trim()
  );
}
