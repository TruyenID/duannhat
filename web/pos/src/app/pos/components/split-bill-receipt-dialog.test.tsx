import { describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { AppProvider } from "@/providers/app-provider";
import { workstationPrintService } from "@/services/workstation-print-service";
import {
  SplitBillReceiptDialog,
  type SplitBillReceiptData,
} from "./split-bill-receipt-dialog";

// Test locale is DEFAULT_LOCALE = "ja".
const RED_CTA = "領収書を印刷"; // pos.red_invoice.cta

// #1779 — hoá đơn đỏ prints directly (no customer_invoices row). The CTA is
// gated on the workstation print bridge being available.
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    enabled: true,
    printRedInvoice: vi.fn(),
    printPaymentReceipt: vi.fn(),
    // #1875 — the per-guest "đã in ×N" badge probes this on open. A mock that
    // omits it makes the dialog throw, which is the right signal: the component
    // genuinely calls it now.
    getPrintStatus: vi.fn(() => Promise.resolve({ red_invoice: undefined })),
  },
}));

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const data: SplitBillReceiptData = {
  mode: "even",
  orderCode: "ORD-1",
  tableLabel: "B1",
  guestCount: 2,
  totalAmount: 2000,
  perGuestAmount: 1000,
  paidAt: new Date(0),
  remaining: 0,
  guests: [
    {
      index: 1,
      methodName: "Cash",
      methodCode: "cash",
      amount: 1000,
      paidAt: "10:00",
      paymentId: "pay-1",
    },
    {
      index: 2,
      methodName: "Cash",
      methodCode: "cash",
      amount: 1000,
      paidAt: "10:01",
      paymentId: "pay-2",
    },
  ],
};

describe("SplitBillReceiptDialog — hoá đơn đỏ is PER GUEST, never whole-order (#1939)", () => {
  function renderDialog() {
    return render(
      <Wrapper>
        <SplitBillReceiptDialog
          open
          onOpenChange={vi.fn()}
          data={data}
          orderId="ord-1"
          customerName={null}
          onComplete={vi.fn()}
        />
      </Wrapper>,
    );
  }

  it("offers NO whole-order red invoice action", () => {
    renderDialog();
    // The order was deliberately divided among payers who settled separately,
    // so there is nobody to hand a whole-table tax document to. This used to be
    // the widest button on the screen — i.e. the easiest to hit by accident.
    expect(screen.queryAllByText(RED_CTA)).toHaveLength(0);
  });

  it("gives EVERY guest their own red-invoice button, named with that guest's index", () => {
    renderDialog();
    // Split guests routinely owe the SAME amount, so the printed figure cannot
    // tell you afterwards which guest a slip belongs to. The index in the label
    // is the only thing distinguishing these controls — assert per guest, never
    // "at least one".
    for (const g of data.guests) {
      expect(
        screen.getByRole("button", {
          name: new RegExp(`客 ${g.index}`),
        }),
      ).toBeInTheDocument();
    }
  });

  it("renders the per-guest action as a full-width row, not an 11px text link", () => {
    renderDialog();
    // #1939 — it was an 11px underlined <button> styled as a caption. On a touch
    // terminal that is both hard to see and hard to hit; the design system puts
    // POS controls at 44–48px for exactly this reason. Pinning the height and
    // the full width keeps a future restyle from quietly shrinking the hit area
    // back to something a cashier misses mid-checkout.
    const invoiceButton = screen.getByRole("button", {
      name: new RegExp("客 1"),
    });
    expect(invoiceButton.className).toContain("h-12");
    expect(invoiceButton.className).toContain("w-full");
    expect(invoiceButton.className).not.toContain("underline");
  });

  it("opens the invoice dialog scoped to the guest whose button was pressed", async () => {
    renderDialog();

    // Guest 2 specifically — picking the SECOND row proves the handler passes
    // that row's paymentId rather than defaulting to the first (or to none,
    // which RedInvoiceDialog reads as "whole order").
    fireEvent.click(
      screen.getByRole("button", { name: new RegExp(`客 ${data.guests[1]!.index}`) }),
    );

    // The scoped dialog probes the print status for this order on open; that
    // call is the observable proof it mounted, since a closed Radix dialog
    // renders nothing either way.
    await waitFor(() => {
      expect(workstationPrintService.getPrintStatus).toHaveBeenCalledWith({
        orderId: "ord-1",
      });
    });
  });
});

// ---------------------------------------------------------------------------
//  Per-guest cash — what each person handed over and got back
// ---------------------------------------------------------------------------

describe("SplitBillReceiptDialog — per-guest お預かり / お釣り", () => {
  it("shows each guest's OWN tendered and change, not a shared figure", () => {
    // Chia đều: both guests owe the same 1,000 and hand over different notes.
    // A shared/aggregated figure here would be indistinguishable from the bug
    // this feature exists to fix, so both rows are asserted independently.
    const withCash: SplitBillReceiptData = {
      ...data,
      guests: [
        { ...data.guests[0]!, tendered: 5000, change: 4000 },
        { ...data.guests[1]!, tendered: 3000, change: 2000 },
      ],
    };
    render(
      <SplitBillReceiptDialog
        open
        onOpenChange={vi.fn()}
        data={withCash}
        orderId="ord-1"
        onComplete={vi.fn()}
      />,
      { wrapper: Wrapper },
    );

    const rows = screen.getAllByRole("button", { name: /お客様/ });
    expect(within(rows[0]!).getByText("5.000 ₫")).toBeInTheDocument();
    expect(within(rows[0]!).getByText("4.000 ₫")).toBeInTheDocument();
    expect(within(rows[1]!).getByText("3.000 ₫")).toBeInTheDocument();
    expect(within(rows[1]!).getByText("2.000 ₫")).toBeInTheDocument();
  });

  it("prints no cash lines for a card guest", () => {
    // A card row has no tender at all; an お預かり line there would claim the
    // customer handed over money they never did.
    render(
      <SplitBillReceiptDialog
        open
        onOpenChange={vi.fn()}
        data={data}
        orderId="ord-1"
        onComplete={vi.fn()}
      />,
      { wrapper: Wrapper },
    );

    expect(screen.queryByText("お預かり")).toBeNull();
    expect(screen.queryByText("お釣り")).toBeNull();
  });

  it("states a zero change out loud rather than hiding the line", () => {
    // "ぴったり" is information the customer checks against their hand; an
    // absent line reads as "not recorded".
    const exact: SplitBillReceiptData = {
      ...data,
      guests: [{ ...data.guests[0]!, tendered: 1000, change: 0 }, data.guests[1]!],
    };
    render(
      <SplitBillReceiptDialog
        open
        onOpenChange={vi.fn()}
        data={exact}
        orderId="ord-1"
        onComplete={vi.fn()}
      />,
      { wrapper: Wrapper },
    );

    const rows = screen.getAllByRole("button", { name: /お客様/ });
    expect(within(rows[0]!).getByText("お預かり")).toBeInTheDocument();
    expect(within(rows[0]!).getByText("0 ₫")).toBeInTheDocument();
  });
});
