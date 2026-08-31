/**
 * Bản sao của `settingTruthy` bên Go (`internal/handler/print_template_seam.go`).
 *
 * Vì sao cần một hàm riêng thay vì `v === "true"` như mọi toggle khác: đa số
 * khoá settings được Go đọc bằng `== "true"` nghiêm ngặt (`auto_print_bill`,
 * `auto_print_kitchen`, `kds_show_only_fired`), nên so sánh thẳng ở UI là ĐÚNG
 * cho chúng. Riêng `print_template_use_published_templates` được Go đọc bằng
 * `settingTruthy`, vốn nhận cả `1` và `yes`.
 *
 * Lệch đó không phải lý thuyết (#2022): trước khi có toggle, cách DUY NHẤT để
 * bật cờ này là `PUT /api/settings/… {"value":"1"}` qua loopback — nên đúng
 * những máy đã được đem ra thử nghiệm là những máy mang giá trị `1`, và với
 * `v === "true"` thì panel hiện TẮT trong khi máy đang in bằng mẫu đã phát
 * hành. Một màn hình nói ngược với tờ giấy.
 *
 * Không siết Go lại cho khớp UI: `1`/`yes` đã nằm trong DB của máy thật, siết
 * lại là TẮT một máy đang bật.
 */
export function settingTruthy(raw: string | null | undefined): boolean {
  switch ((raw ?? "").trim().toLowerCase()) {
    case "1":
    case "true":
    case "yes":
      return true;
    default:
      return false;
  }
}
