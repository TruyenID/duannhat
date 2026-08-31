/**
 * CashEventDialog + AbandonShiftDialog — component tests (#1183, P2).
 *
 * Both are drawer-money exits reachable from the POS header:
 *   - 入金/出金 moves physical cash mid-shift and immediately changes the
 *     expected-cash figure the close screen reconciles against, so the exact
 *     body (`event_type` / numeric `amount` / trimmed `reason` /
 *     `reference_no` NULL when blank) is the contract under test;
 *   - シフト取消 is the "mis-opened shift" exit door, valid only while no
 *     payment is stamped — the 409 SHIFT_HAS_PAYMENTS path must land on its
 *     own operator-actionable toast, not a raw server string.
 *
 * Spied at the `apiFetch` boundary so the real service + hooks run.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { useState, type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type * as ApiModule from "@/lib/api";

const apiFetchMock = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof ApiModule>();
  return { ...actual, apiFetch: apiFetchMock };
});

vi.mock("sonner", () => ({
  toast: Object.assign(() => {}, {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
  }),
}));

import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { AppProvider, getT } from "@/providers/app-provider";
import { CashEventDialog } from "./cash-event-dialog";
import { AbandonShiftDialog } from "./abandon-shift-dialog";

const SHOP = "ningyocho";
const SESSION = "sess-9";
const CASH_EVENT_URL = `/api/v1/pos/till/sessions/${SESSION}/cash-events`;
const ABANDON_URL = `/api/v1/pos/till/sessions/${SESSION}/abandon`;

const t = getT();

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

function sentBody(call = 0): Record<string, unknown> {
  const [, init] = apiFetchMock.mock.calls[call] as [string, RequestInit];
  return JSON.parse(init.body as string) as Record<string, unknown>;
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
  apiFetchMock.mockResolvedValue({ data: { id: "ev-1" } });
});

// ── CashEventDialog ──────────────────────────────────────────────────────────

function CashHost() {
  const [open, setOpen] = useState(true);
  return (
    <>
      <span data-testid="cash-open">{String(open)}</span>
      <CashEventDialog
        shopSlug={SHOP}
        sessionId={SESSION}
        open={open}
        onOpenChange={setOpen}
      />
    </>
  );
}

function renderCashDialog() {
  return render(<CashHost />, { wrapper: Wrapper });
}

function saveButton(): HTMLButtonElement {
  return screen.getByRole("button", {
    name: t("shift.cash_event.action.save"),
  }) as HTMLButtonElement;
}

function fillAmount(value: string) {
  fireEvent.change(
    screen.getByLabelText(t("shift.cash_event.field.amount")),
    { target: { value } },
  );
}

function fillReason(value: string) {
  fireEvent.change(
    screen.getByLabelText(t("shift.cash_event.field.reason")),
    { target: { value } },
  );
}

describe("CashEventDialog — render + validation", () => {
  it("defaults to 入金 and keeps save closed until amount AND reason are present", () => {
    renderCashDialog();

    expect(screen.getByText(t("shift.cash_event.title"))).toBeInTheDocument();
    expect(screen.getByRole("combobox")).toHaveTextContent(
      t("shift.cash_event.type.paid_in"),
    );
    expect(saveButton()).toBeDisabled();

    fillAmount("5000");
    expect(saveButton()).toBeDisabled();

    fillReason("mua đá");
    expect(saveButton()).toBeEnabled();
  });

  it("rejects a zero / non-positive amount", () => {
    renderCashDialog();
    fillReason("mua đá");
    fillAmount("0");
    expect(saveButton()).toBeDisabled();
  });

  it("rejects a whitespace-only reason", () => {
    renderCashDialog();
    fillAmount("5000");
    fillReason("   ");
    expect(saveButton()).toBeDisabled();
  });

  it("strips non-numeric characters typed into the amount", () => {
    renderCashDialog();
    fillAmount("5,0a0b0");
    expect(
      screen.getByLabelText(t("shift.cash_event.field.amount")),
    ).toHaveValue("5000");
  });
});

describe("CashEventDialog — submitted body", () => {
  it("POSTs a paid_in with a NUMERIC amount, trimmed reason and NULL reference", async () => {
    renderCashDialog();
    fillAmount("5000");
    fillReason("  mua đá cho quầy  ");
    fireEvent.click(saveButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(apiFetchMock.mock.calls[0][0]).toBe(CASH_EVENT_URL);
    expect(apiFetchMock.mock.calls[0][1]).toMatchObject({ method: "POST" });
    expect(sentBody()).toEqual({
      event_type: "paid_in",
      amount: 5000,
      reason: "mua đá cho quầy",
      // Blank reference is an explicit null, never "".
      reference_no: null,
    });
    expect(toast.success).toHaveBeenCalledWith(
      t("shift.cash_event.success.paid_in"),
    );
    // Dialog closes only after the write lands.
    await waitFor(() =>
      expect(screen.getByTestId("cash-open")).toHaveTextContent("false"),
    );
  });

  it("carries a trimmed reference number when the cashier supplies one", async () => {
    renderCashDialog();
    fillAmount("1200.50");
    fillReason("hoàn tiền khách");
    fireEvent.change(
      screen.getByLabelText(new RegExp(t("shift.cash_event.field.reference"))),
      { target: { value: "  INV-7781  " } },
    );
    fireEvent.click(saveButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toMatchObject({
      amount: 1200.5,
      reference_no: "INV-7781",
    });
  });

  it("records a paid_out when the type is switched, with the matching toast", async () => {
    renderCashDialog();

    const trigger = screen.getByRole("combobox");
    fireEvent.keyDown(trigger, { key: "Enter" });
    fireEvent.click(
      await screen.findByRole("option", {
        name: t("shift.cash_event.type.paid_out"),
      }),
    );

    fillAmount("3000");
    fillReason("trả tiền ship");
    fireEvent.click(saveButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody().event_type).toBe("paid_out");
    expect(toast.success).toHaveBeenCalledWith(
      t("shift.cash_event.success.paid_out"),
    );
  });

  it("keeps the dialog open and reports the message when the write fails", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(409, { message: "Shift is not open." }),
    );
    renderCashDialog();
    fillAmount("5000");
    fillReason("mua đá");
    fireEvent.click(saveButton());

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("Shift is not open."),
    );
    expect(screen.getByTestId("cash-open")).toHaveTextContent("true");
    expect(toast.success).not.toHaveBeenCalled();
  });

  it("clears every field when reopened so no amount leaks between events", async () => {
    render(<CashReopenHost />, { wrapper: Wrapper });

    fillAmount("9999");
    fillReason("draft");
    fireEvent.click(
      screen.getByRole("button", { name: t("shift.cash_event.action.cancel") }),
    );
    await waitFor(() =>
      expect(
        screen.queryByLabelText(t("shift.cash_event.field.amount")),
      ).not.toBeInTheDocument(),
    );

    fireEvent.click(screen.getByTestId("reopen"));
    const amount = await screen.findByLabelText(
      t("shift.cash_event.field.amount"),
    );
    expect(amount).toHaveValue("");
    expect(
      screen.getByLabelText(t("shift.cash_event.field.reason")),
    ).toHaveValue("");
    expect(apiFetchMock).not.toHaveBeenCalled();
  });
});

function CashReopenHost() {
  const [open, setOpen] = useState(true);
  return (
    <>
      <button type="button" data-testid="reopen" onClick={() => setOpen(true)}>
        reopen
      </button>
      <CashEventDialog
        shopSlug={SHOP}
        sessionId={SESSION}
        open={open}
        onOpenChange={setOpen}
      />
    </>
  );
}

// ── AbandonShiftDialog ───────────────────────────────────────────────────────

function AbandonHost({ onAbandoned }: { onAbandoned?: () => void }) {
  const [open, setOpen] = useState(true);
  return (
    <>
      <span data-testid="abandon-open">{String(open)}</span>
      <AbandonShiftDialog
        shopSlug={SHOP}
        sessionId={SESSION}
        open={open}
        onOpenChange={setOpen}
        onAbandoned={onAbandoned}
      />
    </>
  );
}

function abandonButton(): HTMLButtonElement {
  return screen.getByRole("button", {
    name: t("shift.abandon.action.abandon"),
  }) as HTMLButtonElement;
}

describe("AbandonShiftDialog", () => {
  it("warns that the shift cannot be restored and allows abandoning without a reason", async () => {
    render(<AbandonHost />, { wrapper: Wrapper });

    expect(screen.getAllByText(t("shift.abandon.description")).length).toBeGreaterThan(0);
    // The reason is recommended, not required — a mis-opened shift must never
    // be trapped behind a text box.
    expect(abandonButton()).toBeEnabled();
    fireEvent.click(abandonButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(apiFetchMock.mock.calls[0][0]).toBe(ABANDON_URL);
    expect(sentBody()).toEqual({ abandon_reason: null });
    expect(toast.success).toHaveBeenCalledWith(t("shift.abandon.success"));
  });

  it("sends the trimmed reason and notifies the caller", async () => {
    const onAbandoned = vi.fn();
    render(<AbandonHost onAbandoned={onAbandoned} />, { wrapper: Wrapper });

    fireEvent.change(
      screen.getByLabelText(t("shift.cash_event.field.reason")),
      { target: { value: "  mở nhầm ca  " } },
    );
    fireEvent.click(abandonButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({ abandon_reason: "mở nhầm ca" });
    await waitFor(() => expect(onAbandoned).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(screen.getByTestId("abandon-open")).toHaveTextContent("false"),
    );
  });

  it("409 SHIFT_HAS_PAYMENTS → 'close it instead' toast, dialog stays open", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(409, {
        code: "SHIFT_HAS_PAYMENTS",
        message: "Session has payments.",
      }),
    );
    const onAbandoned = vi.fn();
    render(<AbandonHost onAbandoned={onAbandoned} />, { wrapper: Wrapper });

    fireEvent.click(abandonButton());

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        t("shift.abandon.error.has_payments"),
      ),
    );
    expect(onAbandoned).not.toHaveBeenCalled();
    expect(screen.getByTestId("abandon-open")).toHaveTextContent("true");
  });

  it("any other failure surfaces the server message", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(422, { message: "Session already settled." }),
    );
    render(<AbandonHost />, { wrapper: Wrapper });

    fireEvent.click(abandonButton());

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("Session already settled."),
    );
  });
});
