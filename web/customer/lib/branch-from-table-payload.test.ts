import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
  BRANCH_FIELDS_ABSENT_FROM_TABLE_PAYLOAD,
  branchFromTablePayload,
  type TableBranchPayload,
} from "./branch-from-table-payload.ts";
import type { Branch } from "../data/brands.ts";

/**
 * #1778 — the bug was never "the flag is false". It was that TWO sources
 * describe the same branch and were allowed to CONTRADICT each other, with the
 * winner decided by which HTTP response landed last. So the tests below assert
 * agreement between the sources and invariance to their arrival order, not a
 * particular boolean.
 */

/** What `brand-context` builds out of `GET /api/v1/customer/branches` — the
 *  complete branch, including every field the table endpoint never sends. */
const fromBranchesEndpoint: Branch = {
  id: "b-1",
  slug: "ningyocho",
  name: "Ningyocho",
  weekly_hours: { mon: { open: "09:00", close: "22:00" } },
  timezone: "Asia/Tokyo",
  service_charge_rate: 10,
  prices_include_tax: true,
  service_charge_tax_rate: 10,
  default_tax_type: { id: "tt-1", code: "standard", rate: 10 },
  currency_code: "JPY",
  split_bill_rounding_mode: "integer",
  locale: "ja-JP",
  effective_order_policy: {
    prep_before_payment: true,
    customer_email_required: false,
    phone_country: "JP",
    source: { prep_before_payment: "shop", customer_email_required: "brand" },
  },
  review_avg_rating: 4.5,
  review_total_count: 12,
  brand: { id: "br-1", slug: "hongo", name: "Hongo" },
};

/** What `GET /api/v1/customer/tables/{qrToken}` sends for that same branch,
 *  backend half of #1778 deployed. */
const tablePayload: TableBranchPayload = {
  id: "b-1",
  name: "Ningyocho",
  slug: "ningyocho",
  code: "NGC",
  address: "Tokyo, Chuo-ku",
  phone: "03-0000-0000",
  img_branches: null,
  banner_desktop: null,
  banner_tablet: null,
  banner_mobile: null,
  logo: null,
  business_hours: "09:00-22:00",
  weekly_hours: { mon: { open: "09:00", close: "22:00" } },
  timezone: "Asia/Tokyo",
  seat_capacity: 40,
  review_avg_rating: 4.5,
  review_total_count: 12,
  prices_include_tax: true,
  service_charge_rate: 10,
  currency_code: "JPY",
  brand: { id: "br-1", slug: "hongo", name: "Hongo" },
};

describe("branchFromTablePayload — #1778", () => {
  it("hai nguồn KHÔNG được nói ngược nhau về giá đã gồm thuế", () => {
    // `/customer/branches` nói true. Payload bàn của CÙNG chi nhánh cũng nói
    // true. Kết quả phải là true — trước bản sửa, object tự dựng thay thế
    // nguyên cả branch nên cờ rơi mất và menu dán nhãn "Chưa gồm thuế".
    const merged = branchFromTablePayload(fromBranchesEndpoint, tablePayload);

    assert.equal(merged.prices_include_tax, true);
    assert.equal(
      merged.prices_include_tax,
      fromBranchesEndpoint.prices_include_tax,
      "menu dine-in và màn tóm tắt phải nói cùng một câu về thuế",
    );
  });

  it("nhãn thuế không đổi theo thứ tự về của hai response (đây chính là cuộc đua)", () => {
    // Trên mạng thường payload bàn về sau và thắng; bóp Slow 4G thì ngược lại,
    // và nhãn lật. Cả hai thứ tự phải cho cùng một câu trả lời.
    const emptyBranch: Branch = { id: "", slug: "", name: "", brand: { id: "", slug: "", name: "" } };

    // Thứ tự A — /branches về trước, rồi payload bàn ghi đè.
    const branchesThenTable = branchFromTablePayload(fromBranchesEndpoint, tablePayload);

    // Thứ tự B — payload bàn về trước, rồi /branches re-resolve theo slug
    // (brand-context: `mapped.find(b => b.slug === prev.slug) ?? prev`).
    const tableFirst = branchFromTablePayload(emptyBranch, tablePayload);
    const tableThenBranches =
      tableFirst.slug === fromBranchesEndpoint.slug ? fromBranchesEndpoint : tableFirst;

    assert.equal(branchesThenTable.prices_include_tax, tableThenBranches.prices_include_tax);
  });

  it("giữ cờ khi backend CHƯA phát prices_include_tax (nửa backend chưa deploy)", () => {
    // Trường vắng mặt nghĩa là "nguồn này không có ý kiến", không phải `false`.
    const withoutFlag: TableBranchPayload = { ...tablePayload };
    delete withoutFlag.prices_include_tax;

    const merged = branchFromTablePayload(fromBranchesEndpoint, withoutFlag);

    assert.equal(merged.prices_include_tax, true);
  });

  it("payload bàn vẫn được quyền nói false khi nó THỰC SỰ nói false", () => {
    const merged = branchFromTablePayload(
      { ...fromBranchesEndpoint, prices_include_tax: true },
      { ...tablePayload, prices_include_tax: false },
    );

    assert.equal(merged.prices_include_tax, false);
  });

  it("không đánh rơi các field mà chỉ /customer/branches mới có", () => {
    const merged = branchFromTablePayload(fromBranchesEndpoint, tablePayload);

    for (const field of BRANCH_FIELDS_ABSENT_FROM_TABLE_PAYLOAD) {
      assert.deepEqual(
        merged[field],
        fromBranchesEndpoint[field],
        `${field} bị đánh rơi khi fold payload bàn`,
      );
    }
  });

  it("KHÔNG mang dữ liệu chi nhánh cũ sang chi nhánh khác (mặt trái của merge)", () => {
    // Quét QR một bàn thuộc chi nhánh khác: chế độ thuế / tiền tệ / làm tròn
    // của chi nhánh trước không được rò sang. Đây là lý do bản gốc thay thế
    // nguyên object, và bản sửa phải giữ được ý đó.
    const otherBranch: TableBranchPayload = {
      id: "b-2",
      name: "Jimbocho",
      slug: "jimbocho",
      prices_include_tax: false,
      currency_code: "VND",
      service_charge_rate: 0,
      brand: { id: "br-1", slug: "hongo", name: "Hongo" },
    };

    const merged = branchFromTablePayload(fromBranchesEndpoint, otherBranch);

    assert.equal(merged.slug, "jimbocho");
    assert.equal(merged.prices_include_tax, false);
    assert.equal(merged.currency_code, "VND");
    assert.equal(merged.split_bill_rounding_mode, undefined);
    assert.equal(merged.default_tax_type, undefined);
    assert.equal(merged.locale, undefined);
    assert.equal(merged.effective_order_policy, undefined);
  });

  it("giữ nguyên default cũ khi CHƯA nguồn nào nói gì", () => {
    const emptyBranch: Branch = { id: "", slug: "", name: "", brand: { id: "", slug: "", name: "" } };
    const merged = branchFromTablePayload(emptyBranch, {
      id: "b-3",
      name: "Cold",
      slug: "cold",
    });

    assert.equal(merged.currency_code, "JPY");
    assert.equal(merged.service_charge_rate, 0);
    assert.equal(merged.review_total_count, 0);
    assert.deepEqual(merged.brand, { id: "", slug: "", name: "" });
  });

  it("#1447 — timezone đi kèm weekly_hours qua cả bản fold", () => {
    const merged = branchFromTablePayload(fromBranchesEndpoint, tablePayload);

    assert.equal(merged.timezone, "Asia/Tokyo");
    assert.deepEqual(merged.weekly_hours, { mon: { open: "09:00", close: "22:00" } });
  });
});
