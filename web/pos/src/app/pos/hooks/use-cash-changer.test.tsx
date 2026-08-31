import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, act, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  customerKeys,
  orderKeys,
  orderPaymentKeys,
  tableKeys,
} from "@/hooks/api/query-keys";
import { ApiError } from "@/lib/api";

const svc = vi.hoisted(() => ({
  enabled: true,
  start: vi.fn(),
  status: vi.fn(),
  cancel: vi.fn(),
}));

vi.mock("@/services/workstation-cash-changer-service", () => ({
  workstationCashChangerService: svc,
}));

import { useCashChanger } from "./use-cash-changer";

const SHOP = "shop-1";

function wrapper(client: QueryClient) {
  return function W({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

function snap(over: Partial<Record<string, unknown>> = {}) {
  return {
    session_id: "s1",
    order_id: "o1",
    running: false,
    status: "finish",
    payment_id: "pay-1",
    total: 1200,
    tendered: 2000,
    change: 800,
    error: "",
    ...over,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  svc.enabled = true;
});
afterEach(() => vi.useRealTimers());

describe("useCashChanger", () => {
  // The workstation's RecordCashPayment is "the single writer of the payments
  // table for the cash-changer flow". The POS must refetch, never post — a
  // payment created here would double-charge the customer.
  it("on finish it INVALIDATES the order queries and creates no payment", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockResolvedValue(snap());

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });

    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith({ queryKey: orderKeys.all(SHOP) }),
    );
    // The service exposes no "create payment" call at all, and none is invented here.
    expect(Object.keys(svc)).not.toContain("createPayment");
    await waitFor(() => expect(result.current.session?.payment_id).toBe("pay-1"));
  });

  // #2535 B12 / #2582 vòng 2 — bộ invalidate là CHÍNH bản vá tươi-UI-tiền, và
  // bài trên chỉ assert MỘT khoá cũ (`orderKeys.all`). Xoá `tableKeys.all` đi
  // thì bug B12 quay lại nguyên vẹn — thẻ bàn kẹt ở trạng thái trước-thanh-toán
  // sau khi đã thu tiền thật — mà cả 37 test vẫn xanh. `tsc` không cứu: nó kiểm
  // khoá có TYPE đúng không, không kiểm khoá NÀO được bắn.
  //
  // Khách CÓ ĐỊNH DANH, vì `customerKeys.outstanding` chỉ bắn khi có chủ đơn;
  // ca khách lẻ nằm ở bài ngay dưới.
  it("on finish it invalidates ALL FIVE key families (#2535 B12)", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockResolvedValue(snap());

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useCashChanger(SHOP, "cus-7"), {
      wrapper: wrapper(client),
    });

    await act(async () => {
      await result.current.start("o1");
    });

    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith({ queryKey: orderKeys.all(SHOP) }),
    );
    // `order_id` phải lấy từ SNAPSHOT, không phải từ tham số `start()` — đó là
    // thứ giữ cho đường này đúng khi máy trả về một đơn khác với đơn vừa mở.
    expect(spy).toHaveBeenCalledWith({ queryKey: orderKeys.detail(SHOP, "o1") });
    expect(spy).toHaveBeenCalledWith({ queryKey: tableKeys.all(SHOP) });
    expect(spy).toHaveBeenCalledWith({ queryKey: orderPaymentKeys.list(SHOP, "o1") });
    expect(spy).toHaveBeenCalledWith({
      queryKey: customerKeys.outstanding(SHOP, "cus-7"),
    });
  });

  // Khách lẻ: không có ai để làm tươi dư nợ. Bắn khoá `outstanding` với một id
  // rỗng là một truy vấn vô nghĩa, nên hook cố ý KHÔNG bắn.
  it("does not invalidate customer outstanding for a walk-in order", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockResolvedValue(snap());

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });

    // Mốc đồng bộ là `orderKeys.all` — khoá LUÔN được bắn, không liên quan tới
    // thứ bài này đang đo. Dùng `tableKeys.all` làm mốc thì bài này đỏ theo mỗi
    // khi ai đó đụng khoá bàn, tức đỏ vì lý do của bài KHÁC.
    await waitFor(() =>
      expect(spy).toHaveBeenCalledWith({ queryKey: orderKeys.all(SHOP) }),
    );
    const outstandingCalls = spy.mock.calls.filter(([arg]) =>
      JSON.stringify((arg as { queryKey: unknown }).queryKey).includes("outstanding"),
    );
    expect(outstandingCalls).toHaveLength(0);
  });

  // timeout = the machine KEPT the cash. The snapshot must stay on screen so the
  // cashier is told, rather than a dialog closing itself and leaving them to infer.
  it("keeps the terminal snapshot for timeout and does NOT invalidate", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockResolvedValue(snap({ status: "timeout", payment_id: "", error: "timeout" }));

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const spy = vi.spyOn(client, "invalidateQueries");
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });

    await waitFor(() => expect(result.current.session?.status).toBe("timeout"));
    expect(spy).not.toHaveBeenCalled();
  });

  it("surfaces a start failure instead of pretending a collection is running", async () => {
    svc.start.mockRejectedValue(new Error("machine busy"));

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });

    expect(result.current.error).toBe("machine busy");
    expect(result.current.session).toBeNull();
  });

  // Cancelling asks the machine to return the cash; it does not make it so. The
  // authoritative outcome arrives through the poll as a terminal `cancel`.
  it("cancel does not declare the session cancelled by itself", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockResolvedValue(snap({ running: true, status: "beginDeposit", payment_id: "" }));
    svc.cancel.mockResolvedValue({ session_id: "s1", canceling: true });

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });
    await act(async () => {
      await result.current.cancel();
    });

    expect(svc.cancel).toHaveBeenCalledWith("s1");
    expect(result.current.session?.status).not.toBe("cancel");
    act(() => result.current.dismiss());
  });

  it("reports unavailable when no workstation is paired", () => {
    svc.enabled = false;
    const client = new QueryClient();
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });
    expect(result.current.available).toBe(false);
  });
});

// ── #1810 — the poll used to retry EVERY error for ever, on the stated
//    assumption that "the workstation keeps the session". The session is
//    in-memory on the workstation, so a restart mid-collection turns that into
//    an endless 1s loop against a 404 while the cashier watches a spinner that
//    can never end and the cash may be sitting inside the machine. ────────────
describe("useCashChanger — poll gives up on a permanent error (#1810)", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it("stops polling on 404 and reports the outcome as UNKNOWN, not cancelled", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockRejectedValue(new ApiError(404, { message: "session not found" }));

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });

    const callsAfterGiveUp = svc.status.mock.calls.length;
    expect(result.current.outcomeUnknown).toBe(true);
    expect(result.current.session?.running).toBe(false);

    // And it must stay stopped — no timer left running.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000);
    });
    expect(svc.status.mock.calls.length).toBe(callsAfterGiveUp);
  });

  it("keeps polling through a LAN blip — ApiError(0) is transient", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockRejectedValue(new ApiError(0, { message: "workstation unreachable" }));

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });
    const afterFirst = svc.status.mock.calls.length;

    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000);
    });

    expect(svc.status.mock.calls.length).toBeGreaterThan(afterFirst);
    expect(result.current.outcomeUnknown).toBe(false);
  });

  it("still gives up if a transient error never clears — bounded, not endless", async () => {
    svc.start.mockResolvedValue({ session_id: "s1", order_id: "o1", total: 1200 });
    svc.status.mockRejectedValue(new ApiError(0, { message: "workstation unreachable" }));

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useCashChanger(SHOP), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.start("o1");
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000);
    });

    expect(result.current.outcomeUnknown).toBe(true);
    const settled = svc.status.mock.calls.length;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000);
    });
    expect(svc.status.mock.calls.length).toBe(settled);
  });
});
