import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";

import { AppProvider } from "@/providers/app-provider";
import { setActiveCurrency } from "../lib/totals";
import { OrderDetail } from "./order-history-shared";

/**
 * In từ màn LỊCH SỬ — điều kiện hiện nút, và cặp `In gốc` / `In lại` (#2535 A7).
 *
 * Bài test đi qua `OrderDetail` chứ không gọi thẳng `PrintDocActions`, vì thứ dễ
 * hỏng ở đây không phải cái nút mà là ĐIỀU KIỆN quanh nó: một gate viết sai vẫn
 * biên dịch được, vẫn render đẹp, và chỉ sai vào đúng cái đơn thu ngân đang cần.
 *
 * Hai thứ đắt nhất được ghim:
 *
 *  1. Phiếu bếp phải gọi `/kitchen-reprint`, KHÔNG phải `/kitchen-ticket`. Đường
 *     thứ hai là ĐIỀU MÓN — nó đóng delta và bắn `order.kitchen_printed` cho mọi
 *     màn KDS — nên in lại một đơn đã thanh toán bằng nó sẽ ném đơn đó trở lại
 *     màn hình bếp như việc mới. Cả hai đều trả 200, nên gọi nhầm là im lặng.
 *  2. `In gốc` KHÔNG gửi lý do, `In lại` có. Một nút chung buộc phải đoán, và nó
 *     đoán sai đúng ở lớp đơn #2535 đẻ ra: đã thu tiền mà chưa từng ra giấy.
 */
const useOrder = vi.fn();
const useTableOrders = vi.fn();
vi.mock("@/hooks/api/use-orders", () => ({
  useOrder: (...a: unknown[]) => useOrder(...a),
  useTableOrders: (...a: unknown[]) => useTableOrders(...a),
}));

const printPaymentReceipt = vi.fn();
const printKitchenReprint = vi.fn();
const printKitchenTicket = vi.fn();
const printOrderBill = vi.fn();
const printRedInvoice = vi.fn();
const getPrintStatus = vi.fn();
// Biến ngoài chứ không phải hằng: một ca cần `enabled === false` để kiểm dải nút
// biến mất hoàn toàn khi máy chưa ghép workstation.
let printServiceEnabled = true;
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    get enabled() {
      return printServiceEnabled;
    },
    printPaymentReceipt: (...a: unknown[]) => printPaymentReceipt(...a),
    printKitchenReprint: (...a: unknown[]) => printKitchenReprint(...a),
    printKitchenTicket: (...a: unknown[]) => printKitchenTicket(...a),
    printOrderBill: (...a: unknown[]) => printOrderBill(...a),
    printRedInvoice: (...a: unknown[]) => printRedInvoice(...a),
    getPrintStatus: (...a: unknown[]) => getPrintStatus(...a),
  },
}));

/** Tally của workstation. `undefined` cho một kind = workstation không trả lời
 *  về kind đó ⇒ `unknown` ⇒ cặp nút gộp về một nút trung tính.
 *
 *  `untargeted_scope` là phạm vi mà một lượt in KHÔNG NHẮM AI sẽ rơi vào, do
 *  workstation tự quyết (`resolvePrintScope`). Mặc định ở đây là phạm vi cả đơn;
 *  đơn một người trả thì workstation trả về id của chính thanh toán đó — xem
 *  bài test "một người trả" bên dưới. */
const status = (over: Record<string, unknown> = {}) => ({
  printer_roles: {},
  sync: { last_pulled_at: "" },
  untargeted_scope: { payment_id: null },
  ...over,
});
const kindCounts = (count: number, byPayment: Record<string, number> = {}) => ({
  printed: count > 0,
  order_scope: { count },
  by_payment: Object.entries(byPayment).map(([payment_id, c]) => ({ payment_id, count: c })),
});

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("VND");
  useOrder.mockReset();
  useTableOrders.mockReset();
  printPaymentReceipt.mockReset().mockResolvedValue({ status: "ok" });
  printKitchenReprint.mockReset().mockResolvedValue({ status: "ok" });
  printKitchenTicket.mockReset().mockResolvedValue({ status: "ok" });
  printOrderBill.mockReset().mockResolvedValue({ status: "ok" });
  printRedInvoice.mockReset().mockResolvedValue({ status: "ok" });
  // Mặc định: chưa in gì cả, cả hai loại chứng từ đều biết rõ là 0.
  getPrintStatus
    .mockReset()
    .mockResolvedValue(status({ receipt: kindCounts(0), red_invoice: kindCounts(0) }));
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const paidPayment = (over: Record<string, unknown> = {}) => ({
  id: "pay-1",
  payment_code: "PAY-1",
  status: "succeeded",
  amount: 2000,
  paid_at: "2026-07-20T06:00:00Z",
  ...over,
});

const detail = (over: Record<string, unknown> = {}) => ({
  id: "o1",
  order_code: "ORD-2026-4231",
  order_type: "dine_in",
  status: "closed",
  subtotal: 2000,
  total_amount: 2000,
  paid_amount: 2000,
  remaining_amount: "0",
  created_at: "2026-07-20T05:44:00Z",
  opened_at: "2026-07-20T05:00:00Z",
  items: [{ id: "i1", quantity: 1, subtotal: 2000, name: "Cà phê", status: "ordered" }],
  payments: [paidPayment()],
  ...over,
});

function renderDetail(over: Record<string, unknown> = {}, props: { allowReprint?: boolean } = {}) {
  useOrder.mockReturnValue({ data: { data: detail(over) }, isLoading: false });
  return render(
    <OrderDetail
      shopSlug="shop-a"
      orderId="o1"
      onBack={() => {}}
      allowReprint={props.allowReprint ?? true}
      t={(k: string) => k}
      locale="vi"
    />,
    { wrapper: Wrapper },
  );
}

const q = (key: string) => screen.queryByText(key);
const all = (key: string) => screen.queryAllByText(key);
/** Nút thật là `<button>` bọc quanh chữ — `closest` để đọc được `disabled`. */
const btn = (key: string) => q(key)?.closest("button") ?? null;

const RECEIPT_ORIGINAL = "pos.print_doc.receipt.original";
const RECEIPT_REPRINT = "pos.print_doc.receipt.reprint";
const RECEIPT_NEUTRAL = "pos.print_doc.receipt.print";
const RED_ORIGINAL = "pos.print_doc.red_invoice.original";
const RED_REPRINT = "pos.print_doc.red_invoice.reprint";
const RED_NEUTRAL = "pos.print_doc.red_invoice.print";
const KITCHEN = "pos.order_history.reprint_kitchen";
const HOLD = "pos.order_history.reprint_hold";

/* `OrderDetail` nhận `t` là hàm đồng nhất nên nút hiện ra CHÍNH LÀ khoá i18n.
   `RedInvoiceDialog` thì không — nó tự gọi `useTranslation()`, nên bên trong hộp
   là tiếng Việt thật (locale `vi` đặt ở `beforeEach`). Hai lớp chữ khác nhau là
   cố ý: bài test đọc nút theo khoá, đọc nội dung hộp theo chữ thu ngân thấy. */
const RED_DIALOG = '[data-slot="red-invoice-dialog"]';
const RED_WARNING = '[data-slot="red-invoice-reprint-warning"]';
const RED_REASON_INPUT = "#red-invoice-reprint-reason";

const redDialog = () => document.querySelector(RED_DIALOG) as HTMLElement | null;
async function openRedDialog(labelKey: string) {
  await waitFor(() => expect(btn(labelKey)).toBeEnabled());
  fireEvent.click(btn(labelKey)!);
  await waitFor(() => expect(redDialog()).not.toBeNull());
  return redDialog()!;
}
/** Nút In trong hộp. `pos.red_invoice.title` và `pos.red_invoice.print` là CÙNG
 *  một chuỗi tiếng Việt, nên phải lọc theo role — nếu không, tiêu đề hộp cũng
 *  khớp và bài test bấm vào một cái không phải nút. */
const redPrintButton = (dialog: HTMLElement) =>
  within(dialog).getByRole("button", { name: "In phiếu thanh toán" });

describe("in từ màn lịch sử — điều kiện hiện nút", () => {
  it("đơn closed đã thu tiền: có hoá đơn, hoá đơn đỏ, và hai phiếu", async () => {
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeInTheDocument());
    expect(q(RED_ORIGINAL)).toBeInTheDocument();
    expect(q(KITCHEN)).toBeInTheDocument();
    expect(q(HOLD)).toBeInTheDocument();
  });

  // Ruling của chủ quán. Hoá đơn khẳng định việc mua bán ĐÃ KẾT THÚC — mời in nó
  // trên đơn khách còn đang ngồi ăn là đưa khách chứng từ về một việc chưa xảy ra.
  it("đơn chưa đóng: không có hoá đơn/hoá đơn đỏ, nhưng vẫn in lại được hai phiếu", async () => {
    renderDetail({ status: "dining" });
    await waitFor(() => expect(q(KITCHEN)).toBeInTheDocument());
    expect(q(RECEIPT_ORIGINAL)).not.toBeInTheDocument();
    expect(q(RECEIPT_NEUTRAL)).not.toBeInTheDocument();
    expect(q(RED_ORIGINAL)).not.toBeInTheDocument();
    expect(q(HOLD)).toBeInTheDocument();
  });

  it("đơn closed nhưng chưa có thanh toán nào đã vào tiền: không có chứng từ tiền", async () => {
    renderDetail({ payments: [paidPayment({ status: "pending" })] });
    await waitFor(() => expect(q(KITCHEN)).toBeInTheDocument());
    expect(q(RECEIPT_ORIGINAL)).not.toBeInTheDocument();
    expect(q(RED_ORIGINAL)).not.toBeInTheDocument();
  });

  it("mọi món đều đã huỷ: không có nút phiếu bếp / phiếu order", async () => {
    renderDetail({
      items: [{ id: "i1", quantity: 1, subtotal: 2000, name: "Cà phê", status: "voided" }],
    });
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeInTheDocument());
    expect(q(KITCHEN)).not.toBeInTheDocument();
    expect(q(HOLD)).not.toBeInTheDocument();
  });

  // Màn lịch sử theo BÀN dùng chung `OrderDetail` và cố ý không bật in: nó được
  // mở giữa giờ phục vụ cạnh một đơn đang sống, và một cú chạm nhầm ở đó là giấy
  // khách không hề xin.
  it("không bật allowReprint thì không có nút nào, và cũng không probe workstation", () => {
    renderDetail({}, { allowReprint: false });
    expect(q(RECEIPT_ORIGINAL)).not.toBeInTheDocument();
    expect(q(KITCHEN)).not.toBeInTheDocument();
    expect(getPrintStatus).not.toHaveBeenCalled();
  });
});

describe("cặp In gốc / In lại (#2535 A7)", () => {
  it("chưa in bản nào: In gốc bật, In lại khoá", async () => {
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeEnabled());
    expect(btn(RECEIPT_REPRINT)).toBeDisabled();
  });

  it("đã có giấy ngoài kia: In gốc khoá, In lại bật, và nói rõ đã in mấy bản", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(2), red_invoice: kindCounts(0) }),
    );
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeDisabled());
    expect(btn(RECEIPT_REPRINT)).toBeEnabled();
    expect(all("pos.red_invoice.printed_count").length).toBeGreaterThan(0);
  });

  /**
   * REGRESSION — đơn MỘT người trả, đã in một bản, mà "In lại" vẫn xám.
   *
   * `resolvePrintScope` branch ② đặt lượt in không-nhắm-ai của đơn một người trả
   * vào phạm vi CHÍNH THANH TOÁN ĐÓ, không phải `order_scope`. Bản đầu của giao
   * diện này đọc thẳng `order_scope` ⇒ số 0 vĩnh viễn ⇒ "In gốc" sáng mãi và
   * "In lại" không bao giờ bấm được, trên đúng loại đơn phổ biến nhất.
   *
   * Chú ý `order_scope: 0` trong fixture: đó CHÍNH LÀ hình dạng dữ liệu thật, và
   * là thứ làm bản cũ xanh trong khi giao diện hỏng.
   */
  it("đơn một người trả: tally nằm ở phạm vi THANH TOÁN, và In lại phải sáng", async () => {
    getPrintStatus.mockResolvedValue(
      status({
        receipt: kindCounts(0, { "pay-1": 1 }),
        red_invoice: kindCounts(0),
        untargeted_scope: { payment_id: "pay-1" },
      }),
    );
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_REPRINT)).toBeEnabled());
    expect(btn(RECEIPT_ORIGINAL)).toBeDisabled();
  });

  // Workstation bản cũ chưa biết trường `untargeted_scope`. Không được đoán là
  // `order_scope` — đoán như thế chính là dựng lại lỗi ngay trên.
  it("workstation không nói phạm vi: một nút trung tính, không đoán", async () => {
    getPrintStatus.mockResolvedValue({
      printer_roles: {},
      sync: { last_pulled_at: "" },
      receipt: kindCounts(0, { "pay-1": 1 }),
      red_invoice: kindCounts(0),
    });
    renderDetail();
    await waitFor(() => expect(q(RECEIPT_NEUTRAL)).toBeInTheDocument());
    expect(q(RECEIPT_ORIGINAL)).not.toBeInTheDocument();
    expect(btn(RECEIPT_NEUTRAL)).toBeEnabled();
  });

  // Workstation bản cũ, hoặc probe LAN hỏng. `print-copy-state.ts` viết rõ vì
  // sao không được đoán: một câu trả lời sai mà tự tin còn tệ hơn không trả lời.
  it("workstation không trả lời tally: gộp về MỘT nút trung tính, không có cặp", async () => {
    getPrintStatus.mockResolvedValue(status()); // có phạm vi, KHÔNG có tally
    renderDetail();
    await waitFor(() => expect(q(RECEIPT_NEUTRAL)).toBeInTheDocument());
    expect(q(RECEIPT_ORIGINAL)).not.toBeInTheDocument();
    expect(q(RECEIPT_REPRINT)).not.toBeInTheDocument();
    expect(btn(RECEIPT_NEUTRAL)).toBeEnabled();
  });

  it("In gốc KHÔNG gửi lý do — bản gốc không có gì để giải thích", async () => {
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeEnabled());
    fireEvent.click(btn(RECEIPT_ORIGINAL)!);
    await waitFor(() =>
      expect(printPaymentReceipt).toHaveBeenCalledWith({
        orderId: "o1",
        paymentId: undefined,
        reprintReason: undefined,
      }),
    );
  });

  // plan-052 §4 / #1166: không đường in nào được phép TỪ CHỐI in. Ô lý do trống
  // vẫn phải ra giấy — nếu không, thu ngân đứng trước khách chờ bị chặn lại bởi
  // một ô nhập.
  it("In lại hỏi lý do, và lý do TRỐNG vẫn in được", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(1), red_invoice: kindCounts(0) }),
    );
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_REPRINT)).toBeEnabled());
    fireEvent.click(btn(RECEIPT_REPRINT)!);

    await waitFor(() => expect(screen.getByText("pos.reprint_reason.title")).toBeInTheDocument());
    expect(printPaymentReceipt).not.toHaveBeenCalled();

    fireEvent.click(screen.getByText("pos.reprint_reason.confirm").closest("button")!);
    await waitFor(() =>
      expect(printPaymentReceipt).toHaveBeenCalledWith({
        orderId: "o1",
        paymentId: undefined,
        reprintReason: "",
      }),
    );
  });

  it("lý do đã gõ thì đi kèm lượt in", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(1), red_invoice: kindCounts(0) }),
    );
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_REPRINT)).toBeEnabled());
    fireEvent.click(btn(RECEIPT_REPRINT)!);

    await waitFor(() => expect(screen.getByLabelText("pos.reprint_reason.label")).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText("pos.reprint_reason.label"), {
      target: { value: "khách làm mất" },
    });
    fireEvent.click(screen.getByText("pos.reprint_reason.confirm").closest("button")!);

    await waitFor(() =>
      expect(printPaymentReceipt).toHaveBeenCalledWith({
        orderId: "o1",
        paymentId: undefined,
        reprintReason: "khách làm mất",
      }),
    );
  });
});

/**
 * Hoá đơn đỏ — nửa CÒN LẠI của cặp, và nửa chưa ai đo.
 *
 * `printRedInvoice` trước đây được mock mà không assert lần nào: đúng cái lỗ mà
 * bài test `printOrderBill` ngay dưới đã phải vá một lần rồi. Nối nhầm dây ở đây
 * (đưa tally của hoá đơn cho hoá đơn đỏ, hoặc quên cờ hỏi-lý-do) **biên dịch
 * được, render đẹp, và vẫn xanh** — chỉ tờ giấy đưa cho khách là sai.
 */
describe("hoá đơn đỏ — nửa còn lại của cặp (#2535 A7)", () => {
  // Hai chứng từ, hai bộ đếm, hai dấu 「BAN IN #N」 riêng. In hoá đơn không được
  // làm khách mất BẢN GỐC hoá đơn đỏ của chính họ.
  it("đọc tally CỦA NÓ, không mượn số của hoá đơn", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(2), red_invoice: kindCounts(0) }),
    );
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeDisabled());
    expect(btn(RED_ORIGINAL)).toBeEnabled();
    expect(btn(RED_REPRINT)).toBeDisabled();
  });

  it("In gốc: hộp KHÔNG hỏi lý do, và lượt in không mang lý do nào", async () => {
    renderDetail();
    const dialog = await openRedDialog(RED_ORIGINAL);
    expect(dialog.querySelector(RED_REASON_INPUT)).toBeNull();

    fireEvent.click(redPrintButton(dialog));
    await waitFor(() =>
      expect(printRedInvoice).toHaveBeenCalledWith({
        orderId: "o1",
        customerName: "",
        paymentId: undefined,
        reprintReason: undefined,
      }),
    );
  });

  // plan-052 §4 / #1166 — HỎI, không bắt buộc. Ô trống vẫn phải ra giấy.
  it("In lại: hộp hỏi lý do, và ô TRỐNG vẫn in được", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(0), red_invoice: kindCounts(1) }),
    );
    renderDetail();
    const dialog = await openRedDialog(RED_REPRINT);
    expect(dialog.querySelector(RED_REASON_INPUT)).not.toBeNull();

    const print = redPrintButton(dialog);
    expect(print).toBeEnabled();
    fireEvent.click(print);
    await waitFor(() =>
      expect(printRedInvoice).toHaveBeenCalledWith({
        orderId: "o1",
        customerName: "",
        paymentId: undefined,
        reprintReason: "",
      }),
    );
  });

  it("In lại: lý do đã gõ đi kèm lượt in", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(0), red_invoice: kindCounts(1) }),
    );
    renderDetail();
    const dialog = await openRedDialog(RED_REPRINT);
    fireEvent.change(dialog.querySelector(RED_REASON_INPUT)!, {
      target: { value: "khách xin thêm một bản" },
    });
    fireEvent.click(redPrintButton(dialog));
    await waitFor(() =>
      expect(printRedInvoice).toHaveBeenCalledWith({
        orderId: "o1",
        customerName: "",
        paymentId: undefined,
        reprintReason: "khách xin thêm một bản",
      }),
    );
  });

  /**
   * REGRESSION — cặp nút đọc đúng phạm vi, nhưng HỘP THOẠI thì chưa.
   *
   * Đơn một người trả: `resolvePrintScope` branch ② đặt lượt in không-nhắm-ai
   * vào CHÍNH thanh toán đó, nên `order_scope` là 0 vĩnh viễn. Hộp hoá đơn đỏ
   * đọc thẳng `order_scope` ⇒ thu ngân bấm đúng nút "In lại" đang sáng, rồi
   * nhìn một cái hộp KHÔNG nói gì — trong khi tờ sắp ra sẽ mang dấu BAN IN #2.
   */
  it("đơn một người trả: hộp nói rõ đã in mấy bản và tờ sau mang dấu mấy", async () => {
    getPrintStatus.mockResolvedValue(
      status({
        receipt: kindCounts(0),
        red_invoice: kindCounts(0, { "pay-1": 1 }),
        untargeted_scope: { payment_id: "pay-1" },
      }),
    );
    renderDetail();
    const dialog = await openRedDialog(RED_REPRINT);
    await waitFor(() => expect(dialog.querySelector(RED_WARNING)).not.toBeNull());
    expect(dialog.querySelector(RED_WARNING)!.textContent).toContain("#2");
  });

  /**
   * Mặt kia của cùng một luật: workstation KHÔNG nói phạm vi thì hộp phải im.
   *
   * `order_scope` ở đây là 3 — đọc bừa nó ra một câu rất tự tin ("đã in 3 bản")
   * về một phạm vi có thể không phải phạm vi sắp bị tính. Một câu trả lời sai mà
   * tự tin còn tệ hơn không trả lời.
   */
  it("workstation không nói phạm vi: hộp KHÔNG đoán, không cảnh báo gì", async () => {
    getPrintStatus.mockResolvedValue({
      printer_roles: {},
      sync: { last_pulled_at: "" },
      receipt: kindCounts(0),
      red_invoice: kindCounts(3),
    });
    renderDetail();
    const dialog = await openRedDialog(RED_NEUTRAL);
    expect(dialog.querySelector(RED_WARNING)).toBeNull();
  });

  it("bill chia: in cho khách nào thì gửi payment_id của khách đó", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(0), red_invoice: kindCounts(0, { "pay-1": 0, "pay-2": 0 }) }),
    );
    renderDetail({
      payments: [
        paidPayment({ id: "pay-1", amount: 1000 }),
        paidPayment({ id: "pay-2", amount: 1000 }),
      ],
    });
    await waitFor(() => expect(all(RED_ORIGINAL)).toHaveLength(3));
    fireEvent.click(all(RED_ORIGINAL)[2]!.closest("button")!);
    await waitFor(() => expect(redDialog()).not.toBeNull());
    fireEvent.click(redPrintButton(redDialog()!));
    await waitFor(() =>
      expect(printRedInvoice).toHaveBeenCalledWith({
        orderId: "o1",
        customerName: "",
        paymentId: "pay-2",
        reprintReason: undefined,
      }),
    );
  });
});

describe("bill chia — bộ đếm theo TỪNG khách", () => {
  // `resolvePrintScope` đếm theo payer. Khách #1 đã có giấy không được làm khách
  // #2 mất bản GỐC của mình.
  it("khách đã có giấy thì In lại; khách chưa có vẫn còn In gốc", async () => {
    getPrintStatus.mockResolvedValue(
      status({
        receipt: kindCounts(2, { "pay-1": 1, "pay-2": 0 }),
        red_invoice: kindCounts(0),
      }),
    );
    renderDetail({
      payments: [
        paidPayment({ id: "pay-1", amount: 1000 }),
        paidPayment({ id: "pay-2", amount: 1000 }),
      ],
    });

    // 1 cặp ở dải trên (phạm vi cả đơn) + 1 cặp cho mỗi khách = 3.
    await waitFor(() => expect(all(RECEIPT_ORIGINAL)).toHaveLength(3));
    const originals = all(RECEIPT_ORIGINAL).map((n) => n.closest("button")!);
    // [0] = cả đơn (count 2 → khoá) · [1] = pay-1 (count 1 → khoá) · [2] = pay-2 (count 0 → bật)
    expect(originals[1]).toBeDisabled();
    expect(originals[2]).toBeEnabled();
  });

  it("in cho một khách thì gửi đúng payment_id của khách đó", async () => {
    getPrintStatus.mockResolvedValue(
      status({ receipt: kindCounts(0, { "pay-1": 0, "pay-2": 0 }), red_invoice: kindCounts(0) }),
    );
    renderDetail({
      payments: [
        paidPayment({ id: "pay-1", amount: 1000 }),
        paidPayment({ id: "pay-2", amount: 1000 }),
      ],
    });

    await waitFor(() => expect(all(RECEIPT_ORIGINAL)).toHaveLength(3));
    fireEvent.click(all(RECEIPT_ORIGINAL)[2]!.closest("button")!);
    await waitFor(() =>
      expect(printPaymentReceipt).toHaveBeenCalledWith({
        orderId: "o1",
        paymentId: "pay-2",
        reprintReason: undefined,
      }),
    );
  });

  it("một thanh toán duy nhất: không nhân đôi cặp nút xuống dòng thanh toán", async () => {
    renderDetail();
    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeInTheDocument());
    expect(all(RECEIPT_ORIGINAL)).toHaveLength(1);
  });
});

describe("phiếu bếp / phiếu order — không phải chứng từ tiền", () => {
  it("phiếu bếp gọi /kitchen-reprint, KHÔNG BAO GIỜ gọi đường điều món", async () => {
    renderDetail();
    await waitFor(() => expect(q(KITCHEN)).toBeInTheDocument());
    fireEvent.click(q(KITCHEN)!);
    await waitFor(() => expect(printKitchenReprint).toHaveBeenCalledWith({ orderId: "o1" }));
    expect(printKitchenTicket).not.toHaveBeenCalled();
  });

  // Hai tờ này không đi qua `printjob.Reserve`, không mang số bản, không có dấu
  // 「BAN IN #N」. Không có bộ đếm thì "gốc" và "bản sao" không phải hai thứ khác
  // nhau, nên dựng cặp nút ở đây sẽ hứa một sự phân biệt tờ giấy không hề có.
  it("phiếu order (hold) gọi printOrderBill, KHÔNG gọi /kitchen-reprint", async () => {
    // Lỗ trong bản đầu: `printOrderBill` được mock nhưng không assert lần nào,
    // nên đảo nhánh `hold` sang `printKitchenReprint` thì MỌI test vẫn xanh —
    // đúng loại nhầm im lặng mà test `kitchen` ngay trên được viết ra để chặn,
    // chỉ là mới chặn có một nửa. Hai tờ này ra hai máy in khác nhau.
    renderDetail();
    await waitFor(() => expect(q(HOLD)).toBeInTheDocument());
    printKitchenReprint.mockClear();
    fireEvent.click(q(HOLD)!);
    await waitFor(() => expect(printOrderBill).toHaveBeenCalledWith({ orderId: "o1" }));
    expect(printKitchenReprint).not.toHaveBeenCalled();
  });

  it("không có cặp gốc/in lại — chỉ một nút mỗi phiếu", async () => {
    renderDetail();
    await waitFor(() => expect(q(KITCHEN)).toBeInTheDocument());
    expect(all(KITCHEN)).toHaveLength(1);
    expect(all(HOLD)).toHaveLength(1);
  });
});

describe("nguồn thanh toán và trạng thái ghép máy", () => {
  it("thanh toán `confirmed` (nguồn máy trạm) vẫn tính là đã thu", async () => {
    // `isSettledPayment` nhận cả `succeeded` lẫn `confirmed`. Fixture cũ chỉ có
    // `succeeded`, nên bỏ vế `confirmed` KHÔNG làm đỏ test nào — trong khi
    // comment ngay trên nó nói bỏ một chữ là nút biến mất trên nửa số đơn, tuỳ
    // máy nối Cloud hay máy trạm.
    renderDetail({
      payments: [
        { id: "p1", status: "confirmed", amount: 1000, payment_method: { name: "Tiền mặt" } },
      ],
    });

    await waitFor(() => expect(btn(RECEIPT_ORIGINAL)).toBeInTheDocument());
  });

  it("chưa ghép máy trạm thì KHÔNG render dải nút nào, kể cả đường kẻ và nhãn", async () => {
    // Con tự `return null` khi `enabled === false`, nhưng dải bao ngoài trước
    // đây không kiểm — nên máy chưa ghép thấy một đường kẻ trên và ba nhãn rỗng.
    // Trái đúng câu "nút ẩn hoàn toàn khi chưa ghép" mà tính năng tự đặt ra.
    printServiceEnabled = false;
    try {
      const { container } = renderDetail();
      await waitFor(() =>
        expect(container.querySelector('[data-slot="order-reprint-bar"]')).toBeNull(),
      );
      expect(q(KITCHEN)).not.toBeInTheDocument();
    } finally {
      printServiceEnabled = true;
    }
  });
});

/**
 * #3040 — đơn trả ONLINE phải hiện nút in, và phải hiện NGAY trên màn bán hàng.
 *
 * Đây là lớp đơn làm quán kêu: khách trả bằng QR/thẻ ở Cloud, đơn sync-down về
 * `closed`, và tờ giấy duy nhất tự in là phiếu 「ĐÃ THANH TOÁN BÀN X」 cho người
 * dọn bàn — không phải biên lai của khách.
 *
 * Hình dạng dữ liệu của nó KHÁC đơn thu tại quầy ở đúng chỗ dễ làm gate sai:
 * máy trạm **không có dòng `payments` cục bộ** cho đơn trả online (dòng cục bộ
 * sẽ làm dịch chuyển tiền ngăn kéo, nên cố ý không có). Thứ tới pos-web là bản
 * đã trộn từ `orders.cloud_payment_summary` qua `mergeCloudPaymentEntriesForHistory`,
 * mang nguyên `status` của Cloud.
 *
 * Nên bài này dựng đúng hình dạng đó — payment KHÔNG có `payment_method`, chỉ có
 * nhãn Cloud gửi kèm — và đòi nút in phải hiện. Một gate viết dựa trên sự tồn
 * tại của phương thức thanh toán cục bộ sẽ xanh với đơn thu tại quầy và ĐỎ ở
 * đây, đúng lớp đơn duy nhất cần nó.
 */
describe("#3040 đơn trả online", () => {
  const cloudPayment = () => ({
    id: "cloud-p1",
    amount: 2000,
    status: "succeeded",
    payment_method_name: "Thẻ (online)",
    created_at: "2026-07-20T05:40:00Z",
  });

  it("hiện nút in biên lai dù KHÔNG có dòng payment cục bộ", () => {
    renderDetail({ payments: [cloudPayment()] });

    expect(btn(RECEIPT_ORIGINAL) ?? btn(RECEIPT_NEUTRAL)).not.toBeNull();
  });

  it("đơn online CHƯA đóng thì vẫn không có nút — rào cũ không được nới", () => {
    // Vế này là thứ giữ cho việc bật cờ trên màn bán hàng vẫn an toàn: đơn đang
    // sống cạnh đó không bao giờ mời in. Gỡ điều kiện `closed` thì bài này đỏ.
    renderDetail({ status: "dining", payments: [cloudPayment()] });

    expect(btn(RECEIPT_ORIGINAL)).toBeNull();
    expect(btn(RECEIPT_NEUTRAL)).toBeNull();
    expect(btn(RECEIPT_REPRINT)).toBeNull();
  });
});
