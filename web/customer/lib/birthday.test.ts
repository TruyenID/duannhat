import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
  birthYearOptions,
  birthdayFieldOrder,
  clampBirthday,
  daysInMonth,
  parseBirthday,
  resolveBirthday,
  todayIso,
} from "./birthday.ts";

describe("birthdayFieldOrder", () => {
  it("theo quy ước viết ngày của từng ngôn ngữ", () => {
    assert.deepEqual(birthdayFieldOrder("ja"), ["year", "month", "day"]);
    assert.deepEqual(birthdayFieldOrder("en"), ["month", "day", "year"]);
    assert.deepEqual(birthdayFieldOrder("vi"), ["day", "month", "year"]);
  });

  it("bỏ qua phần vùng của locale và ngôn ngữ lạ thì theo D/M/Y", () => {
    assert.deepEqual(birthdayFieldOrder("en-GB"), ["month", "day", "year"]);
    assert.deepEqual(birthdayFieldOrder("ja-JP"), ["year", "month", "day"]);
    assert.deepEqual(birthdayFieldOrder(null), ["day", "month", "year"]);
  });
});

describe("daysInMonth", () => {
  it("đếm đúng từng tháng", () => {
    assert.equal(daysInMonth(1, 2003), 31);
    assert.equal(daysInMonth(4, 2003), 30);
    assert.equal(daysInMonth(2, 2003), 28);
  });

  it("năm nhuận có 29/2, năm thế kỷ không chia hết 400 thì không", () => {
    assert.equal(daysInMonth(2, 2000), 29);
    assert.equal(daysInMonth(2, 2004), 29);
    assert.equal(daysInMonth(2, 1900), 28);
  });

  it("chưa chọn năm ⇒ mở 29/2 để người dùng chọn tiếp, chưa chọn tháng ⇒ 31", () => {
    assert.equal(daysInMonth(2, null), 29);
    assert.equal(daysInMonth(null, 2003), 31);
  });
});

describe("parseBirthday", () => {
  it("bỏ số 0 đứng đầu để khớp `value` của <option>", () => {
    assert.deepEqual(parseBirthday("2002-02-13"), { year: "2002", month: "2", day: "13" });
    assert.deepEqual(parseBirthday("1990-11-05"), { year: "1990", month: "11", day: "5" });
  });

  it("rỗng/sai dạng ⇒ ba ô trống", () => {
    for (const input of [null, undefined, "", "13/02/2002", "2002-2-13"]) {
      assert.deepEqual(parseBirthday(input), { year: "", month: "", day: "" });
    }
  });

  it("đi vòng qua resolveBirthday thì về đúng chuỗi cũ", () => {
    assert.deepEqual(resolveBirthday(parseBirthday("2002-02-13"), "2026-08-03"), {
      status: "ok",
      iso: "2002-02-13",
    });
  });
});

describe("clampBirthday", () => {
  it("xoá ngày khi tháng mới không có ngày đó", () => {
    assert.deepEqual(clampBirthday({ year: "2003", month: "2", day: "31" }), {
      year: "2003",
      month: "2",
      day: "",
    });
  });

  it("29/2 rơi mất khi đổi sang năm không nhuận", () => {
    assert.deepEqual(clampBirthday({ year: "2003", month: "2", day: "29" }), {
      year: "2003",
      month: "2",
      day: "",
    });
    assert.deepEqual(clampBirthday({ year: "2004", month: "2", day: "29" }), {
      year: "2004",
      month: "2",
      day: "29",
    });
  });

  it("giữ nguyên khi ngày vẫn hợp lệ, hoặc khi chưa chọn ngày", () => {
    const kept = { year: "2003", month: "1", day: "31" };
    assert.equal(clampBirthday(kept), kept);
    const noDay = { year: "2003", month: "2", day: "" };
    assert.equal(clampBirthday(noDay), noDay);
  });
});

describe("resolveBirthday", () => {
  const TODAY = "2026-08-03";

  it("ba ô trống = xoá khai báo, không phải lỗi", () => {
    assert.deepEqual(resolveBirthday({ year: "", month: "", day: "" }, TODAY), {
      status: "empty",
      iso: null,
    });
  });

  it("chọn dở thì chặn, không im lặng gửi đi", () => {
    assert.deepEqual(resolveBirthday({ year: "2002", month: "2", day: "" }, TODAY), {
      status: "incomplete",
    });
    assert.deepEqual(resolveBirthday({ year: "", month: "2", day: "13" }, TODAY), {
      status: "incomplete",
    });
  });

  it("đệm số 0 cho đúng dạng API", () => {
    assert.deepEqual(resolveBirthday({ year: "2002", month: "2", day: "3" }, TODAY), {
      status: "ok",
      iso: "2002-02-03",
    });
  });

  it("ngày không tồn tại bị chặn kể cả khi state đã lệch", () => {
    assert.deepEqual(resolveBirthday({ year: "2003", month: "2", day: "29" }, TODAY), {
      status: "invalid",
    });
    assert.deepEqual(resolveBirthday({ year: "2003", month: "13", day: "1" }, TODAY), {
      status: "invalid",
    });
  });

  it("hôm nay được, ngày mai thì không", () => {
    assert.deepEqual(resolveBirthday({ year: "2026", month: "8", day: "3" }, TODAY), {
      status: "ok",
      iso: "2026-08-03",
    });
    assert.deepEqual(resolveBirthday({ year: "2026", month: "8", day: "4" }, TODAY), {
      status: "future",
    });
  });
});

describe("birthYearOptions", () => {
  it("mới nhất trước, phủ đủ span năm", () => {
    const years = birthYearOptions(2026, 3);
    assert.deepEqual(years, [2026, 2025, 2024, 2023]);
  });

  it("mặc định phủ 120 năm — người già nhất vẫn chọn được năm sinh", () => {
    const years = birthYearOptions(2026);
    assert.equal(years.length, 121);
    assert.equal(years[years.length - 1], 1906);
  });
});

describe("todayIso", () => {
  it("là ngày dân sự của đồng hồ máy, có đệm số 0", () => {
    assert.equal(todayIso(new Date(2026, 7, 3)), "2026-08-03");
    assert.equal(todayIso(new Date(2026, 0, 9)), "2026-01-09");
  });
});
