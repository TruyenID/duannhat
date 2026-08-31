/**
 * Thu MỘT hàng chia bill bằng máy 釣銭機 (#2946).
 *
 * Bài quan trọng nhất của cả file là `onCreatePayment` **không được gọi lần
 * nào** trong luồng máy. `web/pos/CLAUDE.md` §釣銭機 nói thẳng: khi máy báo
 * `finish`, máy trạm ĐÃ chèn payment, đã chạy lifecycle và đã xếp hàng sync UP
 * — nó là người ghi duy nhất. Một POST thứ hai ở đây là **thu tiền khách hai
 * lần**, và tiền mặt đã vào máy thì không rollback được.
 *
 * Tab này vốn có ĐÚNG MỘT đường hoàn tất hàng (`onCreatePayment`), nên nút mới
 * phải rẽ nhánh hoàn toàn. Đó là kiểu hỏng không tự lộ ra: bấm nút vẫn thấy
 * hàng chuyển xanh, chỉ có khách là mất tiền hai lần.
 *
 * Nửa "PHẢI IM" ở đây dài hơn nửa "PHẢI CHẠY", cùng lý do như mọi rào tiền
 * khác trong repo: một nút hiện sai chỗ trên màn thu tiền đắt hơn một nút thiếu.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { SplitBillEvenTab } from "./split-bill-even-tab";
import { formatCurrency } from "../lib/totals";
import type { CustomerOrder, CustomerOrderItem, PaymentMethod } from "../types";

beforeAll(() => {
  const proto = window.HTMLElement.prototype as unknown as Record<string, unknown>;
  proto.scrollIntoView = vi.fn();
  proto.hasPointerCapture = vi.fn(() => false);
  proto.setPointerCapture = vi.fn();
  proto.releasePointerCapture = vi.fn();
});

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("pos_locale", "en");
});

const CASH: PaymentMethod = {
  id: "m-cash",
  code: "cash",
  name: "Cash",
  is_auto_confirm: true,
  requires_tendered: true,
  is_active: true,
  sort_order: 0,
  branch_id: null,
  organization_id: "org-1",
  translations: {},
} as unknown as PaymentMethod;

const CARD: PaymentMethod = {
  ...CASH,
  id: "m-card",
  code: "card",
  name: "Card",
  requires_tendered: false,
} as unknown as PaymentMethod;

function makeOrder(): CustomerOrder {
  const item = {
    id: "item-1",
    customer_order_id: "order-1",
    product_sku_id: "sku-1",
    quantity: 1,
    unit_price: 200_000,
    topping_subtotal: 0,
    subtotal: 200_000,
    status: "pending",
    note: null,
    product_sku: { id: "sku-1", name: "X", translations: {} },
  } as unknown as CustomerOrderItem;

  return {
    id: "order-1",
    order_code: "ORD-T-0001",
    order_type: "dine_in",
    status: "checkout",
    items: [item],
    subtotal_amount: 200_000,
    total_amount: 200_000,
    paid_amount: 0,
    remaining_amount: 200_000,
    guest_count: 2,
    payments: [],
  } as unknown as CustomerOrder;
}

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

interface Options {
  onCollectWithMachine?: SplitBillEvenTabPropsSlice["onCollectWithMachine"];
  machineIdle?: boolean;
  methods?: PaymentMethod[];
}

type SplitBillEvenTabPropsSlice = Parameters<typeof SplitBillEvenTab>[0];

function renderTab(opts: Options = {}) {
  const onCreatePayment = vi.fn(() => Promise.resolve({ id: "SHOULD-NEVER-BE-USED" }));

  render(
    <SplitBillEvenTab
      open
      onOpenChange={vi.fn()}
      order={makeOrder()}
      methods={opts.methods ?? [CASH, CARD]}
      methodsLoading={false}
      splitData={{ per_person_amounts: [100_000, 100_000] } as never}
      splitLoading={false}
      splitError={null}
      splitCount={2}
      onChangeSplitCount={vi.fn()}
      onCreatePayment={onCreatePayment as never}
      onRefundPayment={vi.fn(() => Promise.resolve())}
      onCancelSplit={vi.fn()}
      onAllRowsPaid={vi.fn()}
      onCollectWithMachine={opts.onCollectWithMachine}
      machineIdle={opts.machineIdle ?? true}
    />,
    { wrapper: Wrapper }
  );

  return { onCreatePayment };
}

/** Chọn phương thức cho hàng `index` (0-based). */
function pickMethod(index: number, label: string) {
  const triggers = screen.getAllByRole("combobox");
  fireEvent.click(triggers[index]!);
  fireEvent.click(screen.getByRole("option", { name: new RegExp(label, "i") }));
}

/**
 * Số tiền như DOM đã chuẩn hoá.
 *
 * `Intl` chèn khoảng trắng KHÔNG NGẮT trước ký hiệu tiền tệ, còn
 * testing-library gom mọi khoảng trắng về dấu cách thường trước khi so — nên so
 * thẳng với `formatCurrency` sẽ trượt ở đúng một ký tự vô hình.
 */
function money(value: number): string {
  return formatCurrency(value).replace(/\s+/g, " ");
}

function machineButtons(): HTMLElement[] {
  return screen.queryAllByRole("button", { name: /collect via machine/i });
}

// ───────────────────────────────────────────────────────────────────────────
// PHẢI CHẠY
// ───────────────────────────────────────────────────────────────────────────

describe("#2946 thu bằng máy 釣銭機 — đường chạy", () => {
  it("gọi máy với ĐÚNG SỐ của hàng, không phải tổng đơn", async () => {
    // Cả điểm của #2941: chia đều 2 trên đơn 200.000 thì khách đầu tiên bị máy
    // đòi 100.000. Đòi 200.000 là bắt một người trả hộ cả bàn.
    const collect = vi.fn(() => Promise.resolve({ id: "pay-machine-1" }));
    renderTab({ onCollectWithMachine: collect });

    pickMethod(0, "Cash");
    fireEvent.click(machineButtons()[0]!);

    await waitFor(() => expect(collect).toHaveBeenCalledTimes(1));
    expect(collect).toHaveBeenCalledWith(100_000, {
      split_mode: "even",
      bill_index: 0,
      total_bills: 2,
    });
  });

  it("máy ghi sổ xong ⇒ hàng thành ĐÃ TRẢ, mang payment CỦA MÁY TRẠM", async () => {
    const collect = vi.fn(() =>
      Promise.resolve({ id: "pay-machine-1", tendered: 150_000, change: 50_000 })
    );
    renderTab({ onCollectWithMachine: collect });

    pickMethod(0, "Cash");
    fireEvent.click(machineButtons()[0]!);

    // Hàng đã trả hiện huy hiệu trạng thái + tiền đưa/thối do MÁY đếm.
    // "Paid" khớp CHÍNH XÁC huy hiệu của hàng — nhãn tiến độ ở chân tab là
    // "Paid (n/2 people)" nên nó không lọt vào đây.
    expect(await screen.findByText("Paid")).toBeTruthy();
    // Regex vì con số đi kèm ký hiệu tiền tệ, và dấu phân nhóm đổi theo
    // locale của đồng tiền shop đang dùng (1.000 ở vi-VN, 1,000 ở ja-JP).
    // So bằng CHÍNH bộ định dạng mà component dùng: gõ tay "150,000" thì sai
    // ngay khi shop đổi đồng tiền, mà khớp lỏng thì "50.000" là chuỗi con của
    // "150.000" nên trúng nhiều phần tử.
    await waitFor(() => expect(screen.getByText(money(150_000))).toBeTruthy());
    expect(screen.getByText(money(50_000))).toBeTruthy();
  });
});

// ───────────────────────────────────────────────────────────────────────────
// KHÔNG ĐƯỢC THU HAI LẦN — bài quan trọng nhất
// ───────────────────────────────────────────────────────────────────────────

describe("#2946 máy trạm là người ghi duy nhất", () => {
  it("luồng máy KHÔNG gọi onCreatePayment lần nào", async () => {
    const collect = vi.fn(() => Promise.resolve({ id: "pay-machine-1" }));
    const { onCreatePayment } = renderTab({ onCollectWithMachine: collect });

    pickMethod(0, "Cash");
    fireEvent.click(machineButtons()[0]!);

    await waitFor(() => expect(collect).toHaveBeenCalledTimes(1));
    expect(await screen.findByText("Paid")).toBeTruthy();

    // Nếu vế này đỏ thì khách vừa trả tiền hai lần: một lần vào máy, một lần
    // qua POST /payments.
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("hàng đã thu bằng máy KHÓA luôn, không bấm Thu lần nữa được", async () => {
    const collect = vi.fn(() => Promise.resolve({ id: "pay-machine-1" }));
    const { onCreatePayment } = renderTab({ onCollectWithMachine: collect });

    pickMethod(0, "Cash");
    fireEvent.click(machineButtons()[0]!);
    expect(await screen.findByText("Paid")).toBeTruthy();

    // Hàng thành công đổi footer sang refund/print — nút Thu của nó biến mất,
    // và đó CHÍNH LÀ rào chống POST chồng lên khoản máy đã ghi.
    expect(screen.queryAllByRole("button", { name: /^collect$/i })).toHaveLength(1);
    expect(onCreatePayment).not.toHaveBeenCalled();
  });
});

// ───────────────────────────────────────────────────────────────────────────
// PHẢI IM
// ───────────────────────────────────────────────────────────────────────────

describe("#2946 nút phải vắng mặt đúng chỗ", () => {
  it("không có máy ⇒ KHÔNG nút nào, tab chạy y như trước", () => {
    renderTab({ onCollectWithMachine: undefined });
    pickMethod(0, "Cash");

    expect(machineButtons()).toHaveLength(0);
  });

  it("hàng THẺ ⇒ không có nút — máy đếm tiền mặt", () => {
    renderTab({ onCollectWithMachine: vi.fn() });
    pickMethod(0, "Card");

    expect(machineButtons()).toHaveLength(0);
  });

  it("máy đang bận ⇒ nút bị KHOÁ, không để máy trả 409 rồi hiện lỗi", () => {
    // Máy chỉ có MỘT và service giữ mutex suốt lượt thu.
    renderTab({ onCollectWithMachine: vi.fn(), machineIdle: false });
    pickMethod(0, "Cash");

    const [btn] = machineButtons();
    expect(btn).toBeTruthy();
    expect((btn as HTMLButtonElement).disabled).toBe(true);
  });

  it("thu được tiền mà GHI SỔ HỎNG ⇒ hàng KHÔNG thành đã-trả, và nói ra", async () => {
    // Máy trả `finish` cả cho ca này (#2535 B3). Đánh dấu đã trả là nói dối về
    // một khoản chưa có trong sổ — thứ chỉ lộ ra lúc chốt ca, khi không ai còn
    // dựng lại được chuyện gì đã xảy ra.
    const collect = vi.fn(() => Promise.resolve(null));
    const { onCreatePayment } = renderTab({ onCollectWithMachine: collect });

    pickMethod(0, "Cash");
    fireEvent.click(machineButtons()[0]!);

    expect(await screen.findByText(/not recorded/i)).toBeTruthy();
    expect(screen.queryByText("Paid")).toBeNull();
    // Và tuyệt đối không được "chữa cháy" bằng một POST — đó là thu hai lần.
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("máy ném lỗi ⇒ hàng failed, vẫn không POST gì", async () => {
    const collect = vi.fn(() => Promise.reject(new Error("workstation unreachable")));
    const { onCreatePayment } = renderTab({ onCollectWithMachine: collect });

    pickMethod(0, "Cash");
    fireEvent.click(machineButtons()[0]!);

    expect(await screen.findByText(/workstation unreachable/i)).toBeTruthy();
    expect(onCreatePayment).not.toHaveBeenCalled();
  });
});
