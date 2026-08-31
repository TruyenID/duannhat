/**
 * Nội suy placeholder chạy qua TRANSLATOR THẬT, không qua bản dựng lại (#2974).
 *
 * `catalogue.test.ts` canh *hình dạng* của catalogue — nó bắt được `{{x}}` nằm
 * trong file JSON. File này canh *kết quả* — cái mà thu ngân thật sự đọc.
 *
 * Hai thứ đó không thay nhau được. Một rào chỉ đọc JSON sẽ xanh với bất kỳ cú
 * pháp nào mà nó chưa được dạy để ghét; một rào chạy translator thì nói thẳng
 * "chuỗi ra còn ngoặc thừa" bất kể cú pháp sai kiểu gì. Lỗi #2974 lọt qua BA
 * rào i18n có sẵn (parity · used-but-undefined · dynamic-prefix) vì cả ba đều
 * hỏi về khoá, không hỏi về giá trị sau nội suy.
 *
 * Chạy cho CẢ BA locale: bản dịch được sửa từng file một, nên một locale sót
 * lại là ca hoàn toàn có thật.
 */

import { describe, expect, it } from "vitest";
import { getT } from "@/providers/app-provider";

const LOCALES = ["ja", "en", "vi"] as const;

/** Đặt locale theo đúng đường mà `getT()` đọc, rồi trả về translator. */
function translatorFor(locale: string) {
  localStorage.setItem("pos_locale", locale);

  return getT();
}

describe("#2974 nội suy placeholder", () => {
  it("SKU hiện ra SẠCH, không kèm ngoặc, ở cả ba locale", () => {
    for (const locale of LOCALES) {
      const t = translatorFor(locale);
      const out = t("pos.cart.edit_product_unavailable", { sku: "SKU-42" });

      // Giá trị phải tới nơi…
      expect(out, `${locale}: thiếu SKU trong "${out}"`).toContain("SKU-42");
      // …và KHÔNG được mang theo ngoặc. Đây là vế bắt được lỗi thật: trước bản
      // vá, chuỗi ra là "… SKU {SKU-42} …" — đúng giá trị, sai hình dạng.
      expect(out, `${locale}: còn ngoặc thừa trong "${out}"`).not.toMatch(/[{}]/);
    }
  });

  it("khoá KHÔNG có tham số thì giữ nguyên, không bị đụng tới", () => {
    // Rào cho chính bản vá: một lần "sửa" nội suy quá tay (ví dụ strip mọi
    // ngoặc) sẽ làm hỏng các chuỗi cố ý chứa ngoặc.
    const t = translatorFor("en");

    expect(t("common.save")).not.toBe("");
    expect(t("common.save")).not.toContain("{");
  });
});
