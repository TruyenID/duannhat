// JSON imports need an explicit attribute under node's ESM loader; bundlers
// (vitest, Next) accept them bare, which is why this test ran fine under vitest
// and had to be adjusted when it moved to the runner `pnpm test` actually uses.
import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

import en from "./en.json" with { type: "json" };
import ja from "./ja.json" with { type: "json" };
import vi from "./vi.json" with { type: "json" };

/**
 * A key present in one locale and missing in another is not a translation
 * chore — it is a broken string in front of a customer.
 *
 * `orderSuccess.paymentTimeoutCancelled` existed in ja and vi but not en, and
 * it is rendered by `components/payment-warning-banner.tsx` on the banner that
 * tells someone their order was cancelled because payment timed out. An
 * English-speaking customer met a missing-message fallback at exactly the
 * moment the app owed them a clear explanation. Nothing failed; next-intl just
 * rendered the key path.
 *
 * Every sibling app already had parity (pos-web 948, admin-web 4799, kds 61,
 * kiosk 347, tms 57 — all three locales equal). customer-web was the only one
 * that had drifted, and it is the one customers actually use.
 */
type Messages = Record<string, unknown>;

function flatten(value: unknown, prefix = ""): string[] {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    return [prefix];
  }

  return Object.entries(value as Messages).flatMap(([key, child]) =>
    flatten(child, prefix ? `${prefix}.${key}` : key)
  );
}

const locales = { ja, en, vi } as const;

/**
 * Keys deliberately allowed to exist in only some locales. Each needs a reason.
 *
 * These are the two that were already adrift when the gate was written. Both are
 * unused by any component — verified by grep, including the dynamic
 * `t(\`filter\${...}\`)` shapes — so they are groundwork for features that have
 * not landed, not missing translations. If either ever gets rendered, it must
 * be translated everywhere first and removed from here.
 */
// godx-tempo#1719 — trống, và giữ cho nó trống là mục tiêu.
//
// Hai mục cũ (`guestOrders.filterCancelled`, `review.complete`) chỉ tồn tại ở
// `vi` và không chỗ nào trong code đọc tới: union type ở
// `order-history-card.tsx` chỉ nhận filterAll/filterPending/filterPaid, còn
// `review.complete` không xuất hiện trong bất kỳ file nào dùng namespace
// `review`. Miêu tả của chúng nói đúng "unused" — nên cách xử lý đúng là xoá
// hẳn key khỏi catalogue, không phải ghi tên vào danh sách miễn trừ.
const ALLOWED_PARTIAL: Record<string, string> = {};

describe("message catalogue parity", () => {
  const byLocale = Object.fromEntries(
    Object.entries(locales).map(([name, messages]) => [name, new Set(flatten(messages))])
  ) as Record<keyof typeof locales, Set<string>>;

  const union = new Set(Object.values(byLocale).flatMap((keys) => [...keys]));

  it("defines every key in every locale", () => {
    const missing: string[] = [];

    for (const [locale, keys] of Object.entries(byLocale)) {
      for (const key of union) {
        if (!keys.has(key) && !(key in ALLOWED_PARTIAL)) {
          missing.push(`${locale}: ${key}`);
        }
      }
    }

    assert.deepEqual(missing, [], `Keys missing from a locale:\n  ${missing.join("\n  ")}`);
  });

  it("keeps the allow-list honest", () => {
    // An entry that is now defined everywhere is a claim about the code that
    // has stopped being true, and the next reader will believe it.
    const stale = Object.keys(ALLOWED_PARTIAL).filter((key) =>
      Object.values(byLocale).every((keys) => keys.has(key))
    );

    assert.deepEqual(stale, [], `Now present in every locale — drop from ALLOWED_PARTIAL:\n  ${stale.join("\n  ")}`);

    // And an entry naming a key that exists in NO locale is dead weight.
    const phantom = Object.keys(ALLOWED_PARTIAL).filter((key) => !union.has(key));

    assert.deepEqual(phantom, [], `Named in ALLOWED_PARTIAL but absent everywhere:\n  ${phantom.join("\n  ")}`);
  });

  it("checks the reason, not just the shape of the allow-list", () => {
    // Every current entry is excused as "unused: ...". That is a claim about
    // the code, and until now nothing verified it — the two checks above only
    // confirm the key is partial and exists somewhere. So the day a component
    // starts calling t("complete"), a Japanese guest reads the raw key on the
    // default locale and the suite stays green.
    //
    // next-intl scopes by namespace: useTranslations("review") then t("complete").
    // Matching the call form `('complete')` is precise enough to avoid the false
    // positives a bare word search would produce.
    const roots = ["app", "components", "lib", "hooks"];
    const inUse: string[] = [];

    for (const [key, reason] of Object.entries(ALLOWED_PARTIAL)) {
      if (!reason.startsWith("unused")) continue;

      const segment = key.split(".").pop() as string;
      for (const root of roots) {
        const dir = join(import.meta.dirname, "..", root);
        if (!existsSync(dir)) continue;
        if (referencesTranslationKey(dir, segment)) {
          inUse.push(`${key} — excused as "${reason}" but ${root}/ calls t("${segment}")`);
          break;
        }
      }
    }

    assert.deepEqual(inUse, [], `Allow-list reasons that have stopped being true:\n  ${inUse.join("\n  ")}`);
  });

  it("keeps the catalogues valid JSON on disk", () => {
    // The imports above would already fail on malformed JSON, but they are
    // resolved by the bundler; this reads the actual files so a stray trailing
    // comma cannot slip through a cached module graph.
    for (const locale of Object.keys(locales)) {
      // import.meta.dirname, not __dirname: this file is ESM under node's
      // runner, where __dirname does not exist. vitest polyfilled it, which is
      // why the check passed there and threw the moment it ran for real.
      const raw = readFileSync(join(import.meta.dirname, `${locale}.json`), "utf8");
      assert.doesNotThrow(() => JSON.parse(raw) as unknown);
    }
  });
});

/** True when any .ts/.tsx under `dir` calls a translation function with `key`. */
function referencesTranslationKey(dir: string, key: string): boolean {
  const needles = [`('${key}')`, `("${key}")`];

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === "node_modules" || entry.name.startsWith(".")) continue;
      if (referencesTranslationKey(full, key)) return true;
      continue;
    }
    if (!/\.tsx?$/.test(entry.name) || entry.name.includes(".test.")) continue;
    const source = readFileSync(full, "utf8");
    if (needles.some((n) => source.includes(n))) return true;
  }

  return false;
}
