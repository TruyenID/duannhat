import { describe, expect, it } from "vitest";
import type { ReactNode } from "react";
import { render, screen } from "@testing-library/react";

import { AppProvider } from "@/providers/app-provider";
import { RedInvoicePrintedBadge } from "./red-invoice-printed-badge";
import type { PrintKindCounts } from "@/services/workstation-print-service";

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

// Test locale is DEFAULT_LOCALE = "ja" → pos.red_invoice.printed_count.
const label = (n: number) => `印刷済 ×${n}`;

const counts: PrintKindCounts = {
  printed: true,
  order_scope: { count: 1 },
  by_payment: [
    { payment_id: "pay-a", count: 2 },
    { payment_id: "pay-b", count: 0 },
  ],
};

describe("RedInvoicePrintedBadge", () => {
  it("shows the count for the named payer", () => {
    render(<RedInvoicePrintedBadge counts={counts} paymentId="pay-a" />, {
      wrapper: Wrapper,
    });
    expect(screen.getByText(label(2))).toBeInTheDocument();
  });

  // The reason the badge is per-payer at all: on a split bill one guest can
  // have paper while the next has none, and an order-level badge would smear
  // that into "someone here was printed".
  it("stays silent for a payer with no paper, even when another guest has two", () => {
    render(<RedInvoicePrintedBadge counts={counts} paymentId="pay-b" />, {
      wrapper: Wrapper,
    });
    expect(screen.queryByText(label(2))).not.toBeInTheDocument();
    expect(screen.queryByText(label(0))).not.toBeInTheDocument();
  });

  it("uses the whole-order tally when no payer is named", () => {
    render(<RedInvoicePrintedBadge counts={counts} />, { wrapper: Wrapper });
    expect(screen.getByText(label(1))).toBeInTheDocument();
  });

  // A workstation older than #1875 sends no tally. Rendering a confident
  // nothing is right; rendering "chưa in" would be a claim we cannot support.
  it("renders nothing when the workstation reported no counts", () => {
    const { container } = render(
      <RedInvoicePrintedBadge counts={undefined} paymentId="pay-a" />,
      { wrapper: Wrapper },
    );
    expect(container).toBeEmptyDOMElement();
  });
});
