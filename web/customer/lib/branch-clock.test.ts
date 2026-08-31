import assert from "node:assert/strict";
import test from "node:test";

import {
  branchClockDiffersFromDevice,
  instantFromWallClock,
  wallClockAt,
  weekdayIndexOf,
  zoneOffsetMinutes,
} from "./branch-clock.ts";

/**
 * godx-tempo#1767 — ca gốc của issue, viết lại thành test.
 *
 * Khách để máy ở giờ Việt Nam (UTC+7) đặt đồ ở chi nhánh Tokyo (UTC+9). Cùng
 * MỘT instant, đồng hồ máy chỉ 21:30 còn đồng hồ quán chỉ 23:30 — và quán đóng
 * lúc 22:00. Trước khi sửa, màn checkout in ra 21:30 rồi báo "đóng cửa lúc
 * 22:00", nên câu chặn đọc ra là vô lý.
 */
const TOKYO = "Asia/Tokyo";
const HANOI = "Asia/Ho_Chi_Minh";

test("cùng một instant, hai múi giờ cho hai mặt đồng hồ khác nhau", () => {
  // 23:30 giờ Tokyo ngày 2026-08-04.
  const instant = new Date("2026-08-04T23:30:00+09:00");

  assert.deepEqual(wallClockAt(instant, TOKYO), {
    year: 2026,
    month: 8,
    day: 4,
    hour: 23,
    minute: 30,
  });

  // Cùng instant đó, đồng hồ Hà Nội mới 21:30 — đây chính là con số mà ô chọn
  // giờ từng hiển thị trong khi câu chặn lại nói theo 22:00 của Tokyo.
  assert.deepEqual(wallClockAt(instant, HANOI), {
    year: 2026,
    month: 8,
    day: 4,
    hour: 21,
    minute: 30,
  });
});

test("mặt đồng hồ chi nhánh có thể rơi sang NGÀY khác với máy khách", () => {
  // 00:30 ngày 05/08 giờ Tokyo = 22:30 ngày 04/08 giờ Hà Nội.
  const instant = new Date("2026-08-05T00:30:00+09:00");

  assert.equal(wallClockAt(instant, TOKYO).day, 5);
  assert.equal(wallClockAt(instant, HANOI).day, 4);
});

test("quy đổi khứ hồi giữ nguyên instant", () => {
  const instant = new Date("2026-08-04T23:30:00+09:00");
  const back = instantFromWallClock(wallClockAt(instant, TOKYO), TOKYO);

  assert.equal(back.getTime(), instant.getTime());
});

test("dựng instant từ giờ treo tường của quán, không phải giờ máy", () => {
  // Khách gõ 23:30 vào bánh xe giờ; con số đó phải được hiểu là 23:30 TẠI QUÁN.
  const instant = instantFromWallClock(
    { year: 2026, month: 8, day: 4, hour: 23, minute: 30 },
    TOKYO,
  );

  assert.equal(instant.toISOString(), "2026-08-04T14:30:00.000Z");
});

test("offset đọc đúng dấu và đúng độ lớn", () => {
  const instant = new Date("2026-08-04T12:00:00Z");

  assert.equal(zoneOffsetMinutes(instant, TOKYO), 540);
  assert.equal(zoneOffsetMinutes(instant, HANOI), 420);
});

test("giây lẻ không bị đội thành một phút offset", () => {
  // Instant có 59 giây: cắt về phút trước khi so, nếu không offset lệch 1 phút.
  const instant = new Date("2026-08-04T12:00:59.750Z");

  assert.equal(zoneOffsetMinutes(instant, TOKYO), 540);
});

test("vùng có đổi giờ mùa hè: cùng giờ treo tường, hai offset khác nhau", () => {
  const NEW_YORK = "America/New_York";

  // Tháng 1 là giờ chuẩn (UTC-5), tháng 7 là giờ mùa hè (UTC-4). Cùng "12:00
  // trưa tại New York" nhưng là hai instant lệch nhau một tiếng so với UTC.
  const winter = instantFromWallClock(
    { year: 2026, month: 1, day: 15, hour: 12, minute: 0 },
    NEW_YORK,
  );
  const summer = instantFromWallClock(
    { year: 2026, month: 7, day: 15, hour: 12, minute: 0 },
    NEW_YORK,
  );

  assert.equal(winter.toISOString(), "2026-01-15T17:00:00.000Z");
  assert.equal(summer.toISOString(), "2026-07-15T16:00:00.000Z");

  // Và đọc ngược lại vẫn ra đúng 12:00 ở cả hai mùa.
  assert.equal(wallClockAt(winter, NEW_YORK).hour, 12);
  assert.equal(wallClockAt(summer, NEW_YORK).hour, 12);
});

test("giờ ngay sát ranh giới đổi giờ vẫn khứ hồi được", () => {
  const NEW_YORK = "America/New_York";
  // 2026-11-01 01:30 tại New York xuất hiện HAI lần (giờ lùi lại). Không đòi
  // hỏi chọn lần nào — chỉ đòi hỏi kết quả là một instant thật, và đọc ngược
  // lại đúng con số khách đã gõ.
  const ambiguous = instantFromWallClock(
    { year: 2026, month: 11, day: 1, hour: 1, minute: 30 },
    NEW_YORK,
  );

  assert.equal(Number.isNaN(ambiguous.getTime()), false);
  assert.equal(wallClockAt(ambiguous, NEW_YORK).hour, 1);
  assert.equal(wallClockAt(ambiguous, NEW_YORK).minute, 30);
});

test("không có múi giờ chi nhánh → rơi về đồng hồ máy, khứ hồi vẫn khớp", () => {
  const instant = new Date("2026-08-04T14:30:00Z");
  const wall = wallClockAt(instant, null);

  assert.equal(wall.year, instant.getFullYear());
  assert.equal(wall.hour, instant.getHours());
  // Khứ hồi bỏ phần giây, nên so theo phút.
  assert.equal(
    instantFromWallClock(wall, null).getTime(),
    Math.floor(instant.getTime() / 60_000) * 60_000,
  );
});

test("tên múi giờ rác cũng rơi về đồng hồ máy chứ không ném lỗi", () => {
  // Một chuỗi cấu hình hỏng không được phép làm sập màn thanh toán. Quan trọng
  // hơn: `isOpenAt` cũng fail-open y hệt, nên cả màn cùng rơi về MỘT đường lui.
  const instant = new Date("2026-08-04T14:30:00Z");

  assert.deepEqual(wallClockAt(instant, "Không/Phải_Múi_Giờ"), wallClockAt(instant, null));
});

test("thứ trong tuần tính từ mặt đồng hồ chi nhánh", () => {
  // 2026-08-04 là thứ Ba.
  assert.equal(weekdayIndexOf({ year: 2026, month: 8, day: 4, hour: 23, minute: 30 }), 2);
  // Cùng instant nhưng đọc ở Hà Nội vẫn là 04/08 → vẫn thứ Ba.
  // Còn 05/08 giờ Tokyo thì đã sang thứ Tư.
  assert.equal(weekdayIndexOf({ year: 2026, month: 8, day: 5, hour: 0, minute: 30 }), 3);
});

test("chỉ báo lệch giờ khi hai đồng hồ THẬT SỰ lệch", () => {
  const instant = new Date("2026-08-04T12:00:00Z");
  const deviceZoneOffset = -instant.getTimezoneOffset();

  // Không biết múi giờ quán → không có gì để nói.
  assert.equal(branchClockDiffersFromDevice(instant, null), false);

  // Một vùng cùng offset với máy đang chạy test: không được báo lệch, kể cả
  // khi tên vùng khác. Tokyo và Seoul cùng +09:00 là ca kinh điển.
  const sameOffsetZone = deviceZoneOffset === 540 ? "Asia/Seoul" : "UTC";
  const expectSame = deviceZoneOffset === 540 || deviceZoneOffset === 0;
  if (expectSame) {
    assert.equal(branchClockDiffersFromDevice(instant, sameOffsetZone), false);
  }

  // Một vùng chắc chắn lệch với mọi máy hợp lý: chọn theo offset máy để test
  // không phụ thuộc TZ của môi trường chạy.
  const differentZone = deviceZoneOffset === 540 ? "America/New_York" : TOKYO;
  assert.equal(branchClockDiffersFromDevice(instant, differentZone), true);
});
