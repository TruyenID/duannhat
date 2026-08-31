import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@/lib/api";

const resolver = vi.hoisted(() => ({
  hasWorkstation: vi.fn(() => true),
  getWorkstationUrl: vi.fn(() => "http://192.168.1.50:6969/"),
}));

vi.mock("./workstation/base-url-resolver", () => resolver);
vi.mock("@/lib/auth", () => ({ getToken: () => "tok" }));

import {
  workstationCashChangerService as svc,
  cashCollectedAndRecorded,
  cashCollectedButNotRecorded,
  cashRetainedByMachine,
  type CashChangerSession,
} from "./workstation-cash-changer-service";

function okResponse(data: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => ({ data }),
  } as unknown as Response;
}

beforeEach(() => {
  vi.clearAllMocks();
  resolver.hasWorkstation.mockReturnValue(true);
  resolver.getWorkstationUrl.mockReturnValue("http://192.168.1.50:6969/");
});

describe("workstationCashChangerService", () => {
  it("is disabled when no workstation is paired, so callers can hide their UI", () => {
    resolver.hasWorkstation.mockReturnValue(false);
    expect(svc.enabled).toBe(false);
  });

  // The amount is deliberately absent: the workstation reads the order total
  // server-side, because a client-asserted amount for cash a machine physically
  // counts is a number nobody can check afterwards.
  it("starts a collection with ONLY the order id — never an amount", async () => {
    const fetchMock = vi.fn(async () => okResponse({ session_id: "s1", order_id: "o1", total: 1200 }));
    vi.stubGlobal("fetch", fetchMock);

    const started = await svc.start("o1");

    expect(started.session_id).toBe("s1");
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://192.168.1.50:6969/api/v1/pos/cash-changer/collect");
    const body = JSON.parse(init.body as string);
    expect(body).toEqual({ order_id: "o1" });
    expect(Object.keys(body)).not.toContain("total");
    expect(Object.keys(body)).not.toContain("amount");
    vi.unstubAllGlobals();
  });

  it("forwards canonical split audit context with one machine-collected share", async () => {
    const fetchMock = vi.fn(async () =>
      okResponse({ session_id: "s-split", order_id: "o1", total: 1000 })
    );
    vi.stubGlobal("fetch", fetchMock);

    await svc.start("o1", 1000, {
      split_mode: "by_amount",
      bill_index: 1,
      total_bills: 3,
      label: "Guest 2",
      amount: 1000,
    });

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(init.body as string)).toEqual({
      order_id: "o1",
      amount: 1000,
      metadata: {
        split_mode: "by_amount",
        bill_index: 1,
        total_bills: 3,
        label: "Guest 2",
        amount: 1000,
      },
    });
    vi.unstubAllGlobals();
  });

  it("surfaces a machine-busy 409 as ApiError, not as a silent no-op", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({
      ok: false,
      status: 409,
      json: async () => ({ message: "machine busy" }),
    }) as unknown as Response));

    await expect(svc.start("o1")).rejects.toBeInstanceOf(ApiError);
    vi.unstubAllGlobals();
  });

  it("turns a LAN fetch failure into ApiError(0) so callers can say 'workstation off'", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => { throw new TypeError("network"); }));

    await expect(svc.status("s1")).rejects.toMatchObject({ status: 0 });
    vi.unstubAllGlobals();
  });

  it("never falls back to Cloud — with no workstation it refuses instead of guessing a host", async () => {
    resolver.hasWorkstation.mockReturnValue(false);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    await expect(svc.start("o1")).rejects.toMatchObject({ status: 0 });
    expect(fetchMock).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});

// The distinction that costs real money if collapsed: `cancel` returns the
// deposited cash, `timeout` KEEPS it. Showing them as one generic failure tells
// a cashier the customer has their money back when the machine still holds it.
describe("cashRetainedByMachine", () => {
  it("says the machine still holds the cash for timeout / abort / failure", () => {
    expect(cashRetainedByMachine("timeout")).toBe(true);
    expect(cashRetainedByMachine("abort")).toBe(true);
    expect(cashRetainedByMachine("failure")).toBe(true);
  });

  it("says it does NOT for cancel (cash returned) or finish (money taken)", () => {
    expect(cashRetainedByMachine("cancel")).toBe(false);
    expect(cashRetainedByMachine("finish")).toBe(false);
  });
});

function session(over: Partial<CashChangerSession> = {}): CashChangerSession {
  return {
    session_id: "s-1",
    order_id: "o-1",
    running: false,
    status: "finish",
    payment_id: "pay-1",
    total: 1000,
    tendered: 5000,
    change: 4000,
    error: "",
    ...over,
  };
}

describe("#2535 B3 — 'finish' một mình KHÔNG phải là thành công", () => {
  it("thu xong và ghi được sổ ⇒ thành công", () => {
    expect(cashCollectedAndRecorded(session())).toBe(true);
    expect(cashCollectedButNotRecorded(session())).toBe(false);
  });

  it("ĐÃ THU nhưng payment_id rỗng ⇒ KHÔNG phải thành công", () => {
    // Đây là hình dạng snapshot thật khi `RecordCashPayment` lỗi: workstation
    // vẫn trả `status: "finish"` vì máy đã thu tiền và thối tiền xong. Trước
    // #2535 màn hình đọc đúng hai chữ "Đã thu xong" trong khi sổ trống trơn.
    const s = session({ payment_id: "", error: "cash collected (glory txn T9) but recording failed" });

    expect(cashCollectedAndRecorded(s)).toBe(false);
    expect(cashCollectedButNotRecorded(s)).toBe(true);
  });

  it("có payment_id nhưng kèm lỗi ⇒ vẫn KHÔNG phải thành công", () => {
    const s = session({ error: "boom" });

    expect(cashCollectedAndRecorded(s)).toBe(false);
    expect(cashCollectedButNotRecorded(s)).toBe(true);
  });

  it("máy giữ tiền / trả tiền là chuyện KHÁC — không rơi vào nhóm 'đã thu chưa ghi sổ'", () => {
    for (const status of ["timeout", "abort", "failure", "cancel", "shortage"] as const) {
      const s = session({ status, payment_id: "", error: "x" });

      expect(cashCollectedAndRecorded(s)).toBe(false);
      // Chỉ `finish` mới là "đã thu"; các kết cục kia chưa từng ghi nhận tiền.
      expect(cashCollectedButNotRecorded(s)).toBe(false);
    }

    // Và ranh giới giữ-tiền vẫn nguyên: cancel/shortage là tiền ĐÃ trả lại.
    expect(cashRetainedByMachine("timeout")).toBe(true);
    expect(cashRetainedByMachine("cancel")).toBe(false);
    expect(cashRetainedByMachine("shortage")).toBe(false);
  });
});
