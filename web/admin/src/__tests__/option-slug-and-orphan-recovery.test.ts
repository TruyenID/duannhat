import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { toOptionSlug } from "@/lib/option-slug";

/**
 * #2488 — hai thứ khiến một lần xoá SKU nhầm thành bế tắc không lối ra.
 *
 * Bối cảnh: sản phẩm Rượu Gin của Betoya. Khách tạo 2 biến thể (thành công),
 * một SKU bị xoá, giá trị tuỳ chọn ở lại mồ côi — màn hình hiện 2 chip thuộc
 * tính nhưng 1 dòng biến thể — và mọi cách gõ lại đều bị chặn.
 *
 * Phía backend đã ghim ở `OrphanOptionValueAfterSkuDeleteTest.php`. File này
 * ghim hai thứ chỉ tồn tại ở client.
 */

describe("#2488 — slug của giá trị tuỳ chọn là TẤT ĐỊNH trên nhãn", () => {
  /*
   * `slugifyAscii` trả chuỗi RỖNG cho kanji/katakana (chúng không có phân rã
   * NFD sang ASCII), nên `toOptionSlug` rơi về `value_<hash nhãn>`. Cái hash đó
   * không phải chi tiết trang trí — nó là KHOÁ mà backend dùng để nhận ra một
   * giá trị đã bị xoá mềm và KHÔI PHỤC nó thay vì tạo hàng mới
   * (`ProductOptionService::syncValues`, nhánh `$trashed`).
   *
   * Đổi hàm băm ⇒ mọi giá trị tiếng Nhật đang tồn tại thành KHÔNG VỚI TỚI ĐƯỢC:
   * thêm lại đúng nhãn cũ sẽ sinh slug khác, nên nhánh khôi phục không khớp và
   * hệ thống tạo một giá trị trùng lặp bên cạnh cái cũ. Hỏng im lặng, và hỏng
   * theo hướng làm bẩn dữ liệu chứ không phải báo lỗi.
   *
   * Ba giá trị dưới đây KHÔNG phải bịa: chúng là `product_option_values.value`
   * đọc thẳng từ production của sản phẩm trong sự cố.
   */
  it.each([
    ["メガ翠ジン", "value_1deglb5"],
    ["翠ジン", "value_i51sxu"],
    ["翠ジン -", "value_195ayup"],
  ])("%s → %s (khớp production)", (label, slug) => {
    expect(toOptionSlug(label, "value")).toBe(slug);
  });

  it("cùng nhãn luôn ra cùng slug — đây là điều nhánh khôi phục dựa vào", () => {
    expect(toOptionSlug("翠ジン", "value")).toBe(toOptionSlug("翠ジン", "value"));
  });

  it("hai nhãn khác nhau ra hai slug khác nhau, kể cả khi chỉ hơn nhau một ký tự", () => {
    // `翠ジン` và `翠ジン -` va nhau thì việc gõ lại tên khác — đường thoát duy
    // nhất khách tìm được — cũng hỏng nốt.
    expect(toOptionSlug("翠ジン", "value")).not.toBe(toOptionSlug("翠ジン -", "value"));
  });

  it("nhãn ASCII vẫn đi đường slug thường, không băm", () => {
    // Nhánh băm chỉ dành cho nhãn không còn gì sau khi lọc ASCII. Băm cả nhãn
    // ASCII sẽ biến `size_large` thành `value_xxxx` và làm mọi dữ liệu cũ lệch.
    expect(toOptionSlug("Large", "value")).toBe("large");
    expect(toOptionSlug("Extra Large", "value")).toBe("extra_large");
  });

  it("nhãn rỗng → chuỗi rỗng, KHÔNG phải `value_` cụt", () => {
    // `value_` cụt sẽ va vào chính nó ở mọi hàng trống, biến hai hàng chưa điền
    // thành một lỗi trùng khoá khó hiểu.
    expect(toOptionSlug("", "value")).toBe("");
    expect(toOptionSlug("   ", "value")).toBe("");
    expect(toOptionSlug(null, "value")).toBe("");
  });
});

describe("#2488 — đường cứu giá trị mồ côi phải với tới được từ giao diện", () => {
  /*
   * `generate-combinations` sinh lại đúng tổ hợp còn thiếu và khôi phục SKU đã
   * xoá mềm kèm giá cũ. Trong suốt sự cố #2488 nó tồn tại đủ ở backend +
   * service + hook — `useGenerateSkuCombinations` thậm chí được IMPORT trong
   * variants-display từ ngày đầu — mà không một lần được GỌI. Import-nhưng-
   * không-gọi chính là hình dạng hỏng đã xảy ra thật, nên bài này canh đúng
   * sợi dây đó chứ không canh dòng import.
   */
  it("variants-display thật sự GỌI useGenerateSkuCombinations, không chỉ import", () => {
    const src = readFileSync(
      resolve(
        process.cwd(),
        "src/app/hq/[brandSlug]/products/[id]/components/variants-display.tsx"
      ),
      "utf8"
    );

    expect(src).toMatch(/useGenerateSkuCombinations\(/);
    // Và kết quả phải nối vào một nút bấm được — mutate là thứ nút gọi.
    expect(src).toContain("generateMissing.mutate()");
  });
});
