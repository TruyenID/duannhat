import { describe, expect, it } from "vitest";
import { resolveTabLabel, tableLabelsByOrderId } from "./tab-label";
import type { CustomerOrder, TableResource } from "../types";

const table = (over: Partial<TableResource>): TableResource =>
  ({
    id: "t",
    code: "A-1",
    name: null,
    seat_count: 4,
    status: "occupied",
    is_active: true,
    qr_token: "tok",
    current_order_id: null,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    ...over,
  }) as TableResource;

const order = (over: Record<string, unknown>) =>
  ({ id: "o1", order_code: "ORD-2026-3251", ...over }) as unknown as CustomerOrder;

describe("tableLabelsByOrderId", () => {
  it("ánh xạ bàn đang giữ đơn → tên bàn", () => {
    const m = tableLabelsByOrderId([
      table({ id: "t1", code: "A-2", current_order_id: "o1" }),
      table({ id: "t2", code: "B-1", current_order_id: null }),
    ]);

    expect(m.get("o1")).toBe("A-2");
    expect(m.size).toBe(1);
  });

  it("đơn GỘP BÀN nối bằng DẤU CỘNG, sắp theo mã để nhãn không nhảy chỗ", () => {
    // `+` chứ không phải `,`: bốn bàn này là MỘT đơn đã gộp, không phải bốn thứ
    // rời nhau. Thứ tự feed về là ngẫu nhiên; nhãn thì không được nhảy.
    const m = tableLabelsByOrderId([
      table({ id: "t2", code: "A-3", current_order_id: "o1" }),
      table({ id: "t1", code: "A-1", current_order_id: "o1" }),
      table({ id: "t3", code: "A-2", current_order_id: "o1" }),
    ]);

    expect(m.get("o1")).toBe("A-1 + A-2 + A-3");
  });

  it("ưu tiên TÊN riêng của bàn, không có thì dùng mã", () => {
    const m = tableLabelsByOrderId([
      table({ id: "t1", code: "A-2", name: "Sân vườn 1", current_order_id: "o1" }),
      table({ id: "t2", code: "B-1", name: "  ", current_order_id: "o2" }),
    ]);

    expect(m.get("o1")).toBe("Sân vườn 1");
    // Tên toàn khoảng trắng KHÔNG được thành nhãn rỗng — lùi về mã bàn.
    expect(m.get("o2")).toBe("B-1");
  });

  it("bỏ qua bàn không tên lẫn không mã — nhãn rỗng tệ hơn mã đơn", () => {
    const m = tableLabelsByOrderId([
      table({ id: "t1", code: "", name: null, current_order_id: "o1" }),
    ]);

    expect(m.has("o1")).toBe(false);
  });

  it("feed rỗng / thiếu → map rỗng, không ném", () => {
    expect(tableLabelsByOrderId([]).size).toBe(0);
    expect(tableLabelsByOrderId(null).size).toBe(0);
    expect(tableLabelsByOrderId(undefined).size).toBe(0);
  });
});

describe("resolveTabLabel", () => {
  const empty = new Map<string, string>();

  it("có bàn trên ĐƠN → hiện tên bàn", () => {
    expect(
      resolveTabLabel({
        order: order({ tables: [{ code: "A-2", name: null }] }),
        fallbackCode: "ORD-2026-3251",
        orderId: "o1",
        tableLabels: empty,
      }),
    ).toEqual({ kind: "table", text: "A-2" });
  });

  it("đơn chưa vào cache → lấy bàn từ FEED", () => {
    // Đây là ca sau mỗi lần tải lại trang: tab sống trong localStorage nhưng
    // chi tiết đơn thì chưa. Không có nhánh này thì mọi tab hiện mã đơn rồi
    // lần lượt nhảy sang tên bàn khi người dùng bấm vào từng cái.
    expect(
      resolveTabLabel({
        order: undefined,
        fallbackCode: "ORD-2026-3251",
        orderId: "o1",
        tableLabels: new Map([["o1", "A-2"]]),
      }),
    ).toEqual({ kind: "table", text: "A-2" });
  });

  it("ĐƠN thắng FEED khi hai nguồn lệch nhau", () => {
    // `order.tables` là quan hệ chính thống và là thứ giỏ hàng đang vẽ theo;
    // `current_order_id` chỉ là con trỏ ngược. Lệch nhau thì hai màn không được
    // nói khác nhau.
    expect(
      resolveTabLabel({
        order: order({ tables: [{ code: "A-9", name: null }] }),
        fallbackCode: "ORD-2026-3251",
        orderId: "o1",
        tableLabels: new Map([["o1", "A-2"]]),
      }),
    ).toEqual({ kind: "table", text: "A-9" });
  });

  it("gộp bàn từ ĐƠN cũng sắp y hệt nhánh feed — nhãn không xáo khi mở tab", () => {
    // Hai nguồn khác thứ tự tự nhiên; nếu chỉ một bên sắp thì nhãn đổi chỗ ngay
    // trước mắt thu ngân lúc bấm vào tab.
    expect(
      resolveTabLabel({
        order: order({
          tables: [
            { code: "A-2", name: null },
            { code: "A-1", name: null },
          ],
        }),
        fallbackCode: "x",
        orderId: "o1",
        tableLabels: empty,
      }).text,
    ).toBe("A-1 + A-2");
  });

  it("KHÔNG có bàn → hiện mã đơn, và báo `code` để tô màu riêng", () => {
    expect(
      resolveTabLabel({
        order: order({ order_type: "takeaway", tables: [] }),
        fallbackCode: "ORD-2026-3252",
        orderId: "o2",
        tableLabels: empty,
      }),
    ).toEqual({ kind: "code", text: "ORD-2026-3251" });
  });

  it("đơn chưa cache và không có bàn → dùng nhãn đã lưu của tab", () => {
    expect(
      resolveTabLabel({
        order: undefined,
        fallbackCode: "ORD-2026-3252",
        orderId: "o2",
        tableLabels: empty,
      }),
    ).toEqual({ kind: "code", text: "ORD-2026-3252" });
  });

  it("mã tạm của máy trạm → `pending`, để giao diện thay bằng chỗ chờ", () => {
    // `WS-…` là mã chưa được Cloud cấp; hiện nó thô ra là cho thu ngân đọc một
    // định danh sẽ đổi.
    expect(
      resolveTabLabel({
        order: order({ order_code: "WS-abc123", tables: [] }),
        fallbackCode: "WS-abc123",
        orderId: "o3",
        tableLabels: empty,
      }).kind,
    ).toBe("pending");
  });

  it("không có gì cả → `pending`, không phải chuỗi rỗng", () => {
    expect(
      resolveTabLabel({
        order: undefined,
        fallbackCode: "",
        orderId: "o4",
        tableLabels: empty,
      }),
    ).toEqual({ kind: "pending", text: "" });
  });

  it("bàn thắng cả khi đơn là takeaway — dữ liệu thắng suy đoán theo loại đơn", () => {
    // Không rẽ nhánh theo `order_type`: đơn mang đi mà vẫn được gán bàn (khách
    // ngồi chờ) thì thu ngân cần thấy bàn đó.
    expect(
      resolveTabLabel({
        order: order({ order_type: "takeaway", tables: [{ code: "A-5", name: null }] }),
        fallbackCode: "ORD-2026-3252",
        orderId: "o5",
        tableLabels: empty,
      }),
    ).toEqual({ kind: "table", text: "A-5" });
  });
});
