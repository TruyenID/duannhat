import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { CreateOrderDialog } from "./create-order-dialog";
import type { TableResource } from "../types";

/*
 * #3211 — vào dialog TỪ MỘT BÀN thì không phải chọn bàn lại.
 *
 * Sheet STT 25: "Tạo đơn mới từ bàn bỏ màn hình chọn bàn".
 *
 * Phần khó đã có sẵn từ trước: `tables-overview` truyền `onCreateOrder(tb.id)`
 * và dialog hydrate `tableIds` từ `defaultTableIds`. Thứ còn thiếu chỉ là màn
 * hình — picker vẫn hiện đầy đủ cho một việc đã xong, tức hỏi lại câu vừa được
 * trả lời.
 *
 * GẤP LẠI chứ không ẨN HẲN: ẩn hẳn thì bấm nhầm bàn là không sửa được trong
 * dialog, và luồng GỘP BÀN mất đường thêm bàn thứ hai. Ba bài dưới đây ghim cả
 * hai chiều — gấp đúng lúc, và mở lại được.
 */

beforeAll(() => {
  const proto = window.HTMLElement.prototype as unknown as Record<string, unknown>;
  proto.scrollIntoView = vi.fn();
  proto.hasPointerCapture = vi.fn();
  proto.releasePointerCapture = vi.fn();
  proto.setPointerCapture = vi.fn();
});

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("pos_locale", "en");
});

const TABLES = [
  { id: "t-a1", name: "A-1", code: "A-1", seat_count: 4, status: "free", current_order_id: null, is_active: true, zone: null },
  { id: "t-a2", name: "A-2", code: "A-2", seat_count: 2, status: "free", current_order_id: null, is_active: true, zone: null },
] as unknown as TableResource[];

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

function renderDialog(defaultTableIds?: string[]) {
  render(
    <CreateOrderDialog
      open
      onOpenChange={vi.fn()}
      shopSlug="shop-1"
      tables={TABLES}
      onConfirm={vi.fn()}
      defaultTableIds={defaultTableIds}
    />,
    { wrapper: Wrapper }
  );
}

/** Picker hiện hay không — đo bằng ô bàn CHỌN ĐƯỢC, không bằng tên bàn: tên bàn
 *  đã chọn vẫn hiện ở dòng tóm tắt phía trên kể cả khi picker gấp lại. */
function pickerVisible(): boolean {
  return screen.queryAllByRole("button", { name: /A-2/ }).length > 0;
}

describe("#3211 tạo đơn từ một bàn", () => {
  it("mở TỪ MỘT BÀN ⇒ không hiện màn chọn bàn", () => {
    renderDialog(["t-a1"]);

    expect(pickerVisible()).toBe(false);
    // Tên bàn đã chọn vẫn phải nhìn thấy — gấp picker không được làm người dùng
    // mất dấu bàn nào đang được chọn.
    expect(screen.getAllByText(/A-1/).length).toBeGreaterThan(0);
  });

  it("mở từ nút TẠO ĐƠN CHUNG ⇒ picker vẫn hiện như cũ", () => {
    // Vế ngược. Không có vế này thì một bản vá gấp picker ở MỌI lượt mở vẫn đi
    // qua, và người tạo đơn không từ bàn nào sẽ không còn đường chọn bàn.
    renderDialog(undefined);

    expect(pickerVisible()).toBe(true);
  });

  it("bấm \"Đổi bàn\" mở lại picker — cho lượt bấm nhầm và cho gộp bàn", () => {
    renderDialog(["t-a1"]);

    fireEvent.click(screen.getByRole("button", { name: /change table/i }));

    expect(pickerVisible()).toBe(true);
  });
});
