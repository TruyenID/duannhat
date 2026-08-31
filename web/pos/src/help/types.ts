/**
 * In-app operator guide — data model.
 *
 * Every page, dialog and modal in pos-web carries a `?` button that opens the
 * same right-hand drawer; what differs is the TOPIC it is pointed at. A topic
 * is plain data, not JSX, so the three locales sit side by side and a missing
 * translation is a TYPE error rather than something a cashier discovers.
 *
 * ## Why the copy is NOT in `src/i18n/*.json`
 *
 * The catalogues there are flat dot-key UI chrome — button labels, toasts,
 * column headings — and `catalogue.test.ts` polices them key by key. Help text
 * is long-form and structured (a purpose paragraph, an ordered flow, a list of
 * gotchas, a glossary), so flattening it would add ~800 keys per locale whose
 * ORDER carries meaning that a flat map cannot express: `usage.3` must come
 * after `usage.2`, and nothing in a dot-key JSON says so.
 *
 * Instead each locale exports one `HelpCatalogue`, typed as
 * `Record<HelpTopicId, HelpTopic>`. That is a stronger guarantee than the JSON
 * parity test gives: a topic added here without a Japanese entry does not
 * compile. Only the drawer's own chrome (section headings, the button label)
 * lives in the i18n JSON, under `help.*`.
 *
 * ## The four sections, and why `setup` exists
 *
 * `purpose` / `usage` / `checks` / `glossary` mirror admin-web's `HelpPanel` so
 * an operator reading both apps reads the same shape. `setup` is pos-web's
 * addition and the reason this work was asked for: most of what a cashier
 * cannot do on this screen is not broken — it is switched off somewhere else
 * (a shop setting in admin-web, a payment policy, a device that was never
 * paired, a workstation that is not installed). A screen that only documents
 * its own buttons sends the cashier looking in the wrong app.
 */

import type { LocaleCode } from "@/i18n";

export interface HelpGlossaryEntry {
  /** The term as it appears on screen. */
  term: string;
  /** What it means — especially versus the term next to it. */
  description: string;
}

export interface HelpTopic {
  /** Screen / dialog name, in the reader's language. */
  title: string;
  /**
   * Optional eyebrow above the title — the Japanese operational term
   * (精算 / レジ開け / 赤伝…) so a slip, a manager and this drawer all use one
   * vocabulary.
   */
  subtitle?: string;
  /**
   * One line, for places that LIST topics rather than open one — currently the
   * "other shop settings" card on the settings screen. Only the topics that
   * appear in such a list need it.
   */
  summary?: string;
  /** One paragraph: what this surface is for and who uses it. */
  purpose: string;
  /**
   * What must already be true OUTSIDE pos-web for the surface to work: shop
   * settings, HQ catalogue, payment policy, paired hardware, workstation.
   */
  setup?: string[];
  /** The operating flow, in the order performed. Rendered as a numbered list. */
  usage?: string[];
  /** Constraints, cut-offs, irreversible actions, silent failures. */
  checks?: string[];
  /** Terms that look interchangeable and are not. */
  glossary?: HelpGlossaryEntry[];
}

/**
 * Every helpable surface in the app.
 *
 * Order is documentation order: pages first, then the panels that live inside
 * the POS screen, then dialogs grouped by the flow they belong to. Adding an id
 * here makes all three locale catalogues fail to compile until they define it —
 * which is the point.
 */
export const HELP_TOPIC_IDS = [
  // ── Pages ───────────────────────────────────────────────────────────────
  "pairing",
  "pos-main",
  "tables-overview",
  "takeaway",
  "table-history",
  "order-history",
  "revenue",
  "settings",
  // plan-056 — "Tồn món". A PAGE topic, not a panel: the screen is reached from
  // the header menu and its guide has to answer questions the sales screen
  // cannot ("why is the dish still addable after I turned it off").
  "menu-availability",
  "shift-open",
  "shift-close",
  // ── Cài đặt cửa hàng: cái gì ở admin-web quyết định cái gì ở POS ────────
  // Rải ra thì mỗi màn chỉ nói được phần điều kiện của riêng nó, và câu hỏi
  // "cửa hàng có những cài đặt nào, ở đâu, đổi thì POS thấy gì" không có chỗ
  // nào trả lời. Nhóm này là chỗ đó.
  "shop-settings",
  "settings-order-flow",
  "settings-money",
  "settings-till",
  "settings-payments",
  "settings-printing",
  "settings-catalog",
  // ── Panels inside a page ────────────────────────────────────────────────
  "menu-catalog",
  "order-cart",
  "pos-tabs",
  "connection",
  "gap-reconcile",
  "unresolved-orders",
  "shift-gate-error",
  "shift-expired",
  // ── Order-building dialogs ──────────────────────────────────────────────
  "create-order",
  "product-options",
  "assign-table",
  "change-table",
  "merge-table",
  "unmerge-table",
  "guest-count",
  "void-item",
  "void-order",
  "close-tab",
  "stacking-conflict",
  // ── Money dialogs ───────────────────────────────────────────────────────
  "payment",
  "split-bill",
  "payment-receipt",
  "on-hold-receipt",
  "split-bill-receipt",
  "print-result",
  "red-invoice",
  "debt-search",
  "card-terminal",
  "cash-changer",
  // ── Shift dialogs ───────────────────────────────────────────────────────
  "cash-event",
  "abandon-shift",
  "shift-settle-confirm",
] as const;

export type HelpTopicId = (typeof HELP_TOPIC_IDS)[number];

/**
 * The setting groups listed on the settings screen, in the order they appear.
 *
 * Exported rather than inlined there for two reasons. It is the render order —
 * flow first, because that is the group whose settings make BUTTONS DISAPPEAR
 * and is therefore mistaken for a defect most often. And it is what lets
 * `help-content.test.ts` prove these six are reachable: the card mounts them
 * from a variable (`topic={id}`), which no scan for `topic="…"` literals can
 * see, so the test imports this list instead of pattern-matching for it.
 *
 * `shop-settings` is deliberately NOT in here — it is the map OF this list and
 * is mounted separately, on the card header.
 */
export const HELP_SETTINGS_GROUPS: HelpTopicId[] = [
  "settings-order-flow",
  "settings-money",
  "settings-till",
  "settings-payments",
  "settings-printing",
  "settings-catalog",
];

/** One locale's complete guide. Every topic, no exceptions — enforced by TS. */
export type HelpCatalogue = Record<HelpTopicId, HelpTopic>;

export type HelpCatalogues = Record<LocaleCode, HelpCatalogue>;
