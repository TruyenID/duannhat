/**
 * `useMachineCollector` — bắc cầu poll → `await` (#2946).
 *
 * File này canh những ca mà UI không nhìn thấy được: `useCashChanger` đẩy kết
 * quả qua nhiều lượt render, nên "lượt thu nào vừa xong" là một câu hỏi có thể
 * trả lời SAI mà màn hình vẫn trông đúng.
 *
 * Ca đắt nhất là hàng thứ hai: `start()` là async, nên giữa lúc gắn lời hứa và
 * lúc phiên mới hạ cánh vẫn còn một lượt render mang phiên TRƯỚC — đã terminal
 * và đã thành công. Đọc nhầm nó là đánh dấu hàng #2 đã trả bằng tiền của hàng
 * #1: đơn thiếu một khoản, và không màn hình nào kêu.
 */

import { describe, expect, it, vi } from "vitest";
import { act, render } from "@testing-library/react";
import { useState } from "react";
import type {
  CashChangerSession,
  CashChangerSplitMetadata,
} from "@/services/workstation-cash-changer-service";
import { useMachineCollector, type MachineCollection } from "./use-machine-collector";
import type { UseCashChangerResult } from "./use-cash-changer";

function sessionOf(over: Partial<CashChangerSession>): CashChangerSession {
  return {
    session_id: "s-1",
    order_id: "order-1",
    running: false,
    status: "finish",
    payment_id: "pay-1",
    total: 1000,
    tendered: 1000,
    change: 0,
    error: "",
    ...over,
  } as CashChangerSession;
}

/**
 * Bộ điều khiển tay thay cho `useCashChanger`: test tự quyết định phiên nào
 * đang hiện và lúc nào nó terminal, đúng như poll thật sẽ làm.
 */
function harness(initial: Partial<UseCashChangerResult> = {}) {
  const api: {
    collect?: (
      orderId: string,
      amount: number,
      metadata: CashChangerSplitMetadata
    ) => Promise<MachineCollection | null>;
    idle?: boolean;
    setCash?: (next: Partial<UseCashChangerResult>) => void;
    startCalls: Array<[
      string,
      number | undefined,
      CashChangerSplitMetadata | undefined,
    ]>;
  } = { startCalls: [] };

  function Probe() {
    const [cash, setCash] = useState<UseCashChangerResult>({
      available: true,
      session: null,
      busy: false,
      error: null,
      outcomeUnknown: false,
      start: (orderId: string, amount?: number, metadata?: CashChangerSplitMetadata) => {
        api.startCalls.push([orderId, amount, metadata]);
        return Promise.resolve();
      },
      cancel: () => Promise.resolve(),
      dismiss: () => {},
      ...initial,
    } as UseCashChangerResult);

    const machine = useMachineCollector(cash);
    api.collect = machine.collect;
    api.idle = machine.idle;
    api.setCash = (next) => setCash((prev) => ({ ...prev, ...next }));

    return null;
  }

  render(<Probe />);

  return api;
}

const EVEN_METADATA: CashChangerSplitMetadata = {
  split_mode: "even",
  bill_index: 0,
  total_bills: 2,
};

describe("#2946 useMachineCollector", () => {
  it("giải quyết bằng payment của máy trạm khi tiền ĐÃ vào sổ", async () => {
    const h = harness();

    let resolved: MachineCollection | null | undefined;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then((r) => (resolved = r));
    });

    expect(h.startCalls).toEqual([["order-1", 1000, EVEN_METADATA]]);

    await act(async () => {
      h.setCash!({ session: sessionOf({ session_id: "s-new", tendered: 1500, change: 500 }) });
    });

    expect(resolved).toEqual({ id: "pay-1", tendered: 1500, change: 500 });
  });

  it("KHÔNG giải quyết bằng phiên CŨ còn sót lại trên hook", async () => {
    // Đây là ca hỏng mà không kêu: hàng #1 vừa xong, hook vẫn đang giữ phiên
    // terminal của nó, và hàng #2 vừa bấm. Không có phép so `session_id` thì
    // hàng #2 "thành công" ngay lập tức bằng tiền của hàng #1.
    const h = harness({ session: sessionOf({ session_id: "s-old", payment_id: "pay-CUA-HANG-1" }) });

    let resolved: MachineCollection | null | undefined;
    let settled = false;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then((r) => {
        resolved = r;
        settled = true;
      });
    });

    // Phiên cũ vẫn nằm đó, đã terminal, đã thành công — và phải bị bỏ qua.
    await act(async () => {
      h.setCash!({ busy: true });
    });
    expect(settled).toBe(false);

    await act(async () => {
      h.setCash!({ busy: false, session: sessionOf({ session_id: "s-new", payment_id: "pay-2" }) });
    });
    expect(resolved).toEqual({ id: "pay-2", tendered: 1000, change: 0 });
  });

  it("thu được tiền mà GHI SỔ HỎNG ⇒ null, không phải thành công", async () => {
    // Máy trả `finish` cho cả ca này (#2535 B3) — `payment_id` rỗng là dấu
    // hiệu duy nhất, và nó tuyệt đối không được đọc thành đã trả.
    const h = harness();

    let resolved: MachineCollection | null | undefined = undefined;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then((r) => (resolved = r));
    });
    await act(async () => {
      h.setCash!({ session: sessionOf({ session_id: "s-new", payment_id: "" }) });
    });

    expect(resolved).toBeNull();
  });

  it("máy GIỮ tiền (timeout) ⇒ null", async () => {
    const h = harness();

    let resolved: MachineCollection | null | undefined = undefined;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then((r) => (resolved = r));
    });
    await act(async () => {
      h.setCash!({ session: sessionOf({ session_id: "s-new", status: "timeout", payment_id: "" }) });
    });

    expect(resolved).toBeNull();
  });

  it("mất dấu kết cục ⇒ null, KHÔNG treo và KHÔNG đoán", async () => {
    const h = harness();

    let resolved: MachineCollection | null | undefined = undefined;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then((r) => (resolved = r));
    });
    await act(async () => {
      h.setCash!({ outcomeUnknown: true });
    });

    expect(resolved).toBeNull();
  });

  it("start() hỏng ngay ⇒ null, không treo hàng đó vĩnh viễn", async () => {
    // Không phiên mới nào sẽ tới (máy trạm không với tới được, hoặc 422 vượt
    // dư nợ). Chờ tiếp nghĩa là hàng đó kẹt ở "đang thu" cho tới khi đóng màn.
    const h = harness();

    let resolved: MachineCollection | null | undefined = undefined;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then((r) => (resolved = r));
    });
    await act(async () => {
      h.setCash!({ error: "workstation unreachable", busy: false });
    });

    expect(resolved).toBeNull();
  });

  it("phiên đang CHẠY chưa giải quyết gì", async () => {
    const h = harness();

    let settled = false;
    await act(async () => {
      void h.collect!("order-1", 1000, EVEN_METADATA).then(() => (settled = true));
    });
    await act(async () => {
      h.setCash!({ session: sessionOf({ session_id: "s-new", running: true, status: "" }) });
    });

    expect(settled).toBe(false);
  });

  it("chưa ghép máy trạm ⇒ KHÔNG idle, nên không nút nào hiện ra", () => {
    const h = harness({ available: false });

    expect(h.idle).toBe(false);
  });
});
