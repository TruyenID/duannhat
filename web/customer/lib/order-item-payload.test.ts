/**
 * godx-tempo#1768 — rào chặn: MỌI chỗ dựng payload item để tạo đơn đều phải
 * mang `note`.
 *
 * Ghi chú từng món ("Không hành", "Ít cay") bị vứt ở gần hết các đường đặt
 * hàng, và lý do không phải ai đó viết sai logic: cùng một object literal
 * `{ product_sku_id, quantity, expected_unit_price, toppings }` được CHÉP ra
 * bảy chỗ, và chỉ hai chỗ nhớ kèm `note`. Không có bài test đơn lẻ nào thấy
 * được chuyện đó — xét riêng từng bản thì payload nào cũng "hợp lệ".
 *
 * Nên bài test này không kiểm hành vi, nó kiểm SỰ ĐỒNG NHẤT giữa các bản chép,
 * bằng cách đọc thẳng source. Cùng họ với lỗi ở #1763 (một màn dựng hai lần,
 * hai bản không đồng ý với nhau).
 *
 * Nếu sau này gộp được các chỗ này về một hàm dùng chung thì bài test này thành
 * thừa — và đó là kết cục đáng mong đợi, cứ xoá đi.
 */

import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { readFileSync } from "node:fs";
import { join } from "node:path";

/** Các file dựng payload item gửi lên `POST …/orders`, hoặc dựng draft item rồi POST lại từ đó. */
const PAYLOAD_SOURCES = [
  "components/checkout-page.tsx",
  "components/checkout-page-mobile.tsx",
  "app/[locale]/order-confirm/[id]/page.tsx",
  "app/[locale]/dine-in/[shop]/table/[qrToken]/confirm/page.tsx",
];

const repoRoot = join(import.meta.dirname, "..");

/**
 * Cắt lấy phần thân object literal bắt đầu từ mỗi `product_sku_id:`.
 *
 * Dừng ở `};` hoặc `});` — đủ để bao trọn literal mà không nuốt sang đoạn code
 * sau nó, nên một `note:` nằm ở tận hàm khác không thể vô tình làm bài test này
 * xanh.
 */
function payloadLiterals(source: string): string[] {
  const out: string[] = [];
  let from = 0;

  for (;;) {
    const at = source.indexOf("product_sku_id:", from);
    if (at === -1) break;

    const rest = source.slice(at);
    const end = rest.search(/\n\s*\}[),;]/);
    out.push(end === -1 ? rest.slice(0, 600) : rest.slice(0, end));
    from = at + "product_sku_id:".length;
  }

  return out;
}

describe("order item payload — #1768", () => {
  it("mọi chỗ dựng payload item đều gửi kèm `note`", () => {
    const missing: string[] = [];

    for (const rel of PAYLOAD_SOURCES) {
      const source = readFileSync(join(repoRoot, rel), "utf8");
      const literals = payloadLiterals(source);

      assert.ok(
        literals.length > 0,
        `${rel}: không tìm thấy chỗ nào dựng payload item — file đã đổi cấu trúc, cập nhật lại danh sách PAYLOAD_SOURCES`
      );

      literals.forEach((literal, idx) => {
        if (!/\bnote:/.test(literal)) {
          missing.push(`${rel} (payload thứ ${idx + 1})`);
        }
      });
    }

    assert.deepEqual(
      missing,
      [],
      `Payload item thiếu \`note\` — ghi chú của khách sẽ rơi ở đường này:\n  ${missing.join("\n  ")}`
    );
  });

  it("draft item giữ được `note` để bước xác nhận còn cái mà forward", () => {
    // Đường trả tại quầy POST HOÀN TOÀN từ draft. Draft không có chỗ chứa note
    // thì bước commit không thể gửi note, dù chỗ dựng payload có đúng đến đâu.
    const draftSource = readFileSync(join(repoRoot, "lib/checkout-draft.ts"), "utf8");
    const itemShape = draftSource.slice(
      draftSource.indexOf("export interface CheckoutDraftItem"),
      draftSource.indexOf("export interface CheckoutDraft ")
    );

    assert.ok(
      /\bnote\?:\s*string/.test(itemShape),
      "CheckoutDraftItem thiếu `note?: string` — đường trả tại quầy sẽ lại vứt ghi chú"
    );
  });
});
