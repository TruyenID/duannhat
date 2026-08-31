import { beforeEach, describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { ServiceChargeRow } from "./order-cart";

// plan-043 — the service-charge line shows a 税込 headline (¥50 = ¥45 net + ¥5
// tax) and, when the service carries tax, a collapsible breakdown under a
// clickable "Phí phục vụ" label. Force the vi locale (the vitest env has no
// VITE_DEFAULT_LOCALE, so AppProvider would otherwise default to ja) so the
// labels are "Phí phục vụ" / "Trước thuế" / "Thuế".
beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

describe("ServiceChargeRow", () => {
  it("defaults to expanded: shows the pre-tax + tax breakdown rows", () => {
    render(<ServiceChargeRow gross={50} net={45} tax={5} />, {
      wrapper: Wrapper,
    });
    // Headline label + both breakdown sub-labels are visible.
    expect(screen.getByText("Phí phục vụ")).toBeTruthy();
    expect(screen.getByText("Trước thuế")).toBeTruthy();
    expect(screen.getByText("Thuế")).toBeTruthy();
  });

  it("clicking the label collapses, clicking again expands the breakdown", () => {
    render(<ServiceChargeRow gross={50} net={45} tax={5} />, {
      wrapper: Wrapper,
    });
    const toggle = screen.getByRole("button");
    expect(toggle.getAttribute("aria-expanded")).toBe("true");

    // Collapse — the breakdown rows unmount.
    fireEvent.click(toggle);
    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByText("Trước thuế")).toBeNull();
    expect(screen.queryByText("Thuế")).toBeNull();
    // Headline stays.
    expect(screen.getByText("Phí phục vụ")).toBeTruthy();

    // Expand again.
    fireEvent.click(toggle);
    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(screen.getByText("Trước thuế")).toBeTruthy();
  });

  it("no tax → no toggle, no breakdown (plain row)", () => {
    render(<ServiceChargeRow gross={45} net={45} tax={0} />, {
      wrapper: Wrapper,
    });
    // Not collapsible: the label is not a button and no breakdown renders.
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.queryByText("Trước thuế")).toBeNull();
    expect(screen.getByText("Phí phục vụ")).toBeTruthy();
  });
});
