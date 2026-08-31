/**
 * Cỡ chữ mã bàn tự hạ theo độ dài — mã bàn phải ĐỌC ĐƯỢC TRỌN.
 *
 * Đo 2026-08-18 tại Tsukiji: cỡ cố định text-2xl/3xl làm "COUNTER-01" thành
 * "COUNTE…" ở lưới bàn, còn TablePicker trong dialog New order thì TRÀN đè
 * sang card bên cạnh (truncate chết vì thiếu min-w-0 trên cha flex). Mã bàn
 * là thứ nhân viên đối chiếu bằng mắt với thẻ bàn thật — cụt hay tràn đều
 * làm sai bàn.
 *
 * Bậc thang theo số ký tự, không đo pixel: deterministic, không layout
 * thrash, đủ đúng cho bề rộng card hiện tại. Truncate vẫn giữ ở caller làm
 * lưới an toàn cuối cho tên dài bất thường (>15 ký tự đã hạ về cỡ nhỏ nhất).
 */
export function tableNameSizeClass(
  name: string,
  scale: "overview" | "picker" = "overview",
): string {
  const n = name.length;
  if (scale === "picker") {
    if (n <= 7) return "text-3xl";
    if (n <= 11) return "text-2xl";
    if (n <= 15) return "text-lg";
    return "text-base";
  }
  if (n <= 7) return "text-2xl sm:text-3xl";
  if (n <= 11) return "text-lg sm:text-2xl";
  if (n <= 15) return "text-base sm:text-lg";
  return "text-sm sm:text-base";
}
