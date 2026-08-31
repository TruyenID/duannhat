import { describe, expect, it } from "vitest";
import { tableNameSizeClass } from "./table-name";

describe("tableNameSizeClass", () => {
  it("giữ cỡ lớn cho mã ngắn", () => {
    expect(tableNameSizeClass("HALL-01")).toBe("text-2xl sm:text-3xl");
    expect(tableNameSizeClass("HALL-01", "picker")).toBe("text-3xl");
  });

  it("hạ cỡ cho mã 8-11 ký tự — COUNTER-01 từng thành COUNTE…", () => {
    expect(tableNameSizeClass("COUNTER-01")).toBe("text-lg sm:text-2xl");
    expect(tableNameSizeClass("TERRACE-01", "picker")).toBe("text-2xl");
  });

  it("hạ tiếp cho mã dài, sàn ở cỡ nhỏ nhất", () => {
    expect(tableNameSizeClass("PRIVATE-ROOM-1")).toBe("text-base sm:text-lg");
    expect(tableNameSizeClass("A-VERY-LONG-TABLE-NAME")).toBe("text-sm sm:text-base");
    expect(tableNameSizeClass("A-VERY-LONG-TABLE-NAME", "picker")).toBe("text-base");
  });
});
