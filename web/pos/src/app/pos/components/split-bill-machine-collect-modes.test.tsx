/**
 * Thu bằng máy 釣銭機 ở hai tab còn lại: **chia theo món** và **theo số tiền**
 * (#2958). Tab chia đều đã có rào riêng ở `split-bill-machine-collect.test.tsx`.
 *
 * ## Vì sao không dùng lại test của tab chia đều
 *
 * `web/pos/CLAUDE.md`: *"ba tab có ba row-state và ba đường submit riêng,
 * chúng **đã từng lệch nhau**"*. Đo lại lúc làm #2958, chúng vẫn lệch:
 *
 * | | `even` | `by_items` | `by_amount` |
 * |---|---|---|---|
 * | từ vựng trạng thái | `succeeded` | `succeeded` | **`paid`** |
 * | chốt "đã trả hết" | trong hàm submit | **effect + ref** | trong hàm submit |
 *
 * Một rào dựng trên tab này **không nói gì** về tab kia. Nên mỗi tab có bản
 * assert riêng, kể cả khi nội dung nghe giống nhau.
 *
 * ## Bài quan trọng nhất, lặp lại cho từng tab
 *
 * `onCreatePayment` **không được gọi lần nào** trong luồng máy. Máy trạm đã
 * chèn payment rồi — một POST nữa là **thu tiền khách hai lần**, và tiền mặt
 * đã vào máy thì không rollback được.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { SplitBillByItemsTab } from "./split-bill-by-items-tab";
import { SplitBillByAmountTab } from "./split-bill-by-amount-tab";
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

// Fixture chép NGUYÊN VĂN từ `split-bill-cash-tender.test.tsx`. Bản viết lại
// của tôi thiếu `product_sku.product` và dùng `subtotal_amount` thay cho
// `subtotal`, khiến tab chia-theo-món coi mọi người là "No items assigned" —
// hỏng ở khâu dựng dữ liệu, không phải ở thứ đang được đo.
let itemSeq = 0;
function makeItem(over: Partial<CustomerOrderItem> = {}): CustomerOrderItem {
  itemSeq += 1;
  return {
    id: over.id ?? `item-${itemSeq}`,
    customer_order_id: "order-1",
    product_sku_id: `sku-${itemSeq}`,
    quantity: over.quantity ?? 1,
    unit_price: over.unit_price ?? 100_000,
    topping_subtotal: 0,
    subtotal: (over.quantity ?? 1) * (over.unit_price ?? 100_000),
    status: "pending",
    note: null,
    product_sku: {
      id: `sku-${itemSeq}`,
      name: `Món ${itemSeq}`,
      product: { id: `p-${itemSeq}`, name: `Món ${itemSeq}` },
    },
    ...over,
  } as CustomerOrderItem;
}

function makeOrder(
  items: CustomerOrderItem[],
  over: Partial<CustomerOrder> = {},
): CustomerOrder {
  const subtotal = items.reduce(
    (s, i) => s + Number(i.quantity) * Number(i.unit_price),
    0,
  );
  return {
    id: "order-1",
    order_code: "ORD-T-0001",
    order_type: "dine_in",
    status: "checkout",
    subtotal,
    discount_amount: 0,
    service_charge: 0,
    tax_amount: 0,
    total_amount: subtotal,
    paid_amount: 0,
    remaining_amount: String(subtotal),
    guest_count: 2,
    items,
    customer: null,
    customer_id: null,
    tables: [],
    ...over,
  } as unknown as CustomerOrder;
}

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

function machineButtons(): HTMLElement[] {
  return screen.queryAllByRole("button", { name: /collect via machine/i });
}

/** Máy ghi sổ xong, trả về payment của MÁY TRẠM. */
const recorded = () => vi.fn(() => Promise.resolve({ id: "pay-machine-1" }));
/** Máy KHÔNG ghi được vào sổ (hoặc huỷ / tiền kẹt / mất dấu). */
const notRecorded = () => vi.fn(() => Promise.resolve(null));

// ===========================================================================
//  Chia theo món — SplitBillByItemsTab
// ===========================================================================

describe("#2958 chia theo món — thu bằng máy", () => {
  function renderByItems(
    opts: {
      onCollectWithMachine?: ReturnType<typeof vi.fn>;
      machineIdle?: boolean;
      methods?: PaymentMethod[];
    } = {}
  ) {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "SHOULD-NEVER-BE-USED" }));
    const onAllRowsPaid = vi.fn();

    render(
      <SplitBillByItemsTab
        order={makeOrder([makeItem({ id: "a", unit_price: 100_000 })], { guest_count: 1 })}
        taxRate={0}
        serviceRate={0}
        methods={opts.methods ?? [CASH, CARD]}
        methodsLoading={false}
        onCreatePayment={onCreatePayment as never}
        onAllRowsPaid={onAllRowsPaid as never}
        onClose={vi.fn()}
        onCollectWithMachine={opts.onCollectWithMachine as never}
        machineIdle={opts.machineIdle ?? true}
      />,
      { wrapper: Wrapper }
    );
    // Gán món cho người đang active — không có bước này thì mọi người đều
    // "No items assigned", bill rỗng, và cả hai nút đều bị khoá.
    fireEvent.click(itemPaletteCards()[0]!);

    return { onCreatePayment, onAllRowsPaid };
  }

  function itemPaletteCards(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>("li")).filter(
      (li) => !li.hasAttribute("data-slot") && !li.querySelector("li")
    );
  }

  /**
   * Nút máy PHẢI hỏi trong phạm vi thẻ người.
   *
   * Thẻ `person-payment-card` tự nó mang `role="button"` (bấm để chọn người
   * đang active), nên một truy vấn toàn cục theo tên khớp cả THẺ — tên khả
   * truy cập của thẻ gộp toàn bộ chữ bên trong nó. Bấm nhầm vào thẻ chỉ đổi
   * người active và không gọi máy, mà test vẫn "tìm thấy nút".
   */
  function machineButtonIn(card: HTMLElement): HTMLButtonElement {
    return within(card).getByRole("button", {
      name: /collect via machine/i,
    }) as HTMLButtonElement;
  }

  function machineButtonsIn(card: HTMLElement): HTMLElement[] {
    return within(card).queryAllByRole("button", { name: /collect via machine/i });
  }

  function personCards(): HTMLElement[] {
    return Array.from(
      document.querySelectorAll<HTMLElement>('[data-slot="person-payment-card"]')
    );
  }

  /** Radix Select mở bằng Enter dưới jsdom (pointerdown là no-op). */
  function pickMethod(scope: HTMLElement, name: string) {
    const trigger = within(scope).getByRole("combobox");
    trigger.focus();
    fireEvent.keyDown(trigger, { key: "Enter" });
    fireEvent.click(screen.getByRole("option", { name: new RegExp(name) }));
  }

  it("gọi máy với ĐÚNG tổng của người đó", async () => {
    const collect = recorded();
    renderByItems({ onCollectWithMachine: collect });

    pickMethod(personCards()[0]!, "Cash");
    fireEvent.click(machineButtonIn(personCards()[0]!));

    await waitFor(() => expect(collect).toHaveBeenCalledTimes(1));
    expect(collect).toHaveBeenCalledWith(100_000, {
      split_mode: "by_items",
      bill_index: 0,
      total_bills: 1,
      label: "Người 1",
      item_allocations: [{ item_id: "a", units: 1 }],
    });
  });

  it("KHÔNG gọi onCreatePayment lần nào — và vẫn mở màn biên lai ở người CUỐI", async () => {
    // Tab này chốt "đã trả hết" bằng EFFECT, không phải trong hàm submit. Nên
    // một hàng settle ngoài đường submit vẫn phải chạy qua đúng effect đó —
    // nếu không, thu xong cả bàn mà màn biên lai không mở, chính lỗi #2535 B1.
    const collect = recorded();
    const { onCreatePayment, onAllRowsPaid } = renderByItems({ onCollectWithMachine: collect });

    pickMethod(personCards()[0]!, "Cash");
    fireEvent.click(machineButtonIn(personCards()[0]!));

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    // Nếu vế này đỏ thì khách vừa trả tiền hai lần.
    expect(onCreatePayment).not.toHaveBeenCalled();

    const snapshot = onAllRowsPaid.mock.calls[0]![0] as { guests: Array<{ paymentId: string }> };
    // Payment mang id của MÁY TRẠM, không phải của một POST nào từ đây.
    expect(snapshot.guests[0]!.paymentId).toBe("pay-machine-1");
  });

  it("ghi sổ HỎNG ⇒ không thành đã-trả, không mở biên lai, không POST", async () => {
    const { onCreatePayment, onAllRowsPaid } = renderByItems({
      onCollectWithMachine: notRecorded(),
    });

    pickMethod(personCards()[0]!, "Cash");
    fireEvent.click(machineButtonIn(personCards()[0]!));

    expect(await screen.findByText(/not recorded/i)).toBeTruthy();
    expect(onAllRowsPaid).not.toHaveBeenCalled();
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("không máy ⇒ KHÔNG nút nào", () => {
    renderByItems({ onCollectWithMachine: undefined });
    pickMethod(personCards()[0]!, "Cash");

    expect(machineButtonsIn(personCards()[0]!)).toHaveLength(0);
  });

  it("hàng THẺ không có nút máy", () => {
    renderByItems({ onCollectWithMachine: recorded() });
    pickMethod(personCards()[0]!, "Card");

    expect(machineButtonsIn(personCards()[0]!)).toHaveLength(0);
  });

  it("máy đang bận ⇒ nút bị KHOÁ", () => {
    renderByItems({ onCollectWithMachine: recorded(), machineIdle: false });
    pickMethod(personCards()[0]!, "Cash");

    expect(machineButtonIn(personCards()[0]!).disabled).toBe(true);
  });
});

// ===========================================================================
//  Chia theo số tiền — SplitBillByAmountTab
// ===========================================================================

describe("#2958 chia theo số tiền — thu bằng máy", () => {
  function renderByAmount(
    opts: { onCollectWithMachine?: ReturnType<typeof vi.fn>; machineIdle?: boolean } = {}
  ) {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "SHOULD-NEVER-BE-USED" }));
    const onAllRowsPaid = vi.fn();

    render(
      <SplitBillByAmountTab
        order={makeOrder([makeItem({ unit_price: 200_000 })])}
        methods={[CASH, CARD]}
        methodsLoading={false}
        onCreatePayment={onCreatePayment as never}
        onAllRowsPaid={onAllRowsPaid as never}
        onClose={vi.fn()}
        currencyCode="VND"
        onCollectWithMachine={opts.onCollectWithMachine as never}
        machineIdle={opts.machineIdle ?? true}
      />,
      { wrapper: Wrapper }
    );

    // Hai hàng seed sẵn; phải phân bổ cho khớp tổng, vì cả nút Thu lẫn nút máy
    // đều bị khoá khi phân bổ chưa cân.
    const inputs = screen.getAllByRole("spinbutton");
    fireEvent.change(inputs[0]!, { target: { value: "100000" } });
    fireEvent.change(screen.getAllByRole("spinbutton")[1]!, { target: { value: "100000" } });

    return { onCreatePayment, onAllRowsPaid };
  }

  function amountCards(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>('[data-slot="card"]'));
  }

  /** Tab này chọn phương thức bằng nút thường, không phải Radix Select. */
  function pickMethodChip(card: HTMLElement, name: string) {
    fireEvent.click(within(card).getByRole("button", { name }));
  }

  it("gọi máy với ĐÚNG số của hàng đó", async () => {
    const collect = recorded();
    renderByAmount({ onCollectWithMachine: collect });

    pickMethodChip(amountCards()[0]!, "Cash");
    fireEvent.click(within(amountCards()[0]!).getByRole("button", { name: /collect via machine/i }));

    await waitFor(() => expect(collect).toHaveBeenCalledTimes(1));
    expect(collect).toHaveBeenCalledWith(100_000, {
      split_mode: "by_amount",
      bill_index: 0,
      total_bills: 2,
      label: "Person 1",
      amount: 100_000,
    });
  });

  it("KHÔNG gọi onCreatePayment lần nào", async () => {
    const collect = recorded();
    const { onCreatePayment } = renderByAmount({ onCollectWithMachine: collect });

    pickMethodChip(amountCards()[0]!, "Cash");
    fireEvent.click(within(amountCards()[0]!).getByRole("button", { name: /collect via machine/i }));

    await waitFor(() => expect(collect).toHaveBeenCalledTimes(1));
    expect(await screen.findByText(/paid/i)).toBeTruthy();
    // Nếu vế này đỏ thì khách vừa trả tiền hai lần.
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("thu HÀNG CUỐI bằng máy vẫn mở màn biên lai", async () => {
    // Tab này chốt trong hàm submit, nên đường máy phải đi qua CÙNG chỗ chốt —
    // không thì thu xong cả bàn mà không màn nào mở ra.
    const collect = vi
      .fn()
      .mockResolvedValueOnce({ id: "pay-machine-1" })
      .mockResolvedValueOnce({ id: "pay-machine-2" });
    const { onCreatePayment, onAllRowsPaid } = renderByAmount({ onCollectWithMachine: collect });

    for (const idx of [0, 1]) {
      pickMethodChip(amountCards()[idx]!, "Cash");
      fireEvent.click(
        within(amountCards()[idx]!).getByRole("button", { name: /collect via machine/i })
      );
      await waitFor(() => expect(collect).toHaveBeenCalledTimes(idx + 1));
    }

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    expect(onCreatePayment).not.toHaveBeenCalled();

    const snapshot = onAllRowsPaid.mock.calls[0]![0] as { guests: Array<{ paymentId: string }> };
    // Mỗi khách mang payment RIÊNG của mình — màn biên lai khoá theo paymentId.
    expect(snapshot.guests.map((g) => g.paymentId)).toEqual(["pay-machine-1", "pay-machine-2"]);
  });

  it("ghi sổ HỎNG ⇒ không thành đã-trả, không POST", async () => {
    const { onCreatePayment, onAllRowsPaid } = renderByAmount({
      onCollectWithMachine: notRecorded(),
    });

    pickMethodChip(amountCards()[0]!, "Cash");
    fireEvent.click(within(amountCards()[0]!).getByRole("button", { name: /collect via machine/i }));

    expect(await screen.findByText(/not recorded/i)).toBeTruthy();
    expect(onAllRowsPaid).not.toHaveBeenCalled();
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("không máy ⇒ KHÔNG nút nào", () => {
    renderByAmount({ onCollectWithMachine: undefined });
    pickMethodChip(amountCards()[0]!, "Cash");

    expect(machineButtons()).toHaveLength(0);
  });

  it("hàng THẺ không có nút máy", () => {
    renderByAmount({ onCollectWithMachine: recorded() });
    pickMethodChip(amountCards()[0]!, "Card");

    expect(within(amountCards()[0]!).queryByRole("button", { name: /collect via machine/i })).toBeNull();
  });

  it("máy đang bận ⇒ nút bị KHOÁ", () => {
    renderByAmount({ onCollectWithMachine: recorded(), machineIdle: false });
    pickMethodChip(amountCards()[0]!, "Cash");

    const btn = within(amountCards()[0]!).getByRole("button", {
      name: /collect via machine/i,
    }) as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });
});
