/**
 * ShiftClosePage — component tests (#1183, P2).
 *
 * 精算 / 引き継ぎ is the last money gate of a shift. Two settle kinds share one
 * screen and one payload builder, and picking the wrong endpoint silently
 * ends (or fails to end) a chain of shifts — so these tests pin:
 *   - handover hits `tillService.handover`, final close hits `tillService.close`,
 *   - the exact settle payload (counted rows only, tender rows only when they
 *     carry data, `closing_note` / `closing_cash_adjustment` null when blank),
 *   - the variance-reason gate that mirrors the backend's 422, and
 *   - the VARIANCE_REASON_REQUIRED / SHIFT_NOT_OPEN rejection handling.
 *
 * Mocked at the service boundary (`tillService`); the real hooks + real
 * reconciliation math run.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider, focusManager } from "@tanstack/react-query";
import type * as RouterModule from "react-router-dom";
import { MemoryRouter, Route, Routes } from "react-router-dom";

// ── Service boundary ─────────────────────────────────────────────────────────
const till = vi.hoisted(() => ({
  current: vi.fn(),
  denominations: vi.fn(),
  tenderTypes: vi.fn(),
  tenderCategories: vi.fn(),
  paymentTerminals: vi.fn(),
  reconciliation: vi.fn(),
  orderSummary: vi.fn(),
  saveDraft: vi.fn(),
  close: vi.fn(),
  handover: vi.fn(),
}));
vi.mock("@/services/till-service", () => ({ tillService: till }));

const printShiftReport = vi.hoisted(() => vi.fn());
const printChainReport = vi.hoisted(() => vi.fn());
const getPrintStatus = vi.hoisted(() => vi.fn());
// `enabled` phải ĐỔI ĐƯỢC: #3050 lỗ 4 là ca máy trạm CHƯA GHÉP, và một hằng số
// `true` thì không dựng lại được ca đó.
const printSvcState = vi.hoisted(() => ({ enabled: true }));
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    get enabled() {
      return printSvcState.enabled;
    },
    printShiftReport,
    printChainReport,
    getPrintStatus,
  },
}));

vi.mock("@/app/pos/components/pos-header", () => ({
  PosHeader: () => <div data-testid="pos-header" />,
}));

vi.mock("@/providers/use-auth", () => ({
  useAuth: () => ({ device: { name: "レジ1", branch_name: "人形町店" } }),
}));

vi.mock("sonner", () => ({
  toast: Object.assign(() => {}, {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
  }),
}));

const navigateMock = vi.hoisted(() => vi.fn());
vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof RouterModule>();
  return { ...actual, useNavigate: () => navigateMock };
});

import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { AppProvider, getT } from "@/providers/app-provider";
import { markApiOutcome, resetNetworkStatus } from "@/lib/network-status";
import type {
  CurrentTill,
  Denomination,
  ReconciliationData,
  TillSession,
  TillTenderType,
} from "@/services/till-service";
import { ShiftClosePage } from "./close-page";
import { formatAmount, getCurrencyConfig } from "./currency";

const SHOP = "ningyocho";
const t = getT();

// ── Fixtures ─────────────────────────────────────────────────────────────────

const DENOMS: Denomination[] = [
  { id: "d-10000", currency_code: "JPY", value: 10000, kind: "note", label: null, sort_order: 0 },
  { id: "d-1000", currency_code: "JPY", value: 1000, kind: "note", label: null, sort_order: 1 },
  { id: "d-100", currency_code: "JPY", value: 100, kind: "coin", label: null, sort_order: 2 },
];

const TENDER_TYPES: TillTenderType[] = [
  {
    id: "tt-cash",
    tender_key: "cash",
    name: { ja: "現金", vi: "Tiền mặt" },
    category: "cash",
    parent_tender_key: null,
    currency_code: "JPY",
    payment_method_code: "cash",
    is_expected_anchor: true,
    requires_terminal_total: false,
    sort_order: 0,
  },
  {
    id: "tt-credit",
    tender_key: "credit",
    name: { ja: "クレジット", vi: "Thẻ" },
    category: "card",
    parent_tender_key: null,
    currency_code: "JPY",
    payment_method_code: "card",
    is_expected_anchor: true,
    requires_terminal_total: true,
    sort_order: 1,
  },
];

const CATEGORIES = [
  { id: "c-cash", key: "cash", name: "現金", sort_order: 0, is_system: true },
  { id: "c-card", key: "card", name: "クレジット", sort_order: 1, is_system: true },
];

function session(over: Partial<TillSession> = {}): TillSession {
  return {
    id: "sess-9",
    session_code: "S-0009",
    status: "open",
    business_date: "2026-07-28",
    default_currency_code: "JPY",
    opening_float_amount: 30000,
    expected_cash_amount: null,
    counted_cash_amount: null,
    cash_variance_amount: null,
    closing_cash_adjustment_amount: null,
    opening_note: null,
    closing_note: null,
    opened_by_id: null,
    closed_by_id: null,
    opener_name: "田中 太郎",
    opened_at: "2026-07-28T09:00:00Z",
    closed_at: null,
    abandoned_at: null,
    abandon_reason: null,
    till_id: "till-1",
    branch_id: "branch-1",
    chain_id: "chain-1",
    chain_sequence: 2,
    settlement_kind: null,
    ...over,
  };
}

function currentTill(over: Partial<TillSession> = {}): { data: CurrentTill } {
  return {
    data: {
      till: {
        id: "till-1",
        till_code: "T-01",
        default_currency_code: "JPY",
        variance_tolerance_amount: 100,
        current_session_id: "sess-9",
      },
      open_session: session(over),
    },
  };
}

/**
 * Expected cash 31,000 = 30,000 float + 1,000 cash sales. Card expectation
 * defaults to 0 so a shift with no card sales settles without any declaration
 * (`cardExpected` raises it for the variance cases).
 */
function reconciliation(cardExpected = 0): { data: ReconciliationData } {
  return {
    data: {
      revenue: {
        gross: 1000 + cardExpected,
        net: 46364,
        tax: 4636,
        discount: 0,
        currency_code: "JPY",
      },
      cash: {
        opening_float: 30000,
        cash_sales: 1000,
        paid_in: 0,
        paid_out: 0,
        expected_cash: 31000,
      },
      tenders: [
        { tender_key: "cash", category: "cash", parent: null, expected_amount: 1000 },
        {
          tender_key: "credit",
          category: "card",
          parent: null,
          expected_amount: cardExpected,
        },
      ],
      category_expected: {
        cash: 1000,
        card: cardExpected,
      } as ReconciliationData["category_expected"],
    },
  };
}

// ── Harness ──────────────────────────────────────────────────────────────────

function Wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return (
    <QueryClientProvider client={client}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={[`/shop/${SHOP}/shift/close`]}>
      <Routes>
        <Route path="/shop/:shopSlug/shift/close" element={<ShiftClosePage />} />
      </Routes>
    </MemoryRouter>,
    { wrapper: Wrapper },
  );
}

async function waitForCounter() {
  await screen.findByLabelText(
    t("shift.open.count.qty_label", { denom: "¥10,000" }),
  );
}

function countDenom(denom: string, qty: number) {
  fireEvent.change(
    screen.getByLabelText(t("shift.open.count.qty_label", { denom })),
    { target: { value: String(qty) } },
  );
}

/**
 * #3050 lỗ 3 — mọi cảnh báo in hỏng phải mang NÚT dẫn tới lịch sử ca.
 *
 * Việc in chạy nền sau khi đã điều hướng, nên cảnh báo hiện trên màn khác. Một
 * dòng chữ thoáng qua nói vừa mất một chứng từ, giữa lúc kết ca, mà không có
 * chỗ bấm — đó chính là hiện trường 本郷店 21:50.
 *
 * Ghim cả `duration`: một toast có nút mà biến mất sau 4 giây thì cái nút đó
 * chỉ để trang trí.
 */
function withReprintAction() {
  return expect.objectContaining({
    duration: expect.any(Number),
    action: expect.objectContaining({
      label: t("shift.close.print.open_history"),
      onClick: expect.any(Function),
    }),
  });
}

function declareTender(name: string, gross: string, cancel = "") {
  fireEvent.change(
    screen.getByLabelText(t("shift.close.reconcile.aria_gross", { name })),
    { target: { value: gross } },
  );
  if (cancel) {
    fireEvent.change(
      screen.getByLabelText(t("shift.close.reconcile.aria_cancel", { name })),
      { target: { value: cancel } },
    );
  }
}

function handoverButton(): HTMLButtonElement {
  return screen.getByRole("button", {
    name: t("shift.handover.button"),
  }) as HTMLButtonElement;
}

function finalCloseButton(): HTMLButtonElement {
  return screen.getByRole("button", {
    name: t("shift.close.final.button"),
  }) as HTMLButtonElement;
}

/** Count the drawer to EXACTLY the expected cash (31,000) → zero variance. */
async function countExpectedCash() {
  await waitForCounter();
  countDenom("¥10,000", 3);
  countDenom("¥1,000", 1);
}

function settlePayload(mock: typeof till.close): Record<string, unknown> {
  return mock.mock.calls[0][1] as Record<string, unknown>;
}

beforeAll(() => {
  Element.prototype.scrollIntoView = () => {};
  Element.prototype.hasPointerCapture = () => false;
  Element.prototype.setPointerCapture = () => {};
  Element.prototype.releasePointerCapture = () => {};
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
});

beforeEach(() => {
  vi.clearAllMocks();
  printSvcState.enabled = true;
  till.current.mockResolvedValue(currentTill());
  till.denominations.mockResolvedValue({ data: DENOMS });
  till.tenderTypes.mockResolvedValue({ data: TENDER_TYPES });
  till.tenderCategories.mockResolvedValue({ data: CATEGORIES });
  till.paymentTerminals.mockResolvedValue({ data: [] });
  till.reconciliation.mockResolvedValue(reconciliation());
  till.orderSummary.mockResolvedValue({
    data: {
      paid_orders_count: 12,
      paid_orders_total: 51000,
      unpaid_carry_count: 1,
      unpaid_carry_orders: [
        { id: "o-9", order_code: "ORD-509", total_amount: 4200, status: "open" },
      ],
    },
  });
  till.saveDraft.mockResolvedValue({ data: session() });
  till.close.mockResolvedValue({
    data: session({ status: "settled", settlement_kind: "final" }),
  });
  till.handover.mockResolvedValue({
    data: session({ status: "settled", settlement_kind: "handover" }),
  });
  printShiftReport.mockResolvedValue({ status: "ok" });
  printChainReport.mockResolvedValue({ status: "ok" });
  // Mặc định: máy in hoá đơn khoẻ, nên cảnh báo #3048 im.
  getPrintStatus.mockResolvedValue({
    printer_roles: { receipt_printer: { configured: true, online: true } },
    sync: { last_pulled_at: "2026-08-16T12:00:00Z" },
  });
});

// ── Render / guards ──────────────────────────────────────────────────────────

describe("ShiftClosePage — render + session guard", () => {
  it("renders the session card and both settle actions", async () => {
    renderPage();
    await waitForCounter();

    expect(screen.getByText("S-0009")).toBeInTheDocument();
    // plan-046 chain badge — this is shift 2 of the chain.
    expect(screen.getByText(t("shift.badge.chain", { seq: "2" }))).toBeInTheDocument();
    expect(handoverButton()).toBeInTheDocument();
    expect(finalCloseButton()).toBeInTheDocument();
  });

  it("refuses to render (and bounces to レジ開け) when the shift is no longer open", async () => {
    // plan-032 — the shift was force-abandoned/expired on another terminal.
    till.current.mockResolvedValue({
      data: {
        ...currentTill().data,
        open_session: session({ status: "abandoned" }),
      },
    });
    renderPage();

    await waitFor(() =>
      expect(toast.info).toHaveBeenCalledWith(t("shift.close.no_session")),
    );
    expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}/shift/open`, {
      replace: true,
    });
    expect(
      screen.queryByRole("button", { name: t("shift.close.final.button") }),
    ).not.toBeInTheDocument();
  });

  it("keeps both settle actions closed until the drawer is counted", async () => {
    renderPage();
    await waitForCounter();

    expect(handoverButton()).toBeDisabled();
    expect(finalCloseButton()).toBeDisabled();

    countDenom("¥10,000", 3);
    countDenom("¥1,000", 1);
    expect(handoverButton()).toBeEnabled();
    expect(finalCloseButton()).toBeEnabled();
  });
});

// ── Settle kind routing (plan-046) ───────────────────────────────────────────

describe("ShiftClosePage — handover vs final close (plan-046)", () => {
  it("引き継ぎ confirms with the handover copy and calls ONLY tillService.handover", async () => {
    renderPage();
    await countExpectedCash();

    fireEvent.click(handoverButton());
    expect(
      await screen.findByText(t("shift.handover.confirm.title")),
    ).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: t("shift.handover.action.confirm") }),
    );

    await waitFor(() => expect(till.handover).toHaveBeenCalledTimes(1));
    expect(till.close).not.toHaveBeenCalled();
    expect(till.handover.mock.calls[0][0]).toBe("sess-9");
    expect(toast.success).toHaveBeenCalledWith(
      t("shift.handover.success.settled"),
    );
    // The next cashier must land on レジ開け.
    expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}/shift/open`, {
      replace: true,
    });
    // A handover prints the 引き継ぎ slip and NEVER the aggregate chain slip.
    await waitFor(() =>
      expect(printShiftReport).toHaveBeenCalledWith({
        shopSlug: SHOP,
        sessionId: "sess-9",
        reportKind: "handover",
      }),
    );
    expect(printChainReport).not.toHaveBeenCalled();
  });

  it("精算（最終） confirms with the chain copy and calls ONLY tillService.close", async () => {
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    expect(
      await screen.findByText(t("shift.close.final.confirm.title")),
    ).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
    expect(till.handover).not.toHaveBeenCalled();
    expect(toast.success).toHaveBeenCalledWith(
      t("shift.close.final.success.settled"),
    );
    // Final close prints the shift slip AND the aggregate chain slip.
    await waitFor(() =>
      expect(printShiftReport).toHaveBeenCalledWith({
        shopSlug: SHOP,
        sessionId: "sess-9",
        reportKind: "settlement",
      }),
    );
    await waitFor(() =>
      expect(printChainReport).toHaveBeenCalledWith({
        shopSlug: SHOP,
        chainId: "chain-1",
      }),
    );
  });

  it("still leaves for レジ開け when the thermal printer fails (print is background)", async () => {
    printShiftReport.mockRejectedValue(new Error("printer offline"));
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() =>
      expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}/shift/open`, {
        replace: true,
      }),
    );
    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        t("shift.close.print.failed"),
        withReprintAction(),
      ),
    );
  });

  // #3050 — phiếu CHUỖI không được chết theo phiếu CA.
  //
  // Bản trước xếp hai lượt in trong MỘT chuỗi `await`: phiếu chuỗi đứng sau
  // `await printShiftReport(...)` trong cùng `try`. Hợp đồng nói ba hàm báo cáo
  // "resolve, không ném" với máy nguội / không máy in / bản cũ 404 — nhưng 5xx
  // THẬT thì vẫn nổi lên, và `rp.Connect()` hỏng ở máy trạm chính là 5xx.
  //
  // Nên một lần kết nối hỏng ở phiếu ca lấy mất luôn tờ tổng hợp cả ngày, và
  // không ai biết nó đã không được thử. Bài này là chỗ duy nhất bắt được điều
  // đó: mọi bài khác đều để `printShiftReport` thành công.
  it("#3050 phiếu ca hỏng thì phiếu CHUỖI vẫn được in", async () => {
    printShiftReport.mockRejectedValue(new Error("printer offline"));
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() =>
      expect(printChainReport).toHaveBeenCalledWith({
        shopSlug: SHOP,
        chainId: "chain-1",
      }),
    );
    // Và người dùng vẫn được báo về tờ bị mất, không nuốt im.
    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        t("shift.close.print.failed"),
        withReprintAction(),
      ),
    );
  });

  // Chiều ngược lại: phiếu chuỗi hỏng không được kéo theo phiếu ca, và phải có
  // cảnh báo RIÊNG — nếu dùng chung một thông điệp thì người đọc không biết
  // tờ nào đã mất.
  it("#3050 phiếu chuỗi hỏng thì phiếu CA vẫn in, và cảnh báo riêng", async () => {
    printChainReport.mockRejectedValue(new Error("printer offline"));
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() =>
      expect(printShiftReport).toHaveBeenCalledWith({
        shopSlug: SHOP,
        sessionId: "sess-9",
        reportKind: "settlement",
      }),
    );
    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        t("shift.close.print.chain_failed"),
        withReprintAction(),
      ),
    );
  });

  it("warns (without blocking) when the workstation reports no printer", async () => {
    printShiftReport.mockResolvedValue({ status: "no_printer" });
    renderPage();
    await countExpectedCash();

    fireEvent.click(handoverButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.handover.action.confirm"),
      }),
    );

    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        t("shift.close.print.no_printer"),
        withReprintAction(),
      ),
    );
    expect(till.handover).toHaveBeenCalledTimes(1);
  });
});

// ── Payload ──────────────────────────────────────────────────────────────────

describe("ShiftClosePage — settle payload", () => {
  it("sends counted rows only, null note, null adjustment, and NO empty tender rows", async () => {
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
    const payload = settlePayload(till.close);
    expect(payload.closing_counts).toEqual([
      { denomination_id: "d-10000", quantity: 3 },
      { denomination_id: "d-1000", quantity: 1 },
    ]);
    // Untouched tenders are omitted entirely — a 0/0 row is NOT the same as
    // "the cashier declared zero".
    expect(payload.tender_details).toEqual([]);
    expect(payload.closing_note).toBeNull();
    expect(payload.closing_cash_adjustment).toBeNull();
  });

  it("declared tenders ride as gross/cancel numbers with a null batch total", async () => {
    // Card expectation matches what the cashier declares → no variance gate.
    till.reconciliation.mockResolvedValue(reconciliation(50000));
    renderPage();
    await countExpectedCash();
    declareTender("クレジット", "50000", "0");

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
    expect(settlePayload(till.close).tender_details).toEqual([
      {
        tender_key: "credit",
        gross_amount: 50000,
        cancel_amount: 0,
        terminal_batch_total: null,
        variance_reason: null,
      },
    ]);
  });

  it("the odd-change adjustment lands as a number and moves counted cash", async () => {
    renderPage();
    await countExpectedCash();

    fireEvent.change(
      screen.getByLabelText(t("shift.close.cash_count.odd_change.label")),
      { target: { value: "50" } },
    );
    fireEvent.change(screen.getByLabelText(/./, { selector: "#closing-note" }), {
      target: { value: "  thừa 50 yên  " },
    });

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
    const payload = settlePayload(till.close);
    expect(payload.closing_cash_adjustment).toBe(50);
    expect(payload.closing_note).toBe("thừa 50 yên");
  });

  it("下書き保存 sends the SAME payload shape without settling anything", async () => {
    renderPage();
    await countExpectedCash();

    fireEvent.click(
      screen.getByRole("button", { name: t("shift.close.action.save_draft") }),
    );

    await waitFor(() => expect(till.saveDraft).toHaveBeenCalledTimes(1));
    expect(till.saveDraft.mock.calls[0][0]).toBe("sess-9");
    expect(settlePayload(till.saveDraft)).toMatchObject({
      closing_counts: [
        { denomination_id: "d-10000", quantity: 3 },
        { denomination_id: "d-1000", quantity: 1 },
      ],
      closing_note: null,
    });
    expect(toast.success).toHaveBeenCalledWith(t("shift.close.success.draft"));
    expect(till.close).not.toHaveBeenCalled();
    expect(till.handover).not.toHaveBeenCalled();
  });
});

// ── Variance gates ───────────────────────────────────────────────────────────

describe("ShiftClosePage — variance reason gate (mirrors the backend 422)", () => {
  it("cash out of tolerance locks BOTH settle actions until the closing note explains it", async () => {
    renderPage();
    await waitForCounter();

    // 30,000 counted vs 31,000 expected → −1,000, tolerance is 100.
    countDenom("¥10,000", 3);
    expect(handoverButton()).toBeDisabled();
    expect(finalCloseButton()).toBeDisabled();

    fireEvent.change(screen.getByLabelText(/./, { selector: "#closing-note" }), {
      target: { value: "khách trả thiếu, đã báo quản lý" },
    });
    expect(finalCloseButton()).toBeEnabled();
  });

  it("a terminal variance demands a section reason, which is stamped onto the tender row", async () => {
    till.reconciliation.mockResolvedValue(reconciliation(50000));
    renderPage();
    await countExpectedCash();

    // Declared 60,000 vs expected 50,000 → +10,000, way past tolerance.
    declareTender("クレジット", "60000");
    await waitFor(() => expect(finalCloseButton()).toBeDisabled());

    const reasonBox = screen.getByPlaceholderText(
      t("shift.close.reconcile.variance_reason_placeholder"),
    );
    fireEvent.change(reasonBox, { target: { value: "  端末の二重打ち  " } });
    await waitFor(() => expect(finalCloseButton()).toBeEnabled());

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
    expect(settlePayload(till.close).tender_details).toEqual([
      {
        tender_key: "credit",
        gross_amount: 60000,
        cancel_amount: 0,
        terminal_batch_total: null,
        variance_reason: "端末の二重打ち",
      },
    ]);
  });
});

// ── #3050 lỗ 4 — chưa ghép máy trạm thì phải NÓI RA ─────────────────────────

describe("ShiftClosePage — chưa ghép máy trạm (#3050)", () => {
  /**
   * Trước bản này cả khối in nằm trong `if (workstationPrintService.enabled)`,
   * nên `enabled === false` là: không in, KHÔNG toast, không gì cả — và ca vẫn
   * đóng. Với quán CÓ máy trạm mà pairing rơi, đó là mất giấy trong im lặng:
   * dạng hỏng đắt nhất, vì không ai biết để đi tìm.
   */
  it("NÓI RA rằng phiếu không được in, thay vì im lặng", async () => {
    printSvcState.enabled = false;
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(toast.warning).toHaveBeenCalledWith(
        t("shift.close.print.no_workstation"),
        expect.objectContaining({ duration: expect.any(Number) }),
      ),
    );
    // Và KHÔNG thử in — không có máy trạm thì không có gì để gọi.
    expect(printShiftReport).not.toHaveBeenCalled();
  });

  it("ca vẫn chốt bình thường — cảnh báo, KHÔNG chặn", async () => {
    printSvcState.enabled = false;
    renderPage();
    await countExpectedCash();

    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );

    // plan-052 §4: mất tờ giấy không được phép lật ngược việc đóng ca.
    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
  });
});

// ── #3049 — tổng ±0 mà vẫn chặn ─────────────────────────────────────────────

describe("ShiftClosePage — lệch bù trừ nhau (#3049)", () => {
  /**
   * 本郷店: màn hình hiện `±0` XANH rồi bắt nhập 「Lý do sai số」. Ba dòng bên
   * dưới là −6.120 / +2.920 / +3.200 — cộng lại đúng 0. Nhân viên đọc huy hiệu
   * xanh to trước, rồi bị chặn mà không hiểu vì sao.
   *
   * Cổng KHÔNG sai: backend gác theo TỪNG phương thức, vì một khoản ghi nhầm
   * phương thức làm đối soát với từng nhà cung cấp sai — tiền không mất, nhưng
   * tiền đứng nhầm chỗ. Sai là ở chỗ MÀU nói ngược lại cái nút đang làm.
   */
  function twoTenderReconciliation() {
    const base = reconciliation(50000);
    base.data.tenders.push({
      tender_key: "paypay",
      category: "qr",
      parent: null,
      expected_amount: 50000,
    });
    base.data.category_expected = {
      ...base.data.category_expected,
      qr: 50000,
    } as ReconciliationData["category_expected"];

    return base;
  }

  beforeEach(() => {
    till.reconciliation.mockResolvedValue(twoTenderReconciliation());
    // Hàng tender chỉ render khi CATEGORY của nó nằm trong `visibleCategories`
    // — thiếu nhóm thì phương thức có mà không ai thấy, và bài này đo rỗng.
    till.tenderCategories.mockResolvedValue({
      data: [
        ...CATEGORIES,
        { id: "c-qr", key: "qr", name: "QR", sort_order: 2, is_system: true },
      ],
    });
    till.tenderTypes.mockResolvedValue({
      data: [
        ...TENDER_TYPES,
        {
          id: "tt-paypay",
          tender_key: "paypay",
          name: { ja: "PayPay", vi: "PayPay" },
          category: "qr",
          parent_tender_key: null,
          currency_code: "JPY",
          payment_method_code: "qr",
          is_expected_anchor: true,
          requires_terminal_total: true,
          sort_order: 2,
        },
      ],
    });
  });

  it("huy hiệu tổng THÔI XANH khi bên dưới còn phương thức lệch bù trừ nhau", async () => {
    renderPage();
    await countExpectedCash();

    // +10.000 ở thẻ, −10.000 ở PayPay ⇒ tổng khối = 0, hai dòng đều ngoài ngưỡng.
    declareTender("クレジット", "60000");
    declareTender("PayPay", "40000");

    // Màn hình có HAI huy hiệu `±0`: tiền mặt (đếm khớp — xanh là ĐÚNG) và khối
    // phương thức (tổng 0 nhưng hai dòng lệch — phải hoá hổ phách). Assert cả
    // hai để bản vá không được phép bôi hổ phách bừa lên mọi thứ.
    // Không ghim SỐ LƯỢNG huy hiệu — bố cục đổi là bài vỡ mà chẳng nói lên gì.
    // Tính chất cần chứng minh có hai vế, và vế thứ hai quan trọng ngang vế đầu:
    //   có ít nhất một `±0` HỔ PHÁCH  → khối lệch thôi nói dối bằng màu
    //   vẫn còn một `±0` XANH         → tiền mặt đếm khớp thật, không bị bôi bừa
    await waitFor(() => {
      const chips = screen.getAllByText("±0 JPY");
      expect(chips.some((c) => c.className.includes("amber"))).toBe(true);
      expect(chips.some((c) => c.className.includes("emerald"))).toBe(true);
    });
  });

  it("NÓI RA rằng tổng khớp nhưng có phương thức lệch — không để nhân viên tự đoán", async () => {
    renderPage();
    await countExpectedCash();

    declareTender("クレジット", "60000");
    declareTender("PayPay", "40000");

    expect(
      await screen.findByText(
        t("shift.close.reconcile.offsetting_variance", { count: "2" }),
      ),
    ).toBeInTheDocument();
  });

  it("vẫn CHẶN cho tới khi có lý do — cổng không được nới, chỉ được giải thích", async () => {
    renderPage();
    await countExpectedCash();

    declareTender("クレジット", "60000");
    declareTender("PayPay", "40000");

    await waitFor(() => expect(finalCloseButton()).toBeDisabled());

    fireEvent.change(
      screen.getByPlaceholderText(
        t("shift.close.reconcile.variance_reason_placeholder"),
      ),
      { target: { value: "PayPay bị bấm nhầm sang thẻ" } },
    );
    await waitFor(() => expect(finalCloseButton()).toBeEnabled());
  });
});

// ── Rejections ───────────────────────────────────────────────────────────────

describe("ShiftClosePage — settle rejections", () => {
  async function countAndClose() {
    await countExpectedCash();
    fireEvent.click(finalCloseButton());
    fireEvent.click(
      await screen.findByRole("button", {
        name: t("shift.close.final.action.confirm"),
      }),
    );
  }

  it("422 VARIANCE_REASON_REQUIRED → the reason toast, no navigation", async () => {
    till.close.mockRejectedValue(
      new ApiError(422, {
        code: "VARIANCE_REASON_REQUIRED",
        message: "A variance reason is required.",
      }),
    );
    renderPage();
    await countAndClose();

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        t("shift.close.error.variance_reason"),
      ),
    );
    expect(navigateMock).not.toHaveBeenCalled();
    expect(printShiftReport).not.toHaveBeenCalled();
  });

  it("409 SHIFT_NOT_OPEN (settled elsewhere) → conflict toast + bounce to レジ開け", async () => {
    till.close.mockRejectedValue(
      new ApiError(409, {
        code: "SHIFT_NOT_OPEN",
        message: "Shift is not open.",
      }),
    );
    renderPage();
    await countAndClose();

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        t("shift.close.error.shift_not_open"),
      ),
    );
    expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}/shift/open`, {
      replace: true,
    });
  });

  it("an uncoded error falls back to the server message and keeps the count", async () => {
    till.close.mockRejectedValue(
      new ApiError(500, { message: "Server Error" }),
    );
    renderPage();
    await countAndClose();

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("Server Error"),
    );
    expect(
      screen.getByLabelText(t("shift.open.count.qty_label", { denom: "¥10,000" })),
    ).toHaveValue("3");
  });

  it("a failed draft save reports the message and never settles", async () => {
    till.saveDraft.mockRejectedValue(new Error("workstation unreachable"));
    renderPage();
    await countExpectedCash();

    fireEvent.click(
      screen.getByRole("button", { name: t("shift.close.action.save_draft") }),
    );

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("workstation unreachable"),
    );
    expect(till.close).not.toHaveBeenCalled();
  });
});

/*
 * #1501 — kết toán ca (bàn giao HOẶC đóng chuỗi) cần Cloud tính lại snapshot
 * uy quyền (plan-046 R7). Mất mạng thì khoá, không xếp hàng.
 */
describe("ShiftClosePage — mất kết nối thì khoá kết toán (#1501)", () => {
  beforeEach(() => {
    resetNetworkStatus();
  });

  it("đếm đủ nhưng offline ⇒ CẢ bàn giao lẫn đóng ca đều bị khoá kèm lý do", async () => {
    markApiOutcome("network-error");
    markApiOutcome("network-error");

    renderPage();
    await countExpectedCash();

    await waitFor(() => expect(finalCloseButton()).toBeDisabled());
    expect(handoverButton()).toBeDisabled();
    expect(finalCloseButton()).toHaveAttribute("title");
    expect(handoverButton()).toHaveAttribute("title");
  });

  it("có mạng thì vẫn kết toán được như cũ", async () => {
    renderPage();
    await countExpectedCash();

    await waitFor(() => expect(finalCloseButton()).toBeEnabled());
    expect(handoverButton()).toBeEnabled();
  });
});

// ── SC-11 (#1986) — a saved draft must come back ────────────────────────────
//
// The draft endpoint always persisted the count; this screen never read it back.
// A cashier who counted the drawer, was called to the floor and reloaded came
// back to a blank sheet — and to a variance measured against a drawer the screen
// believed was empty (−24.990 in the QA run, with the software then demanding a
// reason for it). Saving work nobody reads back is worse than not saving it,
// because the screen tells the cashier it was kept.
describe("khôi phục bản nháp kết ca", () => {
  const draftSession = () => ({
    status: "closing" as const,
    closing_note: "đếm dở, ra sảnh",
    closing_cash_adjustment_amount: 990,
    closing_counts: [
      {
        id: "cc-1",
        denomination_id: "d-1000",
        currency_code: "JPY",
        denomination_value: 1000,
        denomination_kind: "note" as const,
        quantity: 3,
        subtotal_amount: 3000,
      },
    ],
  });

  it("nạp lại số đếm, tiền lẻ, ghi chú VÀ tổng đã đếm", async () => {
    till.current.mockResolvedValue(currentTill(draftSession()));
    renderPage();
    await waitForCounter();

    await waitFor(() =>
      expect(
        screen.getByLabelText(t("shift.open.count.qty_label", { denom: "¥1,000" })),
      ).toHaveValue("3"),
    );

    // The loose change is the field this whole issue is about: it is the one the
    // count sheet cannot express, so losing it silently shifts the variance by
    // exactly its amount.
    expect(screen.getByDisplayValue("990")).toBeInTheDocument();
    expect(screen.getByDisplayValue("đếm dở, ra sảnh")).toBeInTheDocument();

    // THE TOTAL, not just the grid. `denomCash` is separate state fed only by
    // the counter's onChange, which does not fire for a controlled `values` prop
    // arriving on mount — so restoring the quantities without it shows the right
    // numbers in the grid while every figure computed below them is measured
    // against an empty drawer. That is worse than restoring nothing, and the
    // first version of this test could not see the difference.
    expect(
      screen.getAllByText(`${formatAmount(3990, getCurrencyConfig("JPY"))} JPY`).length,
    ).toBeGreaterThan(0);
  });

  it("KHÔNG đè lên cái thu ngân vừa gõ khi máy chủ trả dữ liệu MỚI", async () => {
    // Re-applying the server draft on every refetch would wipe out whatever has
    // been typed since — rarer than the bug being fixed and far more
    // infuriating, because it destroys work while the cashier watches.
    //
    // Two things have to be arranged or this proves nothing, and BOTH were wrong
    // in the first version, which stayed green with the guard deleted:
    //
    //   - the refetch must actually happen. `useTillCurrent` has
    //     `staleTime: 15s`, so a focus event moments after mount is a no-op;
    //     the clock has to move past it.
    //   - the refetch must return DIFFERENT data. TanStack's structural sharing
    //     hands back the previous object when the payload is deeply equal, so
    //     `session` keeps its identity and the effect never re-runs either way.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      till.current.mockResolvedValue(currentTill(draftSession()));
      renderPage();
      await waitForCounter();

      await waitFor(() =>
        expect(
          screen.getByLabelText(t("shift.open.count.qty_label", { denom: "¥1,000" })),
        ).toHaveValue("3"),
      );

      countDenom("¥1,000", 7);

      // Another terminal saved a different draft in the meantime.
      const moved = draftSession();
      moved.closing_counts[0]!.quantity = 5;
      moved.closing_counts[0]!.subtotal_amount = 5000;
      till.current.mockResolvedValue(currentTill(moved));

      await vi.advanceTimersByTimeAsync(20_000);
      // `focusManager`, not `fireEvent.focus(window)` — v5 listens on
      // `visibilitychange`, so the window event fires into the void and the
      // refetch never happens. Measured: the call count stayed at 1.
      focusManager.setFocused(false);
      focusManager.setFocused(true);
      await waitFor(() => expect(till.current.mock.calls.length).toBeGreaterThan(1));

      expect(
        screen.getByLabelText(t("shift.open.count.qty_label", { denom: "¥1,000" })),
      ).toHaveValue("7");
    } finally {
      vi.useRealTimers();
    }
  });

  it("ca đang MỞ thì không nạp, kể cả khi payload có sẵn số đếm", async () => {
    // An `open` shift has no draft. Hydrating one anyway would restore the
    // PREVIOUS shift's leftovers onto a fresh count — and the guard must be
    // tested against a payload that DOES carry counts, otherwise it passes for
    // the wrong reason (there was nothing to hydrate either way). Measured: the
    // first version deleted the status check without going red.
    till.current.mockResolvedValue(
      currentTill({ ...draftSession(), status: "open" }),
    );
    renderPage();
    await waitForCounter();

    expect(
      screen.getByLabelText(t("shift.open.count.qty_label", { denom: "¥1,000" })),
    ).toHaveValue("0");
    expect(screen.queryByDisplayValue("990")).not.toBeInTheDocument();
  });
});

/**
 * #3048 — cảnh báo máy in hoá đơn offline, hỏi TRƯỚC khi chốt ca.
 *
 * 本郷店 16/08: máy `Casher` (role `receipt_printer`) offline từ 20:00 JST, ca
 * chốt lúc 21:50 — offline gần hai tiếng. Phiếu 精算 chỉ đi máy mang role đó và
 * KHÔNG có fallback (#2593), nên nó không có đường nào khác. Thu ngân chỉ biết
 * sau khi ca đã settle, mà lúc đó không lùi được và không có đường in lại.
 *
 * Bốn ca dưới đây, và ca cuối là ca dễ làm sai nhất: một trường VẮNG MẶT không
 * được đọc thành "hỏng".
 */
describe("#3048 cảnh báo máy in hoá đơn", () => {
  const openConfirm = async () => {
    renderPage();
    await countExpectedCash();
    fireEvent.click(finalCloseButton());
    return screen.findByRole("button", {
      name: t("shift.close.final.action.confirm"),
    });
  };

  it("KÊU khi máy in hoá đơn offline", async () => {
    getPrintStatus.mockResolvedValue({
      printer_roles: { receipt_printer: { configured: true, online: false } },
      sync: { last_pulled_at: "2026-08-16T12:00:00Z" },
    });
    await openConfirm();

    expect(
      await screen.findByText(t("shift.close.printer_offline_warning")),
    ).toBeInTheDocument();
  });

  it("KÊU khi chưa cấu hình máy in hoá đơn", async () => {
    getPrintStatus.mockResolvedValue({
      printer_roles: { receipt_printer: { configured: false } },
      sync: { last_pulled_at: "2026-08-16T12:00:00Z" },
    });
    await openConfirm();

    expect(
      await screen.findByText(t("shift.close.printer_offline_warning")),
    ).toBeInTheDocument();
  });

  it("KHÔNG chặn nút chốt ca — cảnh báo, không phải rào", async () => {
    getPrintStatus.mockResolvedValue({
      printer_roles: { receipt_printer: { configured: true, online: false } },
      sync: { last_pulled_at: "2026-08-16T12:00:00Z" },
    });
    const confirm = await openConfirm();
    await screen.findByText(t("shift.close.printer_offline_warning"));

    // Mất khả năng chốt ca tệ hơn mất tờ giấy — plan-052 §4.
    expect(confirm).not.toBeDisabled();
    fireEvent.click(confirm);
    await waitFor(() => expect(till.close).toHaveBeenCalledTimes(1));
  });

  // Hai bài riêng, không gộp: mỗi bài render một lần. Và hệ quả phải GIỐNG
  // nhau — KHÔNG BIẾT ≠ HỎNG. Dựng cảnh báo từ một trường vắng mặt là dạy thu
  // ngân bỏ qua cảnh báo.
  it("IM khi bản máy trạm cũ không có trường `online`", async () => {
    getPrintStatus.mockResolvedValue({
      printer_roles: { receipt_printer: { configured: true } },
      sync: { last_pulled_at: "2026-08-16T12:00:00Z" },
    });
    await openConfirm();

    expect(
      screen.queryByText(t("shift.close.printer_offline_warning")),
    ).not.toBeInTheDocument();
  });

  it("IM khi không với tới được máy trạm", async () => {
    getPrintStatus.mockRejectedValue(new Error("workstation unreachable"));
    await openConfirm();

    expect(
      screen.queryByText(t("shift.close.printer_offline_warning")),
    ).not.toBeInTheDocument();
  });
});
