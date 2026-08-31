/**
 * VoidItemDialog — component tests (#1183, P1).
 *
 * The dialog itself is presentational (it hands a `VoidItemSubmit` to its
 * caller), but the money-relevant contract is the BODY that ends up on the
 * wire: plan-051 (#1149) says `void_reason_id` and `void_reason` are OMITTED
 * — not sent as null — when absent, because the backend gates them with
 * `required_without`. So these tests mount the dialog wired to the real
 * `useVoidItem` hook + the real `orderService`, and spy at the `apiFetch`
 * boundary. That also exercises the three structured rejection codes
 * (ITEM_STATUS_NOT_VOIDABLE / VOID_REASON_INVALID / VOID_NOTE_REQUIRED) →
 * operator toast mapping, which lives in the hook.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { useState, type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type * as ApiModule from "@/lib/api";

// ── Network edge ─────────────────────────────────────────────────────────────
// Keep the REAL ApiError class (the hook does `error instanceof ApiError`),
// swap only the fetch function.
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
import { useVoidItem } from "@/hooks/api/use-orders";
import type { CustomerOrder } from "../types";
import type { VoidReason } from "@/services/void-reason-service";
import { VoidItemDialog, type VoidItemSubmit } from "./void-item-dialog";

const SHOP = "ningyocho";
const ORDER = "order-1";
const ITEM = "item-1";
const VOID_URL = `/api/v1/pos/orders/${ORDER}/items/${ITEM}/void`;

const t = getT();

// ── Fixtures ─────────────────────────────────────────────────────────────────

/** Brand VoidReason master rows as the API returns them. */
const REASONS: VoidReason[] = [
  {
    id: "vr-mistake",
    label: "Bấm nhầm",
    stock_effect: "restock",
    requires_note: false,
    sort_order: 0,
  },
  {
    id: "vr-quality",
    label: "Món lỗi",
    stock_effect: "waste",
    requires_note: true,
    sort_order: 1,
  },
];

/**
 * The void endpoint answers with the refreshed order. Money fields ride as
 * decimal STRINGS the way the backend serializes them.
 */
function voidedOrderResponse(): { data: CustomerOrder } {
  return {
    data: {
      id: ORDER,
      order_code: "ORD-0007",
      status: "open",
      total_amount: "18000.00",
      paid_amount: "0.00",
      remaining_amount: "18000.00",
      items: [
        {
          id: ITEM,
          quantity: 1,
          status: "voided",
          unit_price: "45000.00",
          line_total: "0.00",
        },
      ],
    } as unknown as CustomerOrder,
  };
}

/** A promise the test resolves by hand (in-flight assertions). */
function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

// ── Harness ──────────────────────────────────────────────────────────────────

/**
 * Mirrors `page.tsx::handleVoidItemConfirm` — maps the dialog's submit shape
 * onto the mutation variables and swallows the rejection (page.tsx surfaces
 * it through its own error banner), so the toast mapping in the hook is what
 * the test observes.
 */
function Host({
  reasons,
  reasonsLoading = false,
}: {
  reasons?: VoidReason[];
  reasonsLoading?: boolean;
}) {
  const [open, setOpen] = useState(true);
  const voidItem = useVoidItem(SHOP, ORDER);

  async function onConfirm(payload: VoidItemSubmit) {
    try {
      await voidItem.mutateAsync({
        itemId: ITEM,
        voidReasonId: payload.voidReasonId,
        voidReason: payload.note,
      });
    } catch {
      /* page.tsx shows an inline banner; irrelevant here */
    }
  }

  return (
    <>
      <button type="button" data-testid="reopen" onClick={() => setOpen(true)}>
        reopen
      </button>
      <VoidItemDialog
        open={open}
        onOpenChange={setOpen}
        itemLabel="Phở bò tái × 1"
        reasons={reasons}
        reasonsLoading={reasonsLoading}
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

function confirmButton(translate = t): HTMLButtonElement {
  return screen.getByRole("button", {
    name: translate("pos.dialog.void_item.confirm"),
  }) as HTMLButtonElement;
}

/** The exact JSON body handed to apiFetch on the Nth call. */
function sentBody(call = 0): Record<string, unknown> {
  const [, init] = apiFetchMock.mock.calls[call] as [string, RequestInit];
  return JSON.parse(init.body as string) as Record<string, unknown>;
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.removeItem(LOCALE_STORAGE_KEY);
  apiFetchMock.mockResolvedValue(voidedOrderResponse());
});

// ── Render ───────────────────────────────────────────────────────────────────

describe("VoidItemDialog — picker render (plan-051 #1149)", () => {
  it("renders the item label, every master reason, and the required-note tag", () => {
    renderHost({ reasons: REASONS });

    expect(screen.getByText("Phở bò tái × 1")).toBeInTheDocument();
    expect(
      screen.getByText(t("pos.dialog.void_item.pick_label")),
    ).toBeInTheDocument();

    const radios = screen.getAllByRole("radio");
    expect(radios).toHaveLength(2);
    expect(radios[0]).toHaveTextContent("Bấm nhầm");
    expect(radios[1]).toHaveTextContent("Món lỗi");
    // requires_note row is flagged inline so staff sees the demand up front.
    expect(radios[1]).toHaveTextContent(
      t("pos.dialog.void_item.note_required_tag"),
    );
    // Nothing picked yet → the destructive action stays closed.
    expect(confirmButton()).toBeDisabled();
    // Free-text is the SECONDARY path while a picker exists.
    expect(
      screen.queryByText(t("pos.dialog.void_item.preset_label")),
    ).not.toBeInTheDocument();
  });

  it("falls back to free-text as the PRIMARY path when the master list is empty (LAN without a mirror)", () => {
    renderHost({ reasons: [] });

    expect(screen.queryByRole("radio")).not.toBeInTheDocument();
    expect(
      screen.getByText(t("pos.dialog.void_item.preset_label")),
    ).toBeInTheDocument();
    // No way back to a picker that does not exist.
    expect(
      screen.queryByText(t("pos.dialog.void_item.picker_toggle")),
    ).not.toBeInTheDocument();
  });

  it("shows a spinner while the reason list is still loading", () => {
    renderHost({ reasons: [], reasonsLoading: true });

    expect(screen.getByText(t("common.loading"))).toBeInTheDocument();
    expect(screen.queryByRole("radio")).not.toBeInTheDocument();
    expect(
      screen.queryByText(t("pos.dialog.void_item.preset_label")),
    ).not.toBeInTheDocument();
  });
});

// ── Payload shape ────────────────────────────────────────────────────────────

describe("VoidItemDialog — picker path body shape", () => {
  it("sends ONLY void_reason_id when the picked reason needs no note", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    expect(confirmButton()).toBeEnabled();
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(apiFetchMock.mock.calls[0][0]).toBe(VOID_URL);
    expect(apiFetchMock.mock.calls[0][1]).toMatchObject({ method: "POST" });
    // `void_reason` must be ABSENT, not null — the backend's required_without
    // rule reads the key's presence.
    expect(sentBody()).toEqual({ void_reason_id: "vr-mistake" });
    expect("void_reason" in sentBody()).toBe(false);
  });

  it("carries an OPTIONAL note alongside the reason id when staff types one", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    fireEvent.change(screen.getByLabelText(/補足メモ/), {
      target: { value: "  gõ nhầm bàn 5  " },
    });
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    // Trimmed on the way out.
    expect(sentBody()).toEqual({
      void_reason_id: "vr-mistake",
      void_reason: "gõ nhầm bàn 5",
    });
  });

  it("blocks submit until a requires_note reason actually has a note", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Món lỗi/ }));
    expect(confirmButton()).toBeDisabled();

    // Whitespace is not a note.
    const note = screen.getByLabelText(/補足メモ/);
    fireEvent.change(note, { target: { value: "   " } });
    expect(confirmButton()).toBeDisabled();

    fireEvent.change(note, { target: { value: "cháy món, bếp xác nhận" } });
    expect(confirmButton()).toBeEnabled();
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({
      void_reason_id: "vr-quality",
      void_reason: "cháy món, bếp xác nhận",
    });
  });

  it("switching the picked reason re-targets the id that goes out", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    fireEvent.click(screen.getByRole("radio", { name: /Món lỗi/ }));
    fireEvent.change(screen.getByLabelText(/補足メモ/), {
      target: { value: "khách trả lại" },
    });
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody().void_reason_id).toBe("vr-quality");
  });
});

describe("VoidItemDialog — manual free-text fallback body shape", () => {
  it("sends ONLY void_reason (no id) after the manual override, and enforces 5+ chars", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByText(t("pos.dialog.void_item.manual_toggle")));
    // Picker is replaced by the legacy chips + textarea.
    expect(screen.queryByRole("radio")).not.toBeInTheDocument();
    expect(
      screen.getByText(t("pos.dialog.void_item.preset_label")),
    ).toBeInTheDocument();

    const textarea = screen.getByLabelText(
      t("pos.dialog.void_item.reason_label"),
    );
    fireEvent.change(textarea, { target: { value: "hết" } });
    expect(confirmButton()).toBeDisabled();

    fireEvent.change(textarea, { target: { value: "  hết nguyên liệu  " } });
    expect(confirmButton()).toBeEnabled();
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({ void_reason: "hết nguyên liệu" });
    expect("void_reason_id" in sentBody()).toBe(false);
  });

  it("a preset chip fills the free-text reason verbatim", async () => {
    renderHost({ reasons: [] });

    const preset = t("pos.void_item_reason.customer_changed");
    fireEvent.click(screen.getByRole("button", { name: preset }));
    expect(
      screen.getByLabelText(t("pos.dialog.void_item.reason_label")),
    ).toHaveValue(preset);
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({ void_reason: preset });
  });

  it("#1188 a preset shorter than MIN_REASON still submits", async () => {
    // ja `食材切れ` / `調理ミス` are 4 characters while the manual path demands
    // 5+. Holding an approved preset to the junk-text gate left a JP cashier
    // with a filled box and a dead confirm button — the one locale that is
    // also the main market. A preset now clears the gate at any length.
    localStorage.setItem(LOCALE_STORAGE_KEY, "ja");
    const ja = getT();
    renderHost({ reasons: [] });

    const shortPreset = ja("pos.void_item_reason.out_of_stock");
    expect(shortPreset.length).toBeLessThan(5);
    fireEvent.click(screen.getByRole("button", { name: shortPreset }));
    expect(
      screen.getByLabelText(ja("pos.dialog.void_item.reason_label")),
    ).toHaveValue(shortPreset);
    expect(confirmButton(ja)).toBeEnabled();

    fireEvent.click(confirmButton(ja));
    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({ void_reason: shortPreset });
  });

  it("#1188 free text below MIN_REASON is still refused", () => {
    renderHost({ reasons: [] });

    fireEvent.change(
      screen.getByLabelText(t("pos.dialog.void_item.reason_label")),
      { target: { value: "aa" } },
    );
    expect(confirmButton()).toBeDisabled();
  });

  it("toggling BACK to the picker drops the free text and sends the id instead", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByText(t("pos.dialog.void_item.manual_toggle")));
    fireEvent.change(
      screen.getByLabelText(t("pos.dialog.void_item.reason_label")),
      { target: { value: "typed then abandoned" } },
    );
    fireEvent.click(screen.getByText(t("pos.dialog.void_item.picker_toggle")));

    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    fireEvent.click(confirmButton());

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
    expect(sentBody()).toEqual({ void_reason_id: "vr-mistake" });
  });
});

// ── Lifecycle ────────────────────────────────────────────────────────────────

describe("VoidItemDialog — submit lifecycle", () => {
  it("locks the button while in flight and closes on success", async () => {
    const d = deferred<{ data: CustomerOrder }>();
    apiFetchMock.mockReturnValue(d.promise);
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    fireEvent.click(confirmButton());

    // The in-flight label sits next to a role="status" spinner, so the
    // accessible name is "Loading <label>" — match loosely.
    const busy = await screen.findByRole("button", {
      name: new RegExp(t("pos.dialog.void_item.canceling")),
    });
    expect(busy).toBeDisabled();

    d.resolve(voidedOrderResponse());
    await waitFor(() =>
      expect(
        screen.queryByText(t("pos.dialog.void_item.pick_label")),
      ).not.toBeInTheDocument(),
    );
  });

  it("double-tapping confirm fires exactly ONE void", async () => {
    const d = deferred<{ data: CustomerOrder }>();
    apiFetchMock.mockReturnValue(d.promise);
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    const btn = confirmButton();
    fireEvent.click(btn);
    fireEvent.click(btn);

    d.resolve(voidedOrderResponse());
    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(1));
  });

  it("resets the picked reason + note when the dialog is closed and reopened", async () => {
    renderHost({ reasons: REASONS });

    fireEvent.click(screen.getByRole("radio", { name: /Món lỗi/ }));
    fireEvent.change(screen.getByLabelText(/補足メモ/), {
      target: { value: "stale draft" },
    });
    fireEvent.click(screen.getByRole("button", { name: t("common.cancel") }));

    await waitFor(() =>
      expect(screen.queryByRole("radio")).not.toBeInTheDocument(),
    );
    fireEvent.click(screen.getByTestId("reopen"));

    const radios = await screen.findAllByRole("radio");
    expect(radios.every((r) => r.getAttribute("aria-checked") === "false")).toBe(
      true,
    );
    // No selection → no note field, and confirm is closed again.
    expect(screen.queryByLabelText(/補足メモ/)).not.toBeInTheDocument();
    expect(confirmButton()).toBeDisabled();
    expect(apiFetchMock).not.toHaveBeenCalled();
  });
});

// ── Structured rejections ────────────────────────────────────────────────────

describe("VoidItemDialog — plan-051 rejection codes", () => {
  function pickAndSubmit() {
    fireEvent.click(screen.getByRole("radio", { name: /Bấm nhầm/ }));
    fireEvent.click(confirmButton());
  }

  it("409 ITEM_STATUS_NOT_VOIDABLE → status toast with the allowed statuses interpolated", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(409, {
        code: "ITEM_STATUS_NOT_VOIDABLE",
        message: "Item is not voidable.",
        voidable_statuses: ["pending", "preparing"],
      }),
    );
    renderHost({ reasons: REASONS });
    pickAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith(
      t("pos.toast.void_item.status_not_voidable", {
        statuses: "pending, preparing",
      }),
    );
    // Caller (page.tsx) swallows the rejection, so the dialog closes and the
    // toast is the ONLY operator-facing signal — no silent failure.
    await waitFor(() =>
      expect(screen.queryByRole("radio")).not.toBeInTheDocument(),
    );
    // Nothing was voided: exactly one attempt, no success toast.
    expect(apiFetchMock).toHaveBeenCalledTimes(1);
    expect(toast.success).not.toHaveBeenCalled();
  });

  it("409 ITEM_STATUS_NOT_VOIDABLE without a statuses array degrades to 'pending'", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(409, { code: "ITEM_STATUS_NOT_VOIDABLE" }),
    );
    renderHost({ reasons: REASONS });
    pickAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith(
      t("pos.toast.void_item.status_not_voidable", { statuses: "pending" }),
    );
  });

  it("422 VOID_REASON_INVALID → 'pick again' toast", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(422, {
        code: "VOID_REASON_INVALID",
        message: "The selected void reason is invalid.",
      }),
    );
    renderHost({ reasons: REASONS });
    pickAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith(
      t("pos.toast.void_item.reason_invalid"),
    );
  });

  it("422 VOID_NOTE_REQUIRED → note-required toast (server-side requires_note flip)", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(422, {
        code: "VOID_NOTE_REQUIRED",
        message: "The void reason field is required.",
        errors: { void_reason: ["The void reason field is required."] },
      }),
    );
    renderHost({ reasons: REASONS });
    pickAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith(
      t("pos.toast.void_item.note_required"),
    );
  });

  it("an uncoded 500 falls through to the generic envelope message", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(500, { message: "Server Error" }),
    );
    renderHost({ reasons: REASONS });
    pickAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith("Server Error");
  });

  it("a transport failure (workstation unreachable) surfaces the raw message", async () => {
    apiFetchMock.mockRejectedValue(new Error("Failed to fetch"));
    renderHost({ reasons: REASONS });
    pickAndSubmit();

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
    expect(toast.error).toHaveBeenCalledWith("Failed to fetch");
  });
});
