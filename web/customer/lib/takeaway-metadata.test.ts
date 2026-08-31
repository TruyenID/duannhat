import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { buildTakeawayMetadata } from "./takeaway-metadata.ts";

describe("takeaway route metadata", () => {
  it("uses the localized branch name and copy for every supported locale", () => {
    assert.deepEqual(buildTakeawayMetadata("ja", { slug: "jimbocho", name: "神保町店" }), {
      title: "神保町店 – テイクアウトメニュー",
      description: "神保町店のテイクアウトメニュー",
    });
    assert.deepEqual(buildTakeawayMetadata("en", { slug: "jimbocho", name: "Jimbocho Store" }), {
      title: "Jimbocho Store – Takeaway Menu",
      description: "Order takeaway from Jimbocho Store",
    });
    assert.deepEqual(buildTakeawayMetadata("vi", { slug: "jimbocho", name: "Cửa hàng Jimbocho" }), {
      title: "Cửa hàng Jimbocho – Menu mang về",
      description: "Đặt món mang về từ Cửa hàng Jimbocho",
    });
  });

  it("never leaks the old hard-coded Hongo Vietnamese metadata", () => {
    const metadata = buildTakeawayMetadata("en");
    assert.equal(metadata.title, "Betoya – Takeaway Menu");
    assert.doesNotMatch(`${metadata.title} ${metadata.description}`, /Hongo|Cửa hàng/);
  });

  it("emits canonical and every supported alternate without losing the shop slug", () => {
    const metadata = buildTakeawayMetadata(
      "vi",
      { slug: "jimbocho", name: "Cửa hàng Jimbocho" },
      "jimbocho",
    );
    assert.deepEqual(metadata.alternates, {
      canonical: "/vi/takeaway/jimbocho",
      languages: {
        ja: "/ja/takeaway/jimbocho",
        en: "/en/takeaway/jimbocho",
        vi: "/vi/takeaway/jimbocho",
        "x-default": "/en/takeaway/jimbocho",
      },
    });
  });

  it("uses the requested route shop for alternate links when branch lookup fails", () => {
    const metadata = buildTakeawayMetadata("en", undefined, "unknown-shop");
    assert.equal(metadata.alternates?.canonical, "/en/takeaway/unknown-shop");
  });
});
