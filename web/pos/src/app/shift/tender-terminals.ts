/**
 * #1156 — payment-terminal `accepts` model, shared by the payment dialog's
 * brand sub-choice and the close page's per-terminal reconcile sections.
 *
 * A branch may run several physical payment terminals (Stera, StarPay, …),
 * each accepting a different brand subset under its own acquirer contract.
 * Cloud models this as `peripheral_devices(payment_terminal).metadata.accepts`
 * — a list of `till_tender_types.tender_key` values.
 *
 * DATA-SOURCE REFINEMENT (tracked in #1156/#1157): no POS-namespace endpoint
 * exposes peripheral accepts to a paired device token yet — the shop CRUD
 * (`/api/v1/shops/{slug}/peripheral-devices`) sits behind Platform SSO, the
 * same constraint the debt screen documents for `/shops/{slug}/debts`. Until
 * the backend ships `GET /api/v1/pos/till/payment-terminals` (see
 * `usePaymentTerminals`), the terminal list resolves empty and every consumer
 * degrades to the branch's EFFECTIVE tender list from
 * `GET /api/v1/pos/till/tender-types` (per-branch activation already applied
 * server-side by TenderTypeResolver):
 *   - payment dialog → brand chips show every effective non-cash tender;
 *   - close page     → one generic terminal section (pre-#1156 behaviour).
 */

import type {
  TillTenderCategoryRow,
  TillTenderType,
} from "@/services/till-service";

/** One physical payment terminal and the tender_keys it accepts. */
export interface PaymentTerminal {
  id: string;
  name: string;
  /** `till_tender_types.tender_key` subset (device `metadata.accepts`). */
  accepts: string[];
}

/** Section key for tenders not covered by any registered terminal. */
export const GENERIC_SECTION_KEY = "__generic__";

/**
 * One close-page reconcile section: a physical terminal (or the generic
 * bucket) plus the non-cash tenders that settle on it.
 */
export interface TerminalSection {
  /** Terminal device id, or {@link GENERIC_SECTION_KEY}. */
  key: string;
  /** Device display name; null for the generic section. */
  deviceName: string | null;
  /** Ordered tender_keys assigned to this section. */
  tenderKeys: string[];
  /**
   * Categories whose CATEGORY-LEVEL expected is attributed to this section.
   * The reconciliation API only rolls expected up per category (per-brand
   * expected from `order_payments.tender_key` is the backend follow-up), so
   * when a category's tenders span multiple terminals the whole category
   * expected is attributed to the FIRST section holding rows of it. Once the
   * backend splits expected per tender_key this field can be retired.
   */
  ownedCategoryKeys: string[];
}

/** Resolve a tender's display name for the active locale. */
export function tenderDisplayName(tt: TillTenderType, locale: string): string {
  if (typeof tt.name === "string") return tt.name || tt.tender_key;
  const map = tt.name ?? {};
  return (
    map[locale] ??
    map.ja ??
    map.en ??
    map.vi ??
    Object.values(map)[0] ??
    tt.tender_key
  );
}

/**
 * Group the branch's non-cash tenders into one section per payment terminal
 * (assignment = first terminal whose `accepts` contains the tender_key), with
 * a trailing generic section for tenders no device covers. No terminals →
 * a single generic section carrying everything (backward compatible).
 */
export function buildTerminalSections(
  terminals: PaymentTerminal[],
  tenderTypes: TillTenderType[],
  visibleCategoryKeys: string[],
): TerminalSection[] {
  const visible = new Set(visibleCategoryKeys);
  const nonCash = tenderTypes.filter(
    (tt) => tt.category !== "cash" && visible.has(tt.category),
  );
  if (nonCash.length === 0) return [];

  const assigned = new Set<string>();
  const sections: TerminalSection[] = [];

  for (const terminal of terminals) {
    const accepts = new Set(terminal.accepts);
    const keys = nonCash
      .filter((tt) => !assigned.has(tt.tender_key) && accepts.has(tt.tender_key))
      .map((tt) => tt.tender_key);
    if (keys.length === 0) continue; // nothing of the effective list → no section
    keys.forEach((k) => assigned.add(k));
    sections.push({
      key: terminal.id,
      deviceName: terminal.name,
      tenderKeys: keys,
      ownedCategoryKeys: [],
    });
  }

  const leftovers = nonCash
    .filter((tt) => !assigned.has(tt.tender_key))
    .map((tt) => tt.tender_key);
  if (leftovers.length > 0) {
    sections.push({
      key: GENERIC_SECTION_KEY,
      deviceName: null,
      tenderKeys: leftovers,
      ownedCategoryKeys: [],
    });
  }

  // Category-expected attribution: first section holding rows of the
  // category owns its category-level expected (see ownedCategoryKeys doc).
  const categoryOf = new Map(nonCash.map((tt) => [tt.tender_key, tt.category]));
  for (const cat of visibleCategoryKeys) {
    const owner = sections.find((s) =>
      s.tenderKeys.some((k) => categoryOf.get(k) === cat),
    );
    if (owner) owner.ownedCategoryKeys.push(cat);
  }

  return sections;
}

/**
 * The category keys the system seeds for every organization. Anything else in
 * `/pos/till/tender-categories` is a category the shop authored itself.
 */
const SYSTEM_CATEGORY_KEYS = new Set(["cash", "card", "qr", "emoney"]);

/**
 * Display label for a tender category in the operator's language.
 *
 * `till_tender_categories.name` is deliberately NOT translatable — one row,
 * one name, owned by the shop (see the ruling in
 * schemas/Backend/Till/TillTenderCategory.yaml). That ruling is about
 * SHOP-AUTHORED rows, and it holds for them: a category a manager typed exists
 * in exactly the language they typed it in, and there is nothing to resolve.
 *
 * The four `is_system` rows are a different thing wearing the same shape. No
 * shop wrote them, no shop may edit them (the CRUD answers 403
 * TENDER_CATEGORY_SYSTEM_READONLY), and the seeder gave every organization on
 * the platform the same three Vietnamese words — so a cashier in Tokyo read
 * "Thẻ / QR / Tiền điện tử" over their tender chips no matter which language
 * they picked, and no server change could ever fix it because there was
 * nothing per-locale to serve. They are fixed system vocabulary, so they are
 * translated here, from the catalogue that already carries them in ja/en/vi.
 *
 * Client-side on purpose: it covers the LAN and direct-Cloud paths with one
 * implementation, and it switches the instant the operator does — no refetch,
 * which is also why the categories query is not locale-keyed.
 *
 * Falls back to the stored name whenever the catalogue has no entry, so an
 * unknown system key can never render as a raw i18n key.
 */
export function tenderCategoryLabel(
  category: TillTenderCategoryRow,
  t: (key: string) => string,
): string {
  if (!category.is_system || !SYSTEM_CATEGORY_KEYS.has(category.key)) {
    return category.name;
  }
  const key = `shift.close.reconcile.category.${category.key}`;
  const label = t(key);
  // pos-web's t() returns the key itself when a string is missing.
  return label === key ? category.name : label;
}

/** One brand group in the payment dialog's terminal sub-choice. */
export interface TenderBrandGroup {
  categoryKey: string;
  /** Category heading, already resolved for the operator's language. */
  categoryName: string;
  tenders: TillTenderType[];
}

/**
 * Brand options for the payment dialog: the branch's effective non-cash
 * tenders grouped by category. When terminal accepts data exists, the list is
 * restricted to the union of every terminal's accepts (a brand no registered
 * device takes cannot be the one that was just tapped).
 */
export function buildTenderBrandGroups(
  tenderTypes: TillTenderType[],
  categories: TillTenderCategoryRow[],
  terminals: PaymentTerminal[],
  /**
   * How to label a category. Defaults to the stored name so existing callers
   * and tests are unchanged; the payment dialog passes
   * {@link tenderCategoryLabel} so the system headings follow the operator's
   * language like everything else in that dialog.
   */
  labelFor: (category: TillTenderCategoryRow) => string = (category) =>
    category.name,
): TenderBrandGroup[] {
  const acceptsUnion =
    terminals.length > 0
      ? new Set(terminals.flatMap((t) => t.accepts))
      : null;

  const candidates = tenderTypes.filter(
    (tt) =>
      tt.category !== "cash" &&
      (acceptsUnion === null || acceptsUnion.has(tt.tender_key)),
  );

  return categories
    .filter((cat) => cat.key !== "cash")
    .map((cat) => ({
      categoryKey: cat.key,
      categoryName: labelFor(cat),
      tenders: candidates.filter((tt) => tt.category === cat.key),
    }))
    .filter((group) => group.tenders.length > 0);
}
