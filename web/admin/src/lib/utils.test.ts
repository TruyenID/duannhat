import { describe, it, expect } from "vitest";
import { stripHtml, parseOptionalPrice, isValidRequiredPrice } from "./utils";

/**
 * Bug round 2 — a blank price field was sent as 0 (Number("") === 0 is finite),
 * stamping a ¥0 cost onto inbound purchase lots. parseOptionalPrice returns
 * null for blank input so "no price" stays absent.
 */
describe("parseOptionalPrice", () => {
  it("returns null for blank input (regression: not 0)", () => {
    expect(parseOptionalPrice("")).toBeNull();
    expect(parseOptionalPrice("   ")).toBeNull();
    expect(parseOptionalPrice(undefined)).toBeNull();
    expect(parseOptionalPrice(null)).toBeNull();
  });

  it("parses a real number including an explicit zero", () => {
    expect(parseOptionalPrice("100")).toBe(100);
    expect(parseOptionalPrice(" 250 ")).toBe(250);
    expect(parseOptionalPrice("0")).toBe(0);
  });

  it("returns null for non-numeric input", () => {
    expect(parseOptionalPrice("abc")).toBeNull();
  });
});

/**
 * #2024 — the create-product screen refused a selling price of 0, so a giveaway
 * / free topping / ¥0 combo line could not be created at all. The cause is the
 * JS falsy-zero trap: `!price` and `price <= 0` both read a legitimate 0 as
 * "nothing entered", and Save stayed grey with no message.
 *
 * These assertions are the regression pin. `0` valid, blank still invalid
 * (`product_skus.selling_price` is NOT NULL — letting blank through returns a
 * raw SQLSTATE 23000 toast, which is why the guard exists at all).
 */
describe("isValidRequiredPrice — 0 is a price, blank is not", () => {
  it("accepts an explicit zero in every form it can be typed (regression #2024)", () => {
    expect(isValidRequiredPrice("0")).toBe(true);
    expect(isValidRequiredPrice("0.00")).toBe(true);
    expect(isValidRequiredPrice(" 0 ")).toBe(true);
  });

  it("accepts ordinary positive prices, whole and sub-unit", () => {
    expect(isValidRequiredPrice("1000")).toBe(true);
    expect(isValidRequiredPrice("1234.56")).toBe(true);
  });

  it("still rejects blank — selling_price is NOT NULL", () => {
    expect(isValidRequiredPrice("")).toBe(false);
    expect(isValidRequiredPrice("   ")).toBe(false);
    expect(isValidRequiredPrice(null)).toBe(false);
    expect(isValidRequiredPrice(undefined)).toBe(false);
  });

  it("rejects negative and non-numeric input", () => {
    expect(isValidRequiredPrice("-1")).toBe(false);
    expect(isValidRequiredPrice("-0.01")).toBe(false);
    expect(isValidRequiredPrice("abc")).toBe(false);
    // Number("Infinity") is not NaN, so a NaN-only check would let it through
    // and send a non-serializable price to the API.
    expect(isValidRequiredPrice("Infinity")).toBe(false);
  });
});

/**
 * The guard is only half the fix: the payload builder must also send 0 rather
 * than collapsing it to null, or a form that finally accepts 0 still saves
 * "no price". `parseOptionalPrice` is the tested helper for that distinction —
 * these two rows are what the create-product payload relies on.
 */
describe("parseOptionalPrice — the create-product payload contract (#2024)", () => {
  it("sends a typed zero as 0, not null", () => {
    expect(parseOptionalPrice("0")).toBe(0);
    expect(parseOptionalPrice("0.00")).toBe(0);
  });

  it("sends an untouched field as null, not 0", () => {
    expect(parseOptionalPrice("")).toBeNull();
  });
});

/**
 * Bug round 1 — stripHtml double-decodes HTML entities.
 *
 * The entity replacements decode `&amp;` BEFORE `&lt;`/`&gt;`/`&quot;`/`&#39;`.
 * So content that legitimately contains an escaped entity as literal text —
 * e.g. `&amp;lt;` which is the HTML encoding of the literal string `&lt;` —
 * gets decoded twice: `&amp;lt;` → `&lt;` → `<`. The standard HTML-unescape
 * order decodes `&amp;` LAST to avoid exactly this.
 */
describe("stripHtml — entity decoding order", () => {
  it("decodes an escaped entity only once (regression)", () => {
    // `&amp;lt;` is the encoded form of the literal text "&lt;" — it must NOT
    // collapse all the way to "<".
    expect(stripHtml("&amp;lt;")).toBe("&lt;");
  });

  it("decodes a doubly-escaped ampersand to a single one", () => {
    expect(stripHtml("A &amp;amp; B")).toBe("A &amp; B");
  });

  it("still decodes single-level entities normally", () => {
    expect(stripHtml("Tom &amp; Jerry")).toBe("Tom & Jerry");
    expect(stripHtml("a &lt;b&gt; c")).toBe("a <b> c");
    expect(stripHtml("&quot;hi&quot; &#39;yo&#39;")).toBe("\"hi\" 'yo'");
  });

  it("strips tags and collapses whitespace", () => {
    expect(stripHtml("<p>hello   <b>world</b></p>")).toBe("hello world");
  });

  it("returns empty string for nullish input", () => {
    expect(stripHtml(null)).toBe("");
    expect(stripHtml(undefined)).toBe("");
  });
});
