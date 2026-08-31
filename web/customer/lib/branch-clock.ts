/**
 * godx-tempo#1767 — một màn hình, một cái đồng hồ.
 *
 * Màn `/checkout` từng bày cạnh nhau hai loại con số giờ mà không con nào ghi
 * kèm múi giờ: ô chọn giờ hẹn đọc theo đồng hồ MÁY KHÁCH, còn giờ mở/đóng cửa
 * và câu chặn đọc theo đồng hồ CHI NHÁNH (`weekly_hours` là giờ treo tường tại
 * quán — xem `lib/opening-hours.ts`). Khách ở múi giờ khác đọc ra một câu vô
 * lý: "tôi chọn 21:30, quán mở tới 22:00, sao lại bảo tôi chọn sau giờ đóng
 * cửa?" — vì 21:30 kia là giờ máy họ, còn 22:00 là giờ Tokyo.
 *
 * Việc chặn thì đúng: slot đó thật sự nằm ngoài giờ mở cửa, và server cũng từ
 * chối y hệt (422 PICKUP_OUTSIDE_OPENING_HOURS). Chỉ phần hiển thị là sai.
 *
 * Đồng hồ được chọn làm chuẩn là đồng hồ CHI NHÁNH, vì nghiệp vụ đã lấy nó làm
 * chuẩn từ trước (#1091, #1160) và vì đó cũng là đồng hồ mà khách phải có mặt
 * tại quán để lấy đồ.
 *
 * Module này giữ phép quy đổi HAI CHIỀU giữa một *instant* (mốc thời gian tuyệt
 * đối, thứ gửi lên backend) và các con số trên MẶT ĐỒNG HỒ tại chi nhánh. Chiều
 * đọc trước đây nằm ẩn trong `atBranch` của opening-hours; giờ cả hai chiều ở
 * chung một chỗ để không còn hai định nghĩa "giờ chi nhánh" lệch nhau — đúng
 * cái bệnh mà issue này nói tới.
 *
 * KHÔNG đụng tới hợp đồng gửi lên BE: cái gửi đi vẫn là `toISOString()` của
 * đúng instant khách chọn (xem `components/pickup-time-selector.tsx`). Đổi chỗ
 * đó là làm hồi quy commit `0fa5b82`.
 */

/** Các con số trên mặt đồng hồ. `month` là 1-12, KHÔNG phải 0-11 như `Date`. */
export interface WallClock {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
}

/** Mặt đồng hồ của chính thiết bị — đường lui khi không biết múi giờ chi nhánh. */
function deviceWallClock(instant: Date): WallClock {
  return {
    year: instant.getFullYear(),
    month: instant.getMonth() + 1,
    day: instant.getDate(),
    hour: instant.getHours(),
    minute: instant.getMinutes(),
  };
}

/**
 * Instant → các con số mà một cái đồng hồ treo ở `timeZone` đang chỉ.
 *
 * Không có `timeZone`, hoặc `timeZone` là một tên IANA mà runtime không hiểu →
 * rơi về đồng hồ thiết bị. Đây đúng là cách `isOpenAt` đã fail-open từ trước:
 * một chuỗi cấu hình hỏng không được phép chặn khách thanh toán, và quan trọng
 * hơn — cả màn phải cùng rơi về một đường lui, chứ nửa này rơi nửa kia không
 * thì lại đẻ ra đúng cái mâu thuẫn đang đi sửa.
 */
export function wallClockAt(instant: Date, timeZone?: string | null): WallClock {
  if (Number.isNaN(instant.getTime())) return deviceWallClock(instant);
  if (!timeZone) return deviceWallClock(instant);

  try {
    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    }).formatToParts(instant);

    const read = (type: Intl.DateTimeFormatPartTypes) =>
      Number(parts.find((p) => p.type === type)?.value);

    const year = read("year");
    const month = read("month");
    const day = read("day");
    // Nửa đêm có runtime render thành "24" — kéo về 0 để khỏi trôi sang ngày sau.
    const hour = read("hour") % 24;
    const minute = read("minute");

    if ([year, month, day, hour, minute].some(Number.isNaN)) {
      throw new Error("unparsable");
    }

    return { year, month, day, hour, minute };
  } catch {
    return deviceWallClock(instant);
  }
}

/**
 * Chênh lệch của `timeZone` so với UTC tại `instant`, tính bằng phút
 * (Asia/Tokyo → +540). Có dấu ngược với `Date.prototype.getTimezoneOffset()`.
 */
export function zoneOffsetMinutes(instant: Date, timeZone?: string | null): number {
  if (!timeZone) return -instant.getTimezoneOffset();

  const wall = wallClockAt(instant, timeZone);
  const asIfUtc = Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute);
  // Mặt đồng hồ chỉ có tới phút, nên phải so với instant đã cắt về phút —
  // không thì phần giây lẻ đội thành cả một phút chênh.
  const truncated = Math.floor(instant.getTime() / 60_000) * 60_000;

  return Math.round((asIfUtc - truncated) / 60_000);
}

/**
 * Chiều ngược lại: các con số trên mặt đồng hồ tại `timeZone` → instant.
 *
 * Lấy hai lượt vì chính offset cũng phụ thuộc vào thời điểm: đoán instant bằng
 * offset đo tại điểm đoán, rồi đo lại offset tại kết quả. Hai lượt là đủ cho
 * mọi lần đổi giờ mùa hè — chỉ lượt đầu mới có thể đứng nhầm bên ranh giới.
 * Giờ rơi đúng vào khoảng trống lúc "nhảy lên" (giờ không tồn tại) sẽ trả về
 * mốc ngay sau khoảng trống, đúng như cách `Temporal` xử lý mặc định.
 */
export function instantFromWallClock(wall: WallClock, timeZone?: string | null): Date {
  if (!timeZone) {
    return new Date(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute, 0, 0);
  }

  const asIfUtc = Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute);

  const firstGuess = zoneOffsetMinutes(new Date(asIfUtc), timeZone);
  const candidate = asIfUtc - firstGuess * 60_000;

  const settled = zoneOffsetMinutes(new Date(candidate), timeZone);
  if (settled === firstGuess) return new Date(candidate);

  return new Date(asIfUtc - settled * 60_000);
}

/** Thứ trong tuần của một mặt đồng hồ: 0 = Chủ nhật, khớp `Date#getDay()`. */
export function weekdayIndexOf(wall: WallClock): number {
  return new Date(Date.UTC(wall.year, wall.month - 1, wall.day)).getUTCDay();
}

/**
 * Đồng hồ chi nhánh có lệch với đồng hồ máy khách tại thời điểm này không.
 *
 * So bằng OFFSET chứ không so tên vùng: `Asia/Tokyo` và `Asia/Seoul` là hai
 * tên khác nhau nhưng cùng +09:00, khách Seoul đặt đồ ở Tokyo đọc số nào cũng
 * như nhau — nói thêm với họ về múi giờ chỉ tổ gây hoang mang. Cái đáng báo là
 * lúc hai con số THẬT SỰ lệch nhau.
 */
export function branchClockDiffersFromDevice(
  instant: Date,
  timeZone?: string | null,
): boolean {
  if (!timeZone) return false;

  return zoneOffsetMinutes(instant, timeZone) !== -instant.getTimezoneOffset();
}
