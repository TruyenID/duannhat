import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

import en from "./en.json";
import ja from "./ja.json";
import vi from "./vi.json";

/**
 * Three checks, because a missing translation can arrive three different ways
 * and each hides from the other two.
 *
 *   1. PARITY      — a key in one locale and not another. What bit customer-web:
 *                    `orderSuccess.paymentTimeoutCancelled` existed in ja and vi
 *                    but not en, on the banner that explains a cancelled order.
 *   2. USED-BUT-UNDEFINED — a key the code renders that no locale defines.
 *                    Parity is blind to this: all three catalogues are equally
 *                    missing it. Six of these reached admin-web, one being the
 *                    body of a delete-confirmation dialog.
 *   3. DYNAMIC PREFIX — `t(`a.b.${x}`)` where nothing in the catalogue starts
 *                    with `a.b.`. Three of these reached admin-web, so every
 *                    filter button and status badge on those screens rendered as
 *                    a dot-path.
 *
 *   4. PLACEHOLDER CÚ PHÁP SAI — `{{x}}` trong khi runtime chỉ thay `{x}`.
 *                    Ba check trên đều MÙ với nó: khoá có đủ ba locale (parity
 *                    xanh), được code gọi (check 2 xanh), không phải prefix
 *                    động (check 3 xanh). Nó chỉ lộ ra trên màn hình thu ngân,
 *                    dưới dạng `{SKU-42}` — giá trị đúng, nằm trong ngoặc thừa.
 *                    Đã xảy ra: `pos.cart.edit_product_unavailable` (#2974).
 *
 * pos-web is clean on all four today. This keeps it that way: the provider
 * warns about a missing key only in DEV (`warnMissingTranslationOnce`), which
 * fires only if somebody happens to open that screen locally — a key can still
 * ship and render raw to a cashier.
 *
 * Stated limit: the scan reads string literals, and check 3 proves only that a
 * prefix resolves to something, not that every value does.
 */
const CATALOGUES = { ja, en, vi } as Record<string, Record<string, string>>;

const SRC = join(__dirname, "..");

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === "dist") continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      walk(full, out);
    } else if (/\.tsx?$/.test(entry) && !/\.(test|spec)\.tsx?$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

const FILES = walk(SRC);

describe("i18n catalogue", () => {
  it("defines every key in all three locales", () => {
    const union = new Set(Object.values(CATALOGUES).flatMap((c) => Object.keys(c)));
    const missing: string[] = [];

    for (const [locale, catalogue] of Object.entries(CATALOGUES)) {
      for (const key of union) {
        if (!(key in catalogue)) missing.push(`${locale}: ${key}`);
      }
    }

    expect(missing, `Present in one locale, absent from another:\n  ${missing.join("\n  ")}`).toEqual(
      []
    );
  });

  it("defines every key the code renders", () => {
    const used = new Map<string, string>();

    for (const file of FILES) {
      for (const m of readFileSync(file, "utf8").matchAll(/\bt\(\s*"([a-zA-Z0-9_.]+)"/g)) {
        if (!used.has(m[1])) used.set(m[1], file.replace(`${SRC}/`, ""));
      }
    }

    // A regex that matched nothing would read exactly like a clean codebase.
    expect(used.size).toBeGreaterThan(200);

    const missing: string[] = [];
    for (const [key, where] of used) {
      for (const [locale, catalogue] of Object.entries(CATALOGUES)) {
        if (!(key in catalogue)) missing.push(`${locale}: ${key}  (${where})`);
      }
    }

    expect(
      missing,
      `Rendered by the code, defined nowhere — these print as the raw key:\n  ${missing.join("\n  ")}`
    ).toEqual([]);
  });

  it("has a catalogue entry behind every dynamic prefix", () => {
    const prefixes = new Map<string, string>();

    for (const file of FILES) {
      for (const m of readFileSync(file, "utf8").matchAll(/t\(\s*`([a-zA-Z0-9_.]*)\$\{/g)) {
        if (m[1] && !prefixes.has(m[1])) prefixes.set(m[1], file.replace(`${SRC}/`, ""));
      }
    }

    const keys = Object.keys(ja as Record<string, string>);
    const dead = [...prefixes]
      .filter(([prefix]) => !keys.some((k) => k.startsWith(prefix)))
      .map(([prefix, where]) => `${prefix}…  (${where})`);

    expect(
      dead,
      `Dynamic prefixes matching no key at all — every value renders raw:\n  ${dead.join("\n  ")}`
    ).toEqual([]);
  });

  /**
   * #2974 — runtime chỉ hiểu `{x}`.
   *
   * Cả hai translator (`AppProvider` và `getT()`) nội suy bằng
   * `value.replaceAll(`{${k}}`, …)`. Với `{{sku}}` thì lượt thay khớp phần
   * TRONG và để lại một cặp ngoặc ở ngoài, nên chuỗi ra là `{SKU-42}` — đúng
   * giá trị, sai hình dạng, và không có gì đỏ ở đâu cả.
   *
   * Đây là lỗi copy-paste từ hệ i18n khác (i18next/Handlebars dùng hai ngoặc),
   * nên nó sẽ quay lại nếu không có rào.
   */
  it("không có placeholder hai ngoặc — runtime chỉ thay một ngoặc", () => {
    const offenders: string[] = [];

    for (const [locale, catalogue] of Object.entries(CATALOGUES)) {
      for (const [key, value] of Object.entries(catalogue)) {
        if (typeof value === "string" && /\{\{[^}]*\}\}/.test(value)) {
          offenders.push(`${locale}  ${key}  →  ${value}`);
        }
      }
    }

    expect(
      offenders,
      "Placeholder hai ngoặc — runtime chỉ thay một ngoặc, nên chúng in ra kèm ngoặc thừa:\n  " +
        offenders.join("\n  ")
    ).toEqual([]);
  });
});
