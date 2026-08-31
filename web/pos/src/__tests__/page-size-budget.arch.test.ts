import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * Đếm dòng GIỐNG `wc -l`: số ký tự xuống dòng.
 *
 * `split("\n").length` dư đúng 1 khi file kết thúc bằng newline (mọi file ở
 * đây đều vậy), nên trần đặt theo số `wc -l` sẽ đỏ ngay ở nguyên trạng — bản
 * đầu của test này đỏ đúng vì lý do đó, không phải vì file quá to.
 */
function lineCount(path: string): number {
  const text = readFileSync(path, "utf8");
  return text.endsWith("\n") ? text.split("\n").length - 1 : text.split("\n").length;
}

/*
 * #1770 — trần dòng cho `src/app/pos/page.tsx`, kiểu BÁNH CÓC.
 *
 * Lịch sử của chính file này là lý do rào tồn tại:
 *
 *   1289 dòng  → #283 được mở ("refactor page.tsx")
 *   1387 dòng  → #283 đóng COMPLETED (2026-07-10)
 *   1760 dòng  → hôm nay
 *
 * Việc tách component CÓ xảy ra — `src/app/pos/components/` đầy — nhưng file
 * gốc chưa bao giờ nhỏ đi, và từ lúc issue đóng nó phình thêm 373 dòng nữa.
 * Không PR nào ẩu cả: mọi tính năng POS mới đều tự nhiên rơi vào đây, và không
 * có gì đo.
 *
 * Nên rào đi TRƯỚC việc tách, không phải sau. Tách mà không có trần thì ba
 * tháng nữa lặp lại đúng bảng trên — đây là bằng chứng, không phải phỏng đoán.
 *
 * ## Luật
 *
 * Con số này chỉ được GIẢM. Muốn thêm tính năng thì đưa ra component; hạ được
 * trần thì hạ luôn trong cùng PR. Nếu bạn đang định NÂNG nó lên, việc bạn định
 * làm chính là thứ rào này sinh ra để chặn.
 *
 * Cố ý chỉ ghim MỘT file. Đặt trần cho mọi file sẽ kêu ở khắp nơi và bị tắt.
 */

// 950 → 926 (#2049): hai khối mount màn đóng đơn gộp thành `ClosingReceipt`.
// 906 → 905 (#2946): khối `splitError` ba nhánh gộp vào `getApiErrorMessage`,
// vốn đã được import sẵn ở file này — lấy chỗ cho prop `shopSlug` mà luồng thu
// bằng máy 釣銭機 cần.
// 926 → 917 (#2479): hai hộp thoại huỷ/mở-lại về ở `OrderCart` cùng state của
// chúng — page.tsx không còn giữ `voidOrderOpen` lẫn khối mount VoidOrderDialog.
// Hạ trong CÙNG PR với việc tách, đúng luật bánh cóc ở trên.
// 917 → 906: ✕ trên tab thôi gọi `DELETE /orders/{id}` — `runCloseTab`, mutation
// `useDeleteOrder` và nhánh đếm-món biến mất; phần quyết định còn lại ra
// `lib/close-tab-policy.ts` (thuần, test được).
const BUDGET = 905;

describe("ngân sách kích thước page.tsx (#1770)", () => {
  it(`src/app/pos/page.tsx ≤ ${BUDGET} dòng — trần chỉ được giảm`, () => {
    const lines = lineCount(resolve(__dirname, "../app/pos/page.tsx"));

    expect(lines, [
      `page.tsx đang ${lines} dòng, trần ${BUDGET}.`,
      "",
      "Nếu bạn vừa THÊM: đưa phần mới ra component trong src/app/pos/components/.",
      "Nếu bạn vừa BỚT: hạ hằng BUDGET xuống mức mới trong cùng PR — bánh cóc",
      "chỉ có ý nghĩa khi nó thật sự đi xuống.",
    ].join("\n")).toBeLessThanOrEqual(BUDGET);
  });

  it("trần phải BÁM SÁT thực tế — nới rộng quá thì nó không còn chặn gì", () => {
    const lines = lineCount(resolve(__dirname, "../app/pos/page.tsx"));

    // Một trần cao hơn thực tế 200 dòng cho phép phình âm thầm tới tận đó rồi
    // mới kêu — tức rào tồn tại nhưng ngủ. Test này bắt phải hạ trần khi file
    // đã nhỏ đi, thay vì để dư địa tích lại.
    expect(BUDGET - lines).toBeLessThanOrEqual(200);
  });
});
