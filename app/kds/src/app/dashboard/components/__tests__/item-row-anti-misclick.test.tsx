import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { ItemRow } from "../item-row";
import { I18nProvider } from "@/i18n";
import { setDeviceToken } from "@/services/auth/device-token";
import { makeItem } from "@/test/fixtures/kds";

// Use the REAL bump hooks here (unlike item-row.test.tsx which mocks them) so
// the shared `useIsMutating(BUMP_MUTATION_KEY)` gate is exercised end-to-end.
vi.mock("@/services/realtime/dispatcher", () => ({
  createRealtimeDispatcher: vi.fn(() => ({
    on: vi.fn(() => () => {}),
    connect: vi.fn(),
    close: vi.fn(),
  })),
}));

vi.mock("@/providers/use-realtime", () => ({
  useRealtime: () => ({ mode: "lan" as const, isConnected: true, recordBumpKey: vi.fn() }),
}));

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
  setDeviceToken("tok", {
    id: "d-1",
    name: "KDS-1",
    type: "kds",
    status: "active",
    branch_id: "b-1",
  });
});

function wrap(children: React.ReactNode) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
  });
  return (
    <I18nProvider>
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    </I18nProvider>
  );
}

describe("ItemRow shared anti-misclick guard", () => {
  it("disables another item's forward button while a bump is in flight", async () => {
    // Never-resolving fetch keeps the first bump pending.
    global.fetch = vi.fn().mockImplementation(() => new Promise(() => {}));

    const a = makeItem({ id: "i-a", status: "pending", allowed_transitions: ["mark-preparing"] });
    const b = makeItem({ id: "i-b", status: "pending", allowed_transitions: ["mark-preparing"] });

    render(
      wrap(
        <>
          <ItemRow orderId="o-1" item={a} />
          <ItemRow orderId="o-1" item={b} />
        </>,
      ),
    );

    const forwardA = screen.getByTestId("item-i-a-forward") as HTMLButtonElement;
    const forwardB = screen.getByTestId("item-i-b-forward") as HTMLButtonElement;
    expect(forwardA.disabled).toBe(false);
    expect(forwardB.disabled).toBe(false);

    fireEvent.click(forwardA);

    // Both buttons gate together via the shared BUMP_MUTATION_KEY, even though
    // only item A's mutation is running.
    await waitFor(() => expect(forwardB.disabled).toBe(true));
    expect(forwardA.disabled).toBe(true);
  });
});
