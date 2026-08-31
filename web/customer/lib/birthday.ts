// Ngày sinh chọn bằng ba ô select (ngày · tháng · năm), không gõ tay.
//
// Ô gõ tay `DD/MM/YYYY` bắt người dùng đoán thứ tự — mà thứ tự đó KHÁC NHAU
// theo ngôn ngữ (ja 年/月/日, en M/D/Y, vi D/M/Y), nên cùng chuỗi `03/04/2002`
// là hai ngày khác nhau với hai người khác nhau, và không có gì trên màn hình
// nói ai đúng. Ba ô select bỏ hẳn chỗ mơ hồ đó: mỗi ô chỉ mang một nghĩa, và
// một ngày không tồn tại (31/02) không nằm trong danh sách để mà chọn.

export type BirthdayUnit = "year" | "month" | "day";

export interface BirthdayParts {
  /** Chuỗi rỗng = chưa chọn. Giữ dạng chuỗi vì đây là `value` của `<select>`. */
  year: string;
  month: string;
  day: string;
}

export const EMPTY_BIRTHDAY: BirthdayParts = { year: "", month: "", day: "" };

/** Bao nhiêu năm được liệt kê trong ô "năm", tính lùi từ năm hiện tại. */
export const BIRTH_YEAR_SPAN = 120;

/**
 * Thứ tự ba ô theo quy ước viết ngày của từng ngôn ngữ. Đọc đúng thứ tự quen
 * thuộc thì không ai phải dừng lại kiểm tra xem ô giữa là tháng hay ngày.
 */
export function birthdayFieldOrder(locale: string | null | undefined): BirthdayUnit[] {
  const lang = (locale ?? "").split("-")[0].toLowerCase();
  if (lang === "ja" || lang === "ko" || lang === "zh") return ["year", "month", "day"];
  if (lang === "en") return ["month", "day", "year"];
  return ["day", "month", "year"];
}

/**
 * Số ngày của tháng. Chưa chọn năm ⇒ lấy một năm nhuận để 29/2 vẫn chọn được;
 * `resolveBirthday` chặn lại sau, khi đã biết năm.
 */
export function daysInMonth(month: number | null, year: number | null): number {
  if (!month || month < 1 || month > 12) return 31;
  return new Date(Date.UTC(year ?? 2000, month, 0)).getUTCDate();
}

/** Danh sách năm, mới nhất trước — người ta cuộn xuống quá khứ, không cuộn lên. */
export function birthYearOptions(currentYear: number, span = BIRTH_YEAR_SPAN): number[] {
  return Array.from({ length: span + 1 }, (_, i) => currentYear - i);
}

/** `YYYY-MM-DD` (API) → ba ô. Chuỗi rỗng/sai dạng ⇒ ba ô trống. */
export function parseBirthday(iso: string | null | undefined): BirthdayParts {
  if (!iso) return EMPTY_BIRTHDAY;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) return EMPTY_BIRTHDAY;
  // Bỏ số 0 đứng đầu: `value` của `<option>` là "3", không phải "03".
  return { year: m[1], month: String(Number(m[2])), day: String(Number(m[3])) };
}

/**
 * Đổi tháng/năm có thể làm ngày đang chọn biến mất (31 → tháng 2). Xoá ô ngày
 * thay vì tự kéo về 28: tự sửa ngày sinh của người khác là đoán, và đoán sai
 * thì không ai nhìn thấy.
 */
export function clampBirthday(parts: BirthdayParts): BirthdayParts {
  const day = Number(parts.day);
  if (!day) return parts;
  const max = daysInMonth(Number(parts.month) || null, Number(parts.year) || null);
  return day > max ? { ...parts, day: "" } : parts;
}

export type BirthdayResolution =
  /** Cả ba ô trống = xoá khai báo (backend nhận `null`). */
  | { status: "empty"; iso: null }
  | { status: "ok"; iso: string }
  /** Chọn dở — một hoặc hai ô còn trống. */
  | { status: "incomplete" }
  /** Ngày không tồn tại (29/2 năm không nhuận) — UI chặn được, đây là lưới cuối. */
  | { status: "invalid" }
  | { status: "future" };

/**
 * Ba ô → `YYYY-MM-DD` cho API. `todayIso` là ngày dân sự hôm nay theo đồng hồ
 * người dùng — sinh nhật không phải mốc nghiệp vụ của chi nhánh nào, và backend
 * cũng kiểm bằng app clock (`before_or_equal:today`, #1091-ok).
 */
export function resolveBirthday(parts: BirthdayParts, todayIso: string): BirthdayResolution {
  const { year, month, day } = parts;

  if (!year && !month && !day) return { status: "empty", iso: null };
  if (!year || !month || !day) return { status: "incomplete" };

  const y = Number(year);
  const m = Number(month);
  const d = Number(day);
  if (!y || !m || !d || m > 12 || d > daysInMonth(m, y)) return { status: "invalid" };

  const iso = `${String(y).padStart(4, "0")}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
  // So sánh chuỗi `YYYY-MM-DD` là so sánh ngày — cùng độ dài, cùng thứ tự.
  if (iso > todayIso) return { status: "future" };

  return { status: "ok", iso };
}

/** Ngày dân sự hôm nay theo đồng hồ máy người dùng, dạng `YYYY-MM-DD`. */
export function todayIso(now: Date = new Date()): string {
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  const d = String(now.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}
