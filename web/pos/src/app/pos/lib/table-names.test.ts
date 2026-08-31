import { describe, expect, it } from "vitest";
import {
  TABLE_NAME_SEPARATOR,
  joinTableNames,
  tableDisplayName,
} from "./table-names";

describe("tableDisplayName", () => {
  it("ưu tiên tên riêng, không có thì mã bàn", () => {
    expect(tableDisplayName({ code: "A-2", name: "Sân vườn" })).toBe("Sân vườn");
    expect(tableDisplayName({ code: "A-2", name: null })).toBe("A-2");
  });

  it("tên toàn khoảng trắng không được thành nhãn rỗng", () => {
    expect(tableDisplayName({ code: "A-2", name: "   " })).toBe("A-2");
  });

  it("không có gì → chuỗi rỗng, không ném", () => {
    expect(tableDisplayName({ code: null, name: null })).toBe("");
    expect(tableDisplayName(null)).toBe("");
    expect(tableDisplayName(undefined)).toBe("");
  });
});

describe("joinTableNames", () => {
  it("nối bằng DẤU CỘNG — đơn gộp bàn là MỘT đơn, không phải một danh sách", () => {
    expect(joinTableNames([{ code: "A-1" }, { code: "A-2" }])).toBe("A-1 + A-2");
    expect(TABLE_NAME_SEPARATOR).toBe(" + ");
  });

  it("sắp ổn định bất kể thứ tự nguồn", () => {
    // Hai nguồn (`order.tables` và feed bàn) trả khác thứ tự nhau. Không sắp thì
    // nhãn xáo lại ngay trước mắt thu ngân khi tab được mở.
    const a = joinTableNames([{ code: "A-3" }, { code: "A-1" }, { code: "A-2" }]);
    const b = joinTableNames([{ code: "A-2" }, { code: "A-3" }, { code: "A-1" }]);

    expect(a).toBe("A-1 + A-2 + A-3");
    expect(a).toBe(b);
  });

  it("sắp theo SỐ, không theo chuỗi — A-2 đứng trước A-10", () => {
    // So sánh chuỗi trần xếp "A-10" trước "A-2". Chỉ quán trên 9 bàn mỗi khu
    // mới gặp, và lúc đó nó trông như lỗi ngẫu nhiên.
    expect(joinTableNames([{ code: "A-10" }, { code: "A-2" }])).toBe("A-2 + A-10");
  });

  it("bỏ bàn không tên lẫn không mã, không để lại dấu cộng thừa", () => {
    expect(joinTableNames([{ code: "A-1" }, { code: "", name: null }, { code: "A-2" }]))
      .toBe("A-1 + A-2");
  });

  it("một bàn → không có dấu nối", () => {
    expect(joinTableNames([{ code: "A-1" }])).toBe("A-1");
  });

  it("rỗng / thiếu → chuỗi rỗng", () => {
    expect(joinTableNames([])).toBe("");
    expect(joinTableNames(null)).toBe("");
    expect(joinTableNames(undefined)).toBe("");
    expect(joinTableNames([null, undefined])).toBe("");
  });
});
