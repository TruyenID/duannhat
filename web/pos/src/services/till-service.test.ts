import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { tillService } from "./till-service";

// plan-044 R2 — service surface for the gap-preview / order-summary reads and the
// gap-claim keys on the open payload. Mocks global.fetch so real apiFetch runs.

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function mockOk(body: unknown = { data: {} }): void {
  fetchMock.mockResolvedValueOnce({
    ok: true,
    status: 200,
    json: async () => body,
  } as Response);
}

function lastCall(): { url: string; init: RequestInit } {
  const [url, init] = fetchMock.mock.calls[0];
  return { url: String(url), init: init as RequestInit };
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  // Clear the no-token fail-fast guard on /pos/* calls (#472).
  localStorage.setItem("pos_device_token", "test-token");
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("tillService.gapPreview", () => {
  it("GETs /pos/till/gap-preview", async () => {
    mockOk({ data: { payments: [], totals: { count: 0 } } });
    await tillService.gapPreview();
    const { url, init } = lastCall();
    expect(url).toContain("/api/v1/pos/till/gap-preview");
    expect(init?.method ?? "GET").toBe("GET");
  });
});

describe("tillService.unresolvedOrders", () => {
  it("GETs /pos/till/unresolved-orders", async () => {
    mockOk({ data: { orders: [], totals: { count: 0 } } });
    await tillService.unresolvedOrders();
    const { url, init } = lastCall();
    expect(url).toContain("/api/v1/pos/till/unresolved-orders");
    expect(init?.method ?? "GET").toBe("GET");
  });
});

describe("tillService.orderSummary", () => {
  it("GETs /pos/till/sessions/{id}/order-summary", async () => {
    mockOk({ data: { paid_orders_count: 0 } });
    await tillService.orderSummary("sess-9");
    const { url, init } = lastCall();
    expect(url).toContain("/api/v1/pos/till/sessions/sess-9/order-summary");
    expect(init?.method ?? "GET").toBe("GET");
  });
});

describe("tillService.open — gap-claim wiring", () => {
  it("POSTs the claimed ids + held-separately ack in the body", async () => {
    mockOk({ data: { id: "new-session" } });
    await tillService.open({
      opening_counts: [{ denomination_id: "d1000", quantity: 5 }],
      claimed_gap_payment_ids: ["pay-cash", "pay-card"],
      gap_cash_held_separately_ack: true,
    });
    const { url, init } = lastCall();
    expect(url).toContain("/api/v1/pos/till/sessions");
    expect(init.method).toBe("POST");
    const body = JSON.parse(String(init.body));
    expect(body.claimed_gap_payment_ids).toEqual(["pay-cash", "pay-card"]);
    expect(body.gap_cash_held_separately_ack).toBe(true);
  });

  it("omits the gap-claim keys when nothing is claimed", async () => {
    mockOk({ data: { id: "new-session" } });
    await tillService.open({
      opening_counts: [{ denomination_id: "d1000", quantity: 5 }],
    });
    const body = JSON.parse(String(lastCall().init.body));
    expect(body.claimed_gap_payment_ids).toBeUndefined();
    expect(body.gap_cash_held_separately_ack).toBeUndefined();
  });
});

/*
 * #284 — ghim ĐỘNG TỪ + ĐƯỜNG DẪN của các lời gọi ĐỔI TIỀN.
 *
 * Đây không phải test cho có coverage. Mỗi method dưới đây là một dòng gọi
 * `apiFetch`, nên thứ duy nhất có thể sai là chuỗi URL hoặc method — và ở đúng
 * nhóm này, sai một chuỗi là sai TIỀN chứ không phải sai màn hình.
 *
 * Rủi ro cụ thể: `handover` được ghi trong code là "same payload shape as
 * close", tức nó SINH RA TỪ việc chép `close`. Chép mà quên đổi đuôi URL thì
 * một lần bàn giao ca sẽ chạy đường ĐÓNG CHUỖI — chốt sổ cả chuỗi ca thay vì
 * chuyển ca — và giao diện không có cách nào biết.
 */
describe("#284 đường tiền: động từ + đường dẫn", () => {
  const cases: Array<{
    name: string;
    run: () => Promise<unknown>;
    method: string;
    path: string;
  }> = [
    {
      name: "open",
      run: () => tillService.open({ opening_float: 0 } as never),
      method: "POST",
      path: "/api/v1/pos/till/sessions",
    },
    {
      name: "cashEvent",
      run: () => tillService.cashEvent("s1", { type: "cash_in" } as never),
      method: "POST",
      path: "/api/v1/pos/till/sessions/s1/cash-events",
    },
    {
      name: "saveDraft",
      run: () => tillService.saveDraft("s1", {} as never),
      method: "PATCH",
      path: "/api/v1/pos/till/sessions/s1/draft",
    },
    {
      name: "close",
      run: () => tillService.close("s1", {} as never),
      method: "POST",
      path: "/api/v1/pos/till/sessions/s1/close",
    },
    {
      name: "handover",
      run: () => tillService.handover("s1", {} as never),
      method: "POST",
      path: "/api/v1/pos/till/sessions/s1/handover",
    },
    {
      name: "abandon",
      run: () => tillService.abandon("s1", "hết ca"),
      method: "POST",
      path: "/api/v1/pos/till/sessions/s1/abandon",
    },
  ];

  it.each(cases)("$name → $method $path", async ({ run, method, path }) => {
    mockOk({ data: {} });
    await run();
    const { url, init } = lastCall();
    expect(url).toContain(path);
    expect(init?.method).toBe(method);
  });

  it("close và handover KHÔNG trỏ cùng một đường", async () => {
    // Chính là phép đo bắt được lần chép hụt: hai method cùng payload, khác
    // đúng một từ trong URL, và hậu quả là chốt sổ cả chuỗi ca thay vì chuyển ca.
    mockOk({ data: {} });
    await tillService.close("s1", {} as never);
    const closeUrl = lastCall().url;

    fetchMock.mockReset();
    mockOk({ data: {} });
    await tillService.handover("s1", {} as never);
    const handoverUrl = lastCall().url;

    expect(closeUrl).not.toBe(handoverUrl);
    expect(closeUrl).toMatch(/\/close$/);
    expect(handoverUrl).toMatch(/\/handover$/);
  });

  it("abandon gửi lý do trong thân, không nhét vào URL", async () => {
    mockOk({ data: {} });
    await tillService.abandon("s1", "quản lý huỷ ca");
    const { url, init } = lastCall();
    expect(url).not.toContain("quản");
    expect(JSON.parse(String(init.body))).toEqual({
      abandon_reason: "quản lý huỷ ca",
    });
  });

  it("abandon không có lý do gửi NULL tường minh, không bỏ trống khoá", async () => {
    // Khoá vắng mặt và khoá null là hai thứ khác nhau ở phía backend: một cái
    // là "không đổi", cái kia là "xoá lý do".
    mockOk({ data: {} });
    await tillService.abandon("s1");
    expect(JSON.parse(String(lastCall().init.body))).toEqual({
      abandon_reason: null,
    });
  });
});
