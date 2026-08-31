import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { formatGuestDate, formatGuestDateTime, formatGuestTime, intlLocale } from "./date-format.ts";

/**
 * #1261 — six guest screens formatted dates with a hardcoded locale: two to
 * vi-VN, four to ja-JP, in an app that runs three languages and defaults to ja.
 * A Japanese guest at a table read the Vietnamese order of the numbers, and a
 * Vietnamese guest reading their own order history read the Japanese one.
 *
 * node:test, not vitest — that is what `pnpm test` runs here (see package.json),
 * and a vitest file dropped into lib/ is picked up by it and fails. I wrote one
 * before checking the script.
 */

const INSTANT = "2026-07-30T05:00:00Z";

describe("formatGuestDate", () => {
  it("orders the numbers the way each language does", () => {
    // The whole bug in three lines: same instant, three correct answers, and
    // only one of them was ever shown.
    assert.match(formatGuestDate(INSTANT, "ja"), /2026.07.30/);
    assert.match(formatGuestDate(INSTANT, "vi"), /30.07.2026/);
    assert.match(formatGuestDate(INSTANT, "en"), /07.30.2026/);
  });

  it("falls back to the app default for an unknown locale rather than throwing", () => {
    // The locale arrives from the URL segment; a hand-typed one must not take
    // down a screen the customer is mid-order on.
    assert.equal(formatGuestDate(INSTANT, "de"), formatGuestDate(INSTANT, "ja"));
    assert.equal(intlLocale("de"), "ja-JP");
  });

  it("returns empty for an unparseable date instead of 'Invalid Date'", () => {
    assert.equal(formatGuestDate("not-a-date", "vi"), "");
    assert.equal(formatGuestTime("", "vi"), "");
    assert.equal(formatGuestDateTime("nope", "en"), "");
  });
});

describe("formatGuestTime", () => {
  it("is 24-hour in every locale, so a receipt cannot read 3:00 for 15:00", () => {
    for (const locale of ["ja", "vi", "en"]) {
      assert.doesNotMatch(formatGuestTime("2026-07-30T15:30:00Z", locale), /AM|PM|午後|午前/i);
    }
  });
});

describe("formatGuestDateTime", () => {
  it("carries both halves, which is what the account screens render", () => {
    const rendered = formatGuestDateTime(INSTANT, "vi");
    assert.match(rendered, /30.07.2026/);
    assert.match(rendered, /\d{2}:\d{2}/);
  });
});
