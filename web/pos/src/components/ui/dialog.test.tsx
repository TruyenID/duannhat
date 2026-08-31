/**
 * #1738 — per-dialog error boundary, behaviour.
 *
 * The point of the boundary is a survival property, so every test here asserts
 * on what is STILL on screen after the crash, not just on the fallback card.
 */

import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { Dialog, DialogTitle } from "@godxjp/ui";
import { AppProvider } from "@/providers/app-provider";
import { LOCALE_STORAGE_KEY } from "@/i18n";
import { DialogContent } from "./dialog";

vi.mock("@/lib/sentry", () => ({
  captureException: vi.fn(),
  captureMessage: vi.fn(),
  addBreadcrumb: vi.fn(),
  initSentry: vi.fn(),
}));

import { captureException } from "@/lib/sentry";

function Boom({ explode }: { explode: boolean }): React.ReactElement {
  if (explode) throw new Error("dialog body exploded");
  return <div data-testid="dialog-body">body</div>;
}

/** The order screen a cashier is mid-way through — must survive the crash. */
function OrderScreen({ children }: { children: React.ReactNode }) {
  return (
    <AppProvider>
      <div data-testid="order-screen">ORD-0007</div>
      {children}
    </AppProvider>
  );
}

let consoleError: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  localStorage.setItem(LOCALE_STORAGE_KEY, "vi");
  // React logs the caught error itself; keep the run readable.
  consoleError = vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
  consoleError.mockRestore();
  vi.clearAllMocks();
});

describe("DialogContent boundary", () => {
  it("renders children untouched when nothing throws", () => {
    render(
      <OrderScreen>
        <Dialog open>
          <DialogContent>
            <DialogTitle>Thanh toán</DialogTitle>
            <Boom explode={false} />
          </DialogContent>
        </Dialog>
      </OrderScreen>,
    );

    expect(screen.getByTestId("dialog-body")).toBeInTheDocument();
    expect(screen.queryByText("Hộp thoại gặp lỗi")).not.toBeInTheDocument();
  });

  it("adds no DOM wrapper around the body — the 22 dialogs' layout is unchanged", () => {
    // DialogContent's children are laid out as its direct flex/grid children.
    // An extra <div> from the boundary would silently reflow every dialog.
    render(
      <OrderScreen>
        <Dialog open>
          <DialogContent>
            <DialogTitle>Thanh toán</DialogTitle>
            <Boom explode={false} />
          </DialogContent>
        </Dialog>
      </OrderScreen>,
    );

    const body = screen.getByTestId("dialog-body");
    expect(body.parentElement).toBe(screen.getByRole("dialog"));
  });

  it("contains a render crash: dialog shows the fallback, order screen survives", () => {
    render(
      <OrderScreen>
        <Dialog open>
          <DialogContent>
            <DialogTitle>Thanh toán</DialogTitle>
            <Boom explode />
          </DialogContent>
        </Dialog>
      </OrderScreen>,
    );

    // The order the cashier was working on is still on screen — this is the
    // whole point; the app-level boundary in main.tsx would have unmounted it.
    expect(screen.getByTestId("order-screen")).toBeInTheDocument();
    // And the modal chrome survived too, so the dialog is still dismissible.
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getByText("Hộp thoại gặp lỗi")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Thử lại" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Đóng" })).toBeInTheDocument();
  });

  it("reports the crash to Sentry tagged as a dialog boundary", () => {
    render(
      <OrderScreen>
        <Dialog open>
          <DialogContent>
            <DialogTitle>Thanh toán</DialogTitle>
            <Boom explode />
          </DialogContent>
        </Dialog>
      </OrderScreen>,
    );

    expect(captureException).toHaveBeenCalledWith(
      expect.objectContaining({ message: "dialog body exploded" }),
      expect.objectContaining({ tags: { boundary: "dialog" } }),
    );
  });

  it("retry re-renders the body once the cause is gone", () => {
    function Harness() {
      const [explode, setExplode] = useState(true);
      return (
        <OrderScreen>
          <button onClick={() => setExplode(false)}>defuse</button>
          <Dialog open>
            <DialogContent>
              <DialogTitle>Thanh toán</DialogTitle>
              <Boom explode={explode} />
            </DialogContent>
          </Dialog>
        </OrderScreen>
      );
    }

    render(<Harness />);
    expect(screen.getByText("Hộp thoại gặp lỗi")).toBeInTheDocument();

    fireEvent.click(screen.getByText("defuse"));
    fireEvent.click(screen.getByRole("button", { name: "Thử lại" }));

    expect(screen.getByTestId("dialog-body")).toBeInTheDocument();
    expect(screen.queryByText("Hộp thoại gặp lỗi")).not.toBeInTheDocument();
  });

  it("close button dismisses the crashed dialog without touching the order", () => {
    function Harness() {
      const [open, setOpen] = useState(true);
      return (
        <OrderScreen>
          <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent>
              <DialogTitle>Thanh toán</DialogTitle>
              <Boom explode />
            </DialogContent>
          </Dialog>
        </OrderScreen>
      );
    }

    render(<Harness />);
    fireEvent.click(screen.getByRole("button", { name: "Đóng" }));

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(screen.getByTestId("order-screen")).toBeInTheDocument();
  });
});
