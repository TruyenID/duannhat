/**
 * #1184 — UI-string parity across ja / en / vi.
 *
 * A missing key does not crash: `t("key")` falls back to `en`, then returns the
 * RAW KEY. Users see `till_sessions.manual_settle.error` sitting in a toast —
 * it looks like a bug because it is one, but nothing in tsc or eslint catches
 * it. This file is the net. (It caught 4 `toast.coupon.*` keys missing from
 * vi.json the day it was written.)
 *
 * Cheap by design: pure JSON diff, no DOM.
 *
 * @vitest-environment node
 */

import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import ja from "@/i18n/ja.json";
import en from "@/i18n/en.json";
import vi from "@/i18n/vi.json";

const DICTS = { ja, en, vi } as Record<string, Record<string, string>>;
const LOCALES = ["ja", "en", "vi"] as const;

/** Keys present in `a` but not in `b`. */
function missingFrom(a: string, b: string): string[] {
  const bKeys = new Set(Object.keys(DICTS[b]));
  return Object.keys(DICTS[a]).filter((key) => !bKeys.has(key));
}

/** `{name}` placeholders used by a string, as a sorted, de-duplicated list. */
function placeholders(value: string): string[] {
  return [...new Set(value.match(/\{[a-z0-9_]+\}/gi) ?? [])].sort();
}

describe("i18n dictionaries — key-set parity", () => {
  it.each([
    ["ja", "en"],
    ["ja", "vi"],
    ["en", "ja"],
    ["en", "vi"],
    ["vi", "ja"],
    ["vi", "en"],
  ])("every key in %s.json exists in %s.json", (from, to) => {
    // Reported as a list so a failure names the exact keys to add, rather
    // than just "expected 4647 to be 4643".
    expect(missingFrom(from, to)).toEqual([]);
  });

  it("all three dictionaries have identical key SETS", () => {
    const [first, ...rest] = LOCALES.map((l) => Object.keys(DICTS[l]).sort());
    for (const keys of rest) expect(keys).toEqual(first);
  });
});

/**
 * Deliberately-blank strings. These label the trailing action column of a data
 * table, whose header is intentionally empty in every locale. Add here only
 * when "" is the correct rendering — not to silence an untranslated key.
 */
const INTENTIONALLY_BLANK = new Set([
  "settings.tender_type.col_actions",
  "settings.tender_category.col_actions",
  "settings.denomination.col_actions",
]);

describe("i18n dictionaries — value hygiene", () => {
  it.each(LOCALES)("%s.json has no accidentally-empty translations", (locale) => {
    const blank = Object.entries(DICTS[locale])
      .filter(([, value]) => typeof value !== "string" || value.trim() === "")
      .map(([key]) => key)
      .filter((key) => !INTENTIONALLY_BLANK.has(key));
    expect(blank).toEqual([]);
  });

  it.each(LOCALES)("%s.json is a flat string map (no nested objects)", (locale) => {
    const nested = Object.entries(DICTS[locale])
      .filter(([, value]) => typeof value !== "string")
      .map(([key]) => key);
    // `getTranslations()` types the dictionary as Record<string, string> and
    // `t()` interpolates with replaceAll — a nested object would render as
    // "[object Object]".
    expect(nested).toEqual([]);
  });

  it.each([
    ["en", "vi"],
    ["en", "ja"],
  ])("%s and %s use the same {placeholders} for every shared key", (a, b) => {
    // A translation that drops `{n}` silently loses the number; one that
    // invents `{count}` renders the literal braces to the user.
    const drift = Object.keys(DICTS[a])
      .filter((key) => key in DICTS[b])
      .filter((key) => placeholders(DICTS[a][key]).join() !== placeholders(DICTS[b][key]).join())
      .map(
        (key) => `${key}: ${a}=${placeholders(DICTS[a][key])} ${b}=${placeholders(DICTS[b][key])}`
      );
    expect(drift).toEqual([]);
  });
});

/**
 * #2931 — MỌI mục của select trạng thái đơn phải đọc ra khác nhau.
 *
 * Bài dưới ghim đúng cặp `voided` / `expired` — cặp đã gây ra báo cáo. Nhưng
 * select render MƯỜI mục, nên một trùng lặp tương lai giữa hai mục khác sẽ
 * không bị bắt, và triệu chứng với người dùng thì y hệt: hai dòng chữ giống
 * nhau trong cùng một danh sách, không cách nào đoán được cái nào là cái nào.
 *
 * Danh sách này là bản sao của `ORDER_STATUSES` ở CẢ HAI trang
 * (`shop/[shopSlug]/orders` và `hq/[brandSlug]/orders`) — hai trang đang giữ
 * hai danh sách giống hệt nhau. Bài `order status filter list` ngay dưới ghim
 * việc bản sao này không trôi khỏi chúng.
 */
const RENDERED_ORDER_STATUSES = [
  "pending",
  "awaiting_confirmation",
  "confirmed",
  "open",
  "dining",
  "checkout",
  "paying",
  "closed",
  "voided",
  "expired",
] as const;

describe("order status select — every rendered label is distinct", () => {
  it.each(LOCALES)("%s renders no two identical status labels", (locale) => {
    const dict = DICTS[locale];

    for (const scope of ["shop", "hq"] as const) {
      const labels = RENDERED_ORDER_STATUSES.map((s) => dict[`${scope}.orders.status.${s}`]);

      // Khoá thiếu rơi về chính tên khoá và cũng là lỗi — bắt luôn ở đây thay
      // vì để nó hiện ra trên màn hình người dùng.
      expect(labels.filter(Boolean)).toHaveLength(RENDERED_ORDER_STATUSES.length);

      const seen = new Map<string, string[]>();
      RENDERED_ORDER_STATUSES.forEach((s, i) => {
        seen.set(labels[i], [...(seen.get(labels[i]) ?? []), s]);
      });
      const dupes = [...seen.entries()].filter(([, keys]) => keys.length > 1);

      expect(
        dupes.map(([label, keys]) => `${label} ← ${keys.join(" + ")}`),
      ).toEqual([]);
    }
  });
});

/**
 * #3190 — bài này ĐÃ ĐƯỢC HỨA từ lâu mà chưa từng tồn tại.
 *
 * Chú thích của `RENDERED_ORDER_STATUSES` ngay trên viết "Bài
 * `order status filter list` ngay dưới ghim việc bản sao này không trôi khỏi
 * chúng" — nhưng `git grep "order status filter list"` chỉ trả về đúng dòng chú
 * thích ấy. Bản sao không hề được ghim vào đâu cả: thêm một trạng thái vào
 * `ORDER_STATUSES` của trang mà quên thêm ở đây thì select render mục mới
 * **không ai kiểm nhãn trùng**, và cả file vẫn xanh. Một lời hứa trong comment
 * đọc y hệt một cái rào — đó mới là phần nguy hiểm.
 */
describe("order status filter list", () => {
  const PAGES = [
    "src/app/shop/[shopSlug]/orders/page.tsx",
    "src/app/hq/[brandSlug]/orders/page.tsx",
  ];

  it.each(PAGES)("%s giữ đúng danh sách RENDERED_ORDER_STATUSES", (page) => {
    const source = readFileSync(page, "utf8");
    const block = source.match(/const ORDER_STATUSES: CustomerOrderStatus\[\] = \[([^\]]*)\]/);

    // Sàn chống rỗng: trích xuất hỏng (đổi tên hằng, đổi kiểu, tách ra file
    // khác) sẽ cho danh sách rỗng, mà rỗng thì so sánh nào cũng "không lệch".
    expect(
      block,
      `${page}: không tìm thấy \`const ORDER_STATUSES: CustomerOrderStatus[] = [...]\` — ` +
        "hằng đã đổi tên hoặc dời chỗ, và rào này không còn đọc được thứ nó ghim. " +
        "Sửa phép trích xuất, đừng xoá bài."
    ).not.toBeNull();

    const statuses = [...(block?.[1] ?? "").matchAll(/["']([a-z_]+)["']/g)].map((m) => m[1]);
    expect(statuses.length).toBeGreaterThan(0);

    expect(
      statuses,
      `${page}: select trạng thái render danh sách khác với RENDERED_ORDER_STATUSES ở ` +
        "file test này. Bài kiểm nhãn trùng ở trên chạy trên BẢN SAO, nên khi hai bên " +
        "lệch nhau thì mục mới lên màn hình mà không ai soi nhãn của nó. Cập nhật cả hai."
    ).toEqual([...RENDERED_ORDER_STATUSES]);
  });
});

describe("order terminal-status labels", () => {
  const EXPECTED = {
    ja: { voided: "無効", expired: "期限切れ" },
    en: { voided: "Voided", expired: "Expired" },
    vi: { voided: "Đã hủy", expired: "Hết hạn" },
  } as const;

  it.each(LOCALES)(
    "%s keeps manually voided and automatically expired orders distinct",
    (locale) => {
      const dict = DICTS[locale];
      const expected = EXPECTED[locale];
      const shop = {
        voided: dict["shop.orders.status.voided"],
        expired: dict["shop.orders.status.expired"],
      };
      const hq = {
        voided: dict["hq.orders.status.voided"],
        expired: dict["hq.orders.status.expired"],
      };

      // Both order filters expose the same two backend values. A duplicate label
      // makes the select impossible to interpret even though the values differ.
      expect(shop).toEqual(expected);
      expect(hq).toEqual(expected);
      expect(new Set(Object.values(shop)).size).toBe(2);
      expect(new Set(Object.values(hq)).size).toBe(2);
    }
  );
});
