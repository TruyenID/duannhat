/**
 * VoidOrderDialog — component tests (#1183, P1).
 *
 * Voiding a whole order is irreversible and releases the tables, so the
 * dialog is a hard gate: a 10+ character audit reason is mandatory. These
 * tests mount it wired to the real `useVoidOrder` hook + `orderService` and
 * spy at the `apiFetch` boundary, so the assertion is on the actual body
 * (`{ void_reason }` and nothing else) rather than on the callback shape.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
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
  toast: Object.assign(() => {}, { success: vi.fn(), error: vi.fn() }),
}));

import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { AppProvider, getT } from "@/providers/app-provider";
import { LOCALE_STORAGE_KEY } from "@/i18n";
import { useVoidOrder } from "@/hooks/api/use-orders";
import type { CustomerOrder } from "../types";
import { VoidOrderDialog } from "./void-order-dialog";

const SHOP = "ningyocho";
const ORDER = "order-1";
const ORDER_CODE = "ORD-20260728-0007";
const VOID_URL = `/api/v1/pos/orders/${ORDER}/void`;
const MIN_REASON = 10;

const t = getT();

/** Backend answers with the voided order; money rides as decimal strings. */
function voidedOrderResponse(): { data: CustomerOrder } {
  return {
    data: {
      id: ORDER,
      order_code: ORDER_CODE,
      status: "voided",
      total_amount: "184000.00",
      paid_amount: "0.00",
      remaining_amount: "0.00",
      tables: [],
    } as unknown as CustomerOrder,
  };
}

function deferred<T>() {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((res) => {
    resolve = res;
  });
  return { promise, resolve };
}

/** Mirrors `page.tsx::handleVoidOrder` (rejection swallowed → inline banner). */
function Host({ onVoided = vi.fn() }: { onVoided?: () => void } = {}) {
  const [open, setOpen] = useState(true);
  const voidOrder = useVoidOrder(SHOP, ORDER);

  async function onConfirm(reason: string) {
    try {
      await voidOrder.mutateAsync(reason);
      onVoided();
    } catch {
      /* page.tsx surfaces this in its error banner */
    }
  }

  return (
    <>
      <button type="button" data-testid="reopen" onClick={() => setOpen(true)}>
        reopen
      </button>
      <VoidOrderDialog
        open={open}
        onOpenChange={setOpen}
        orderCode={ORDER_CODE}
        onConfirm={onConfirm}
      />
    </>
  );
}

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

function renderHost(props: Parameters<typeof Host>[0] = {}) {
  return render(<Host {...props} />, { wrapper: Wrapper });
}

function reasonBox(translate = t): HTMLTextAreaElement {
  return screen.getByLabelText(
    translate("pos.dialog.void_order.reason_label"),
  ) as HTMLTextAreaElement;
}

function confirmButton(translate = t): HTMLButtonElement {
  return screen.getByRole("button", {
    name: translate("pos.dialog.void_order.confirm"),
  }) as HTMLButtonElement;
}

function sentBody(call = 0): Record<string, unknown> {
  const [, init] = apiFetchMock.mock.calls[call] as [string, RequestInit];
  return JSON.parse(init.body as string) as Record<string, unknown>;
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.removeItem(LOCALE_STORAGE_KEY);
  apiFetchMock.mockResolvedValue(voidedOrderResponse());
});

describe("VoidOrderDialog — render", () => {
  it("shows the order code, the irreversibility warning, and the 10-char audit hint", () => {
    renderHost();

    expect(screen.getByText(ORDER_CODE)).toBeInTheDocument();
    expect(
      screen.getByText(t("pos.dialog.void_order.desc")),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        t("pos.dialog.void_order.audit_hint", { min: String(MIN_REASON) }),
      ),
    ).toBeInTheDocument();
    // Empty reason → the destructive button is closed.
    expect(confirmButton()).toBeDisabled();
  });

  it("renders every preset chip and fills the reason box on tap", () => {
    renderHost();

    const presets = [
      "pos.void_reason.customer_changed",
      "pos.void_reason.wrong_operation",
      "pos.void_reason.system_error",
      "pos.void_reason.customer_request",
    ].map((k) => t(k));

    for (const preset of presets) {
      expect(screen.getByRole("button", { name: preset })).toBeInTheDocument();
    }

    fireEvent.click(screen.getByRole("button", { name: presets[0] }));
    expect(reasonBox()).toHaveValue(presets[0]);
  });
});

describe("VoidOrderDialog — audit-reason gate", () => {
  it("keeps confirm disabled below 10 characters and live at exactly 10", () => {
    renderHost();

    fireEvent.change(reasonBox(), { target: { value: "123456789" } });
    expect(confirmButton()).toBeDisabled();
    // The counter tracks the TRIMMED length, not the raw input.
    expect(screen.getByText(`9 / ${MIN_REASON}+ ${t("common.characters")}`)).toBeInTheDocument();

    fireEvent.change(reasonBox(), { target: { value: "1234567890" } });
    expect(confirmButton()).toBeEnabled();
  });

  it("#1188 a preset submits at any length — every ja chip is under the minimum", async () => {
    // ja presets run 4–7 characters against MIN_REASON=10, so before #1188 a
    // JP cashier could not void an order from ANY chip: tapping filled the box
    // and left confirm dead, with no hint beyond a red counter.
    localStorage.setItem(LOCALE_STORAGE_KEY, "ja");
    const ja = getT();
    renderHost();

    const preset = ja("pos.void_reason.wrong_operation");
    expect(preset.length).toBeLessThan(MIN_REASON);
    fireEvent.click(screen.getByRole("button", { name: preset }));
    expect(reasonBox(ja)).toHaveValue(preset);
    expect(confirmButton(ja)).toBeEnabled();

    fireEvent.click(confirmButton(ja));
    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({ void_reason: preset });
  });

  it("#1188 free text under the minimum is still refused", () => {
    renderHost();

    fireEvent.change(reasonBox(), { target: { value: "ngắn" } });
    expect(confirmButton()).toBeDisabled();
  });

  it("does not let padding whitespace buy its way past the minimum", () => {
    renderHost();

    fireEvent.change(reasonBox(), { target: { value: "  ngắn   " } });
    expect(confirmButton()).toBeDisabled();
  });
});

describe("VoidOrderDialog — submitted body", () => {
  it("POSTs ONLY void_reason, trimmed, to the order void endpoint", async () => {
    renderHost();

    fireEvent.change(reasonBox(), {
      target: { value: "  khách huỷ toàn bộ đơn  " },
    });
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(apiFetchMock.mock.calls[0][0]).toBe(VOID_URL);
    expect(apiFetchMock.mock.calls[0][1]).toMatchObject({ method: "POST" });
    expect(sentBody()).toEqual({ void_reason: "khách huỷ toàn bộ đơn" });
    // No reason-id / note split here — that shape belongs to item voids only.
    expect(Object.keys(sentBody())).toEqual(["void_reason"]);
  });

  it("toasts the success key and reports up to the caller", async () => {
    const onVoided = vi.fn();
    renderHost({ onVoided });

    fireEvent.change(reasonBox(), {
      target: { value: "thao tác nhầm đơn của bàn 4" },
    });
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onVoided).toHaveBeenCalledTimes(1));
    expect(toast.success).toHaveBeenCalledWith(t("pos.toast.order_voided"));
    // Dialog is gone once the void lands.
    await waitFor(() =>
      expect(screen.queryByText(ORDER_CODE)).not.toBeInTheDocument(),
    );
  });

  it("locks the button in flight and fires exactly one void on a double tap", async () => {
    const d = deferred<{ data: CustomerOrder }>();
    apiFetchMock.mockReturnValue(d.promise);
    renderHost();

    fireEvent.change(reasonBox(), {
      target: { value: "hệ thống lỗi, tạo lại đơn" },
    });
    const btn = confirmButton();
    fireEvent.click(btn);
    fireEvent.click(btn);

    const busy = await screen.findByRole("button", {
      // In-flight label sits beside a spinner → loose match on the label.
      name: new RegExp(t("pos.dialog.void_order.canceling")),
    });
    expect(busy).toBeDisabled();

    d.resolve(voidedOrderResponse());
    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
  });

  it("clears the typed reason when the dialog is dismissed and reopened", async () => {
    renderHost();

    fireEvent.change(reasonBox(), { target: { value: "draft bị bỏ dở" } });
    fireEvent.click(screen.getByRole("button", { name: t("common.cancel") }));

    await waitFor(() =>
      expect(screen.queryByText(ORDER_CODE)).not.toBeInTheDocument(),
    );
    fireEvent.click(screen.getByTestId("reopen"));

    await waitFor(() => expect(screen.getByText(ORDER_CODE)).toBeInTheDocument());
    expect(reasonBox()).toHaveValue("");
    expect(confirmButton()).toBeDisabled();
    expect(apiFetchMock).not.toHaveBeenCalled();
  });
});

describe("VoidOrderDialog — backend rejections", () => {
  function typeAndSubmit() {
    fireEvent.change(reasonBox(), {
      target: { value: "khách bỏ về, không thanh toán" },
    });
    fireEvent.click(confirmButton());
  }

  it("409 on an already-paid order surfaces the envelope message, no success toast", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(409, {
        code: "ORDER_NOT_VOIDABLE",
        message: "Đơn đã thanh toán không thể huỷ.",
      }),
    );
    renderHost();
    typeAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith("Đơn đã thanh toán không thể huỷ.");
    expect(toast.success).not.toHaveBeenCalled();
    expect(apiFetchMock).toHaveBeenCalledTimes(1);
  });

  it("422 validation: the toast keeps the TOP-LEVEL message (per-field text stays on ApiError)", async () => {
    const err = new ApiError(422, {
      message: "The given data was invalid.",
      errors: { void_reason: ["The void reason must be at least 10 characters."] },
    });
    apiFetchMock.mockRejectedValue(err);
    renderHost();
    typeAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    // useVoidOrder's generic handler prefers body.message; the per-field
    // detail is still reachable via ApiError.firstFieldError() for any
    // surface that wants it (#284 envelope).
    expect(toast.error).toHaveBeenCalledWith("The given data was invalid.");
    expect(err.firstFieldError()).toBe(
      "The void reason must be at least 10 characters.",
    );
  });

  it("a workstation transport failure still tells the cashier something", async () => {
    apiFetchMock.mockRejectedValue(new Error("workstation unreachable"));
    renderHost();
    typeAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith("workstation unreachable");
  });
});
