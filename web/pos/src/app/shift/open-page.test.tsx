/**
 * ShiftOpenPage — component tests (#1183, P2).
 *
 * レジ開け is where the drawer's opening float is declared, so the assertion
 * that matters is the OPEN payload: which denominations were counted, who is
 * opening (three mutually exclusive shapes), and — plan-044 R2 — whether any
 * gap payment is being claimed into this shift (keys must be ABSENT when
 * nothing is claimed, and the cash ack must gate submit).
 *
 * Mocked at the service boundary (`tillService`); the real hooks + real
 * payload builder run.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type * as RouterModule from "react-router-dom";
import { MemoryRouter, Route, Routes } from "react-router-dom";

// ── Service boundary ─────────────────────────────────────────────────────────
const till = vi.hoisted(() => ({
  current: vi.fn(),
  denominations: vi.fn(),
  open: vi.fn(),
  gapPreview: vi.fn(),
  unresolvedOrders: vi.fn(),
}));
vi.mock("@/services/till-service", () => ({ tillService: till }));

const staffList = vi.hoisted(() => vi.fn());
vi.mock("@/services/staff-service", () => ({
  staffService: { list: staffList },
}));

const settingsGet = vi.hoisted(() => vi.fn());
vi.mock("@/services/shop-order-settings-service", () => ({
  shopOrderSettingsService: { get: settingsGet },
}));

const printShiftOpenReport = vi.hoisted(() => vi.fn());
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: { enabled: true, printShiftOpenReport },
}));

// The header drags in auth/workstation/dropdown machinery irrelevant here.
vi.mock("@/app/pos/components/pos-header", () => ({
  PosHeader: () => <div data-testid="pos-header" />,
}));

vi.mock("@/providers/use-auth", () => ({
  useAuth: () => ({
    device: { name: "レジ1", branch_name: "人形町店" },
  }),
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
  GapPreview,
  TillSession,
} from "@/services/till-service";
import { ShiftOpenPage } from "./open-page";

const SHOP = "ningyocho";
const t = getT();

// ── Fixtures ─────────────────────────────────────────────────────────────────

const DENOMS: Denomination[] = [
  { id: "d-10000", currency_code: "JPY", value: 10000, kind: "note", label: null, sort_order: 0 },
  { id: "d-1000", currency_code: "JPY", value: 1000, kind: "note", label: null, sort_order: 1 },
  { id: "d-100", currency_code: "JPY", value: 100, kind: "coin", label: null, sort_order: 2 },
];

function currentTill(over: Partial<CurrentTill["till"]> = {}): { data: CurrentTill } {
  return {
    data: {
      till: {
        id: "till-1",
        till_code: "T-01",
        default_currency_code: "JPY",
        variance_tolerance_amount: 100,
        current_session_id: null,
        ...over,
      },
      open_session: null,
    },
  };
}

function openedSession(over: Partial<TillSession> = {}): { data: TillSession } {
  return {
    data: {
      id: "sess-9",
      session_code: "S-0009",
      status: "open",
      business_date: "2026-07-28",
      default_currency_code: "JPY",
      opening_float_amount: 31000,
      expected_cash_amount: null,
      counted_cash_amount: null,
      cash_variance_amount: null,
      opening_note: null,
      closing_note: null,
      opened_by_id: null,
      closed_by_id: null,
      opener_name: null,
      opened_at: "2026-07-28T09:00:00Z",
      closed_at: null,
      abandoned_at: null,
      abandon_reason: null,
      till_id: "till-1",
      branch_id: "branch-1",
      chain_id: null,
      chain_sequence: 1,
      settlement_kind: null,
      ...over,
    },
  };
}

function gapPreview(): { data: GapPreview } {
  return {
    data: {
      previous_session: {
        id: "sess-8",
        session_code: "S-0008",
        ended_at: "2026-07-28T08:30:00Z",
      },
      gap_window: {
        from: "2026-07-28T08:30:00Z",
        to: "2026-07-28T09:00:00Z",
      },
      currency_code: "JPY",
      payments: [
        {
          id: "pay-cash",
          order_id: "o-1",
          order_code: "ORD-501",
          amount: 1200,
          method_code: "cash",
          is_cash: true,
          created_at: "2026-07-28T08:40:00Z",
        },
        {
          id: "pay-card",
          order_id: "o-2",
          order_code: "ORD-502",
          amount: 3400,
          method_code: "card",
          method_label: "Card",
          is_cash: false,
          created_at: "2026-07-28T08:50:00Z",
        },
      ],
      totals: { count: 2, cash_amount: 1200, non_cash_amount: 3400 },
    },
  };
}

const EMPTY_GAP: { data: GapPreview } = {
  data: {
    previous_session: null,
    gap_window: { from: null, to: null },
    currency_code: "JPY",
    payments: [],
    totals: { count: 0, cash_amount: 0, non_cash_amount: 0 },
  },
} as unknown as { data: GapPreview };

const EMPTY_UNRESOLVED = {
  data: {
    previous_session: null,
    currency_code: "JPY",
    orders: [],
    totals: { count: 0, outstanding_amount: 0 },
  },
};

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
    <MemoryRouter initialEntries={[`/shop/${SHOP}/shift/open`]}>
      <Routes>
        <Route path="/shop/:shopSlug/shift/open" element={<ShiftOpenPage />} />
      </Routes>
    </MemoryRouter>,
    { wrapper: Wrapper },
  );
}

/** Type a quantity into a denomination row (rows are labelled per value). */
function countDenom(value: string, qty: number) {
  const input = screen.getByLabelText(
    t("shift.open.count.qty_label", { denom: value }),
  );
  fireEvent.change(input, { target: { value: String(qty) } });
}

function submitButton(): HTMLButtonElement {
  return screen.getByRole("button", {
    name: t("shift.open.action.submit"),
  }) as HTMLButtonElement;
}

async function waitForDenoms() {
  await screen.findByLabelText(
    t("shift.open.count.qty_label", { denom: "¥10,000" }),
  );
}

/** The single OpenShiftPayload handed to tillService.open(). */
function sentPayload(): Record<string, unknown> {
  return till.open.mock.calls[0][0] as Record<string, unknown>;
}

/**
 * Radix Checkbox renders a nameless `role="checkbox"` button carrying the id
 * its <label htmlFor> points at, so id lookup is the stable handle.
 */
function gapCheckbox(paymentId: string): Promise<HTMLElement> {
  return waitFor(() => {
    const el = document.getElementById(`gap-${paymentId}`);
    if (!el) throw new Error(`gap checkbox ${paymentId} not rendered yet`);
    return el;
  });
}

function ackCheckbox(): HTMLElement {
  const el = document.getElementById("gap-cash-ack");
  if (!el) throw new Error("held-separately ack not rendered");
  return el;
}

/** Drive the Radix opener <Select> (no user-event in this repo). */
async function pickOpener(optionLabel: string | RegExp) {
  const trigger = screen.getByRole("combobox");
  fireEvent.keyDown(trigger, { key: "Enter" });
  const option = await screen.findByRole("option", { name: optionLabel });
  fireEvent.click(option);
  await waitFor(() =>
    expect(screen.queryByRole("option", { name: optionLabel })).not.toBeInTheDocument(),
  );
}

// Radix's Select popper needs DOM APIs jsdom does not implement.
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
  globalThis.DOMRect ??= class {
    constructor(
      public x = 0,
      public y = 0,
      public width = 0,
      public height = 0,
    ) {}
    top = 0;
    left = 0;
    right = 0;
    bottom = 0;
    toJSON() {
      return {};
    }
  } as unknown as typeof DOMRect;
});

beforeEach(() => {
  vi.clearAllMocks();
  till.current.mockResolvedValue(currentTill());
  till.denominations.mockResolvedValue({ data: DENOMS });
  till.open.mockResolvedValue(openedSession());
  till.gapPreview.mockResolvedValue(EMPTY_GAP);
  till.unresolvedOrders.mockResolvedValue(EMPTY_UNRESOLVED);
  staffList.mockResolvedValue({
    data: [
      { id: "staff-1", name: "田中 太郎", email: "tanaka@example.com" },
      { id: "staff-2", name: "Lê Văn B", email: null },
    ],
  });
  settingsGet.mockResolvedValue({ data: { currency_code: "JPY" } });
  printShiftOpenReport.mockResolvedValue({ status: "ok" });
});

// ── Render ───────────────────────────────────────────────────────────────────

describe("ShiftOpenPage — render", () => {
  it("renders the denomination table and keeps submit closed until cash is counted", async () => {
    renderPage();
    await waitForDenoms();

    expect(screen.getByText(t("shift.open.title"))).toBeInTheDocument();
    expect(screen.getByText(t("shift.open.count.group_notes"))).toBeInTheDocument();
    expect(screen.getByText(t("shift.open.count.group_coins"))).toBeInTheDocument();
    // A blind count with zero cash is not a shift open.
    expect(submitButton()).toBeDisabled();
  });

  it("takes the currency from shop_order_settings, NOT the till default", async () => {
    // The till is JPY but Settings → Order says VND: Settings is the single
    // source of truth (a per-shift picker is exactly what causes drift).
    settingsGet.mockResolvedValue({ data: { currency_code: "VND" } });
    renderPage();
    await waitFor(() => expect(till.denominations).toHaveBeenCalledWith("VND"));
  });

  it("falls back to the till currency when settings has none", async () => {
    settingsGet.mockResolvedValue({ data: {} });
    renderPage();
    await waitFor(() => expect(till.denominations).toHaveBeenCalledWith("JPY"));
  });

  it("bounces to POS when a shift is ALREADY open on this till", async () => {
    till.current.mockResolvedValue({
      data: {
        ...currentTill().data,
        open_session: openedSession().data,
      },
    });
    renderPage();

    await waitFor(() =>
      expect(toast.info).toHaveBeenCalledWith(t("shift.open.error.already_open")),
    );
    expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}`, {
      replace: true,
    });
  });

  it("running total reflects value × quantity across notes and coins", async () => {
    renderPage();
    await waitForDenoms();

    countDenom("¥10,000", 3);
    countDenom("¥1,000", 1);
    countDenom("¥100", 5);

    // 30,000 + 1,000 + 500 = 31,500
    expect(screen.getByText("¥ 31,500")).toBeInTheDocument();
    // Per-row subtotal for the 3 × ¥10,000 notes.
    expect(screen.getByText("¥ 30,000")).toBeInTheDocument();
    expect(submitButton()).toBeEnabled();
  });
});

// ── Payload ──────────────────────────────────────────────────────────────────

describe("ShiftOpenPage — open payload", () => {
  it("sends only counted rows, a null note, and NO opener keys for '自分' (__me)", async () => {
    renderPage();
    await waitForDenoms();

    countDenom("¥10,000", 3);
    countDenom("¥1,000", 1);
    fireEvent.click(submitButton());

    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    const payload = sentPayload();
    expect(payload.opening_counts).toEqual([
      { denomination_id: "d-10000", quantity: 3 },
      { denomination_id: "d-1000", quantity: 1 },
    ]);
    // Zero-quantity denominations are dropped entirely.
    expect(JSON.stringify(payload)).not.toContain("d-100\"");
    // Empty note → explicit null (the column is nullable, "" would be a lie).
    expect(payload.opening_note).toBeNull();
    // Default opener = the signed-in device user → the backend infers it.
    expect("opened_by_id" in payload).toBe(false);
    expect("opener_name" in payload).toBe(false);
    // plan-044 R2 — nothing claimed → the gap keys must not appear at all.
    expect("claimed_gap_payment_ids" in payload).toBe(false);
    expect("gap_cash_held_separately_ack" in payload).toBe(false);
  });

  it("picking a staff member sends opened_by_id ONLY (never a free-text name)", async () => {
    renderPage();
    await waitForDenoms();
    await screen.findByRole("combobox");

    await pickOpener(/田中 太郎/);
    countDenom("¥1,000", 2);
    fireEvent.click(submitButton());

    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    expect(sentPayload().opened_by_id).toBe("staff-1");
    expect("opener_name" in sentPayload()).toBe(false);
  });

  it("'その他' sends a trimmed opener_name ONLY, and blocks submit while blank", async () => {
    renderPage();
    await waitForDenoms();
    await screen.findByRole("combobox");

    await pickOpener(t("shift.open.opener.other"));
    countDenom("¥1,000", 2);
    // Counted cash but no name yet → still blocked.
    expect(submitButton()).toBeDisabled();

    fireEvent.change(
      screen.getByPlaceholderText(t("shift.open.opener.other_placeholder")),
      { target: { value: "  Nguyễn Thời Vụ  " } },
    );
    expect(submitButton()).toBeEnabled();
    fireEvent.click(submitButton());

    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    expect(sentPayload().opener_name).toBe("Nguyễn Thời Vụ");
    expect("opened_by_id" in sentPayload()).toBe(false);
  });

  it("trims the opening note instead of sending padded whitespace", async () => {
    renderPage();
    await waitForDenoms();

    countDenom("¥1,000", 2);
    fireEvent.change(screen.getByLabelText(/./, { selector: "#opening-note" }), {
      target: { value: "  đầu ca thiếu tiền lẻ  " },
    });
    fireEvent.click(submitButton());

    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    expect(sentPayload().opening_note).toBe("đầu ca thiếu tiền lẻ");
  });

  it("prints the open slip and redirects to POS on success", async () => {
    renderPage();
    await waitForDenoms();

    countDenom("¥1,000", 2);
    fireEvent.click(submitButton());

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(t("shift.open.success")),
    );
    expect(printShiftOpenReport).toHaveBeenCalledWith({
      shopSlug: SHOP,
      sessionId: "sess-9",
      deviceName: "レジ1",
    });
    expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}`, {
      replace: true,
    });
  });

  it("still redirects when the slip printer blows up (print is best-effort)", async () => {
    printShiftOpenReport.mockRejectedValue(new Error("no printer"));
    renderPage();
    await waitForDenoms();

    countDenom("¥1,000", 2);
    fireEvent.click(submitButton());

    await waitFor(() =>
      expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}`, {
        replace: true,
      }),
    );
  });

  it("plan-046 — announces the chain position when this open continues a chain", async () => {
    till.open.mockResolvedValue(
      openedSession({
        continues_chain: true,
        chain_id: "chain-1",
        chain_sequence: 3,
      }),
    );
    renderPage();
    await waitForDenoms();

    countDenom("¥1,000", 2);
    fireEvent.click(submitButton());

    await waitFor(() =>
      expect(toast.info).toHaveBeenCalledWith(
        t("shift.open.chain.banner", { seq: "3" }),
      ),
    );
  });
});

// ── Gap reconciliation (plan-044 R2) ─────────────────────────────────────────

describe("ShiftOpenPage — gap claim", () => {
  beforeEach(() => {
    till.gapPreview.mockResolvedValue(gapPreview());
  });

  it("lists the gap payments taken during the previous close→open window", async () => {
    renderPage();
    await waitForDenoms();

    expect(
      await screen.findByText(t("shift.open.gap_reconcile.section")),
    ).toBeInTheDocument();
    expect(screen.getByText("ORD-501")).toBeInTheDocument();
    expect(screen.getByText("ORD-502")).toBeInTheDocument();
    // Cash is flagged as physically held aside.
    expect(
      screen.getByText(t("shift.open.gap_reconcile.cash_held_badge")),
    ).toBeInTheDocument();
  });

  it("claiming CASH blocks submit until the held-separately ack is ticked", async () => {
    renderPage();
    await waitForDenoms();
    countDenom("¥1,000", 2);
    expect(submitButton()).toBeEnabled();

    fireEvent.click(await gapCheckbox("pay-cash"));

    // No ack yet → the drawer cannot be opened with unaccounted cash.
    await waitFor(() => expect(submitButton()).toBeDisabled());
    expect(
      screen.getByText(t("shift.open.gap_reconcile.cash_callout_title")),
    ).toBeInTheDocument();

    fireEvent.click(ackCheckbox());
    await waitFor(() => expect(submitButton()).toBeEnabled());
  });

  it("sends the claimed ids + ack=true once the cashier confirms held-separately", async () => {
    renderPage();
    await waitForDenoms();
    countDenom("¥1,000", 2);

    fireEvent.click(await gapCheckbox("pay-cash"));
    await waitFor(() => expect(submitButton()).toBeDisabled());
    fireEvent.click(ackCheckbox());
    await waitFor(() => expect(submitButton()).toBeEnabled());

    fireEvent.click(submitButton());
    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    expect(sentPayload().claimed_gap_payment_ids).toEqual(["pay-cash"]);
    expect(sentPayload().gap_cash_held_separately_ack).toBe(true);
  });

  it("a NON-cash claim needs no ack and rides with ack=false", async () => {
    renderPage();
    await waitForDenoms();
    countDenom("¥1,000", 2);

    fireEvent.click(await gapCheckbox("pay-card"));
    expect(submitButton()).toBeEnabled();
    // No cash in the claim → no ack callout at all.
    expect(
      screen.queryByText(t("shift.open.gap_reconcile.cash_callout_title")),
    ).not.toBeInTheDocument();

    fireEvent.click(submitButton());
    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    expect(sentPayload().claimed_gap_payment_ids).toEqual(["pay-card"]);
    expect(sentPayload().gap_cash_held_separately_ack).toBe(false);
  });

  it("un-ticking the last claim drops the gap keys back out of the payload", async () => {
    renderPage();
    await waitForDenoms();
    countDenom("¥1,000", 2);

    const card = await gapCheckbox("pay-card");
    fireEvent.click(card);
    fireEvent.click(card);

    fireEvent.click(submitButton());
    await waitFor(() => expect(till.open).toHaveBeenCalledTimes(1));
    expect("claimed_gap_payment_ids" in sentPayload()).toBe(false);
    expect("gap_cash_held_separately_ack" in sentPayload()).toBe(false);
  });
});

// ── Failures ─────────────────────────────────────────────────────────────────

describe("ShiftOpenPage — open failures", () => {
  async function countAndSubmit() {
    await waitForDenoms();
    countDenom("¥1,000", 2);
    fireEvent.click(submitButton());
  }

  it("409 SHIFT_ALREADY_OPEN → dedicated toast + redirect into POS", async () => {
    till.open.mockRejectedValue(
      new ApiError(409, {
        code: "SHIFT_ALREADY_OPEN",
        message: "A shift is already open on this till.",
      }),
    );
    renderPage();
    await countAndSubmit();

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        t("shift.open.error.already_open"),
      ),
    );
    expect(navigateMock).toHaveBeenCalledWith(`/shop/${SHOP}`, {
      replace: true,
    });
    expect(printShiftOpenReport).not.toHaveBeenCalled();
  });

  it("a 409 with any other code surfaces the server message and still exits", async () => {
    till.open.mockRejectedValue(
      new ApiError(409, {
        code: "CURRENCY_MISMATCH",
        message: "Till currency changed.",
      }),
    );
    renderPage();
    await countAndSubmit();

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("Till currency changed."),
    );
  });

  it("a 422 stays on the page so the count is not lost", async () => {
    till.open.mockRejectedValue(
      new ApiError(422, {
        message: "The opening counts field is required.",
        errors: { opening_counts: ["The opening counts field is required."] },
      }),
    );
    renderPage();
    await countAndSubmit();

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "The opening counts field is required.",
      ),
    );
    expect(navigateMock).not.toHaveBeenCalled();
    // The counted quantity is still on screen — nothing was reset.
    expect(
      screen.getByLabelText(t("shift.open.count.qty_label", { denom: "¥1,000" })),
    ).toHaveValue("2");
  });
});

/*
 * #1501 — mở ca ghi một TillSession trên Cloud. Không có mạng thì không có
 * ca, và một "ca mở offline" sẽ là ca ma: mọi khoản thu sau đó không có
 * till_session_id để quy về đâu cả.
 */
describe("ShiftOpenPage — mất kết nối thì khoá nút mở ca (#1501)", () => {
  beforeEach(() => {
    resetNetworkStatus();
  });

  it("đếm đủ tiền nhưng offline ⇒ nút mở ca bị khoá kèm lý do", async () => {
    markApiOutcome("network-error");
    markApiOutcome("network-error");

    renderPage();
    await waitForDenoms();
    countDenom("¥10,000", 1);

    await waitFor(() => expect(submitButton()).toBeDisabled());
    expect(submitButton()).toHaveAttribute("title");
  });

  it("có mạng thì vẫn mở được như cũ", async () => {
    renderPage();
    await waitForDenoms();
    countDenom("¥10,000", 1);

    await waitFor(() => expect(submitButton()).toBeEnabled());
  });
});
