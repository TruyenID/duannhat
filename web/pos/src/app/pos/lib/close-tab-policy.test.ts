import { describe, expect, it } from "vitest";
import { decideCloseTab } from "./close-tab-policy";
import type { CustomerOrder, CustomerOrderType, TableSummary } from "../types";

function order(
  patch: { order_type?: CustomerOrderType; tables?: TableSummary[] } = {},
): CustomerOrder {
  return {
    id: "ord-1",
    order_code: "ORD-0001",
    order_type: patch.order_type ?? "dine_in",
    status: "open",
    subtotal: 0,
    discount_amount: 0,
    service_charge: 0,
    tax_amount: 0,
    total: 0,
    ...(patch.tables ? { tables: patch.tables } : {}),
  } as CustomerOrder;
}

const TABLE: TableSummary = {
  id: "tbl-1",
  code: "A1",
  name: "A1",
  status: "occupied",
  qr_token: "qr-1",
};

describe("decideCloseTab — ✕ trên tab không đụng tới đơn", () => {
  it("đơn CÓ bàn: đóng thẳng, không hỏi", () => {
    // Mở lại được: chạm vào bàn đang phục vụ ở màn Tổng quan.
    expect(decideCloseTab(order({ tables: [TABLE] }))).toBe("close");
  });

  it("đơn takeaway: đóng thẳng, không hỏi", () => {
    // Mở lại được: ngăn kéo "Đơn takeaway" (feed lọc order_type=takeaway).
    expect(decideCloseTab(order({ order_type: "takeaway" }))).toBe("close");
  });

  it("takeaway thì KHÔNG cần bàn để được đóng thẳng", () => {
    // Bản đầu của policy này có thể xanh nếu chỉ kiểm `tables` — takeaway
    // không bao giờ có bàn, nên ca trên phải đứng riêng, không kèm bàn.
    expect(decideCloseTab(order({ order_type: "takeaway", tables: [] }))).toBe("close");
  });

  it("đơn Nhanh (spot) — MẶC ĐỊNH của hộp thoại tạo đơn — thì phải hỏi lại", () => {
    // Không bàn ⇒ vắng mặt trên lưới bàn; không takeaway ⇒ vắng mặt ở ngăn
    // kéo. Đóng tab xong là không còn lối nào mở lại từ POS. Đây không phải
    // ca hiếm: `create-order-dialog` khởi tạo orderType = "spot".
    expect(decideCloseTab(order({ order_type: "spot" }))).toBe("warn_unreachable");
  });

  it("dine-in chưa gán bàn thì phải hỏi lại", () => {
    expect(decideCloseTab(order({ order_type: "dine_in", tables: [] }))).toBe(
      "warn_unreachable",
    );
  });

  it("`tables` vắng mặt (whenLoaded) được coi như CHƯA gán bàn", () => {
    // Sai theo hướng cảnh báo thừa, không phải bỏ sót: một lần hỏi lại thừa
    // tốn một cú bấm, một lần bỏ sót tốn một đơn không ai tìm lại được.
    expect(decideCloseTab(order({ order_type: "dine_in" }))).toBe("warn_unreachable");
  });

  it("không biết đơn: HỎI, không đóng thẳng", () => {
    // Đảo lại so với bản đầu (#2528 review). Cửa sổ để nó xảy ra là thật: dải
    // tab persist qua localStorage nên hiện ngay sau khi tải lại trang, còn
    // `useOpenOrders` — nguồn duy nhất tra được cho tab không hoạt động — thì
    // chưa về. Bấm ✕ trong khoảng đó sẽ tạo đúng cái đơn mồ côi module này
    // sinh ra để chặn, và đơn `open` mồ côi còn chặn xoá SKU ở admin.
    expect(decideCloseTab(undefined)).toBe("warn_unreachable");
  });

  it("đơn Nhanh đã gán bàn thì vẫn đóng thẳng — cảnh báo không được lan", () => {
    // Rào cho hướng ngược lại: lật `!order` sang cảnh báo mà lỡ tay làm mọi
    // đường đều cảnh báo thì thu ngân phải bấm xác nhận cho từng tab, và một
    // hộp thoại luôn hiện là hộp thoại không ai đọc.
    expect(
      decideCloseTab({
        id: "o1",
        order_type: "dine_in",
        tables: [{ id: "t1" }],
      } as never),
    ).toBe("close");
  });
});
