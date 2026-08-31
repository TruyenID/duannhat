/**
 * workstation-cash-changer-service — thin client for the LAN workstation's
 * 釣銭機 (Glory cash recycler) bridge.
 *
 * Why it goes through the workstation and can never go direct (#1804, Gate 8
 * ruling A): the machine speaks HTTP/JSON on the LAN with **no TLS** and an IP
 * allowlist. A cloud pos-web is served over HTTPS, so a direct call is blocked
 * as mixed content — the same wall #1169 had to build the same-origin `/pos`
 * mount to get around. The workstation (Go, on the shop LAN) is the sole host
 * of the driver, and every LAN client — POS and kiosk alike — reaches the
 * machine through it. A shop that wants a 釣銭機 installs a workstation; there
 * is no cloud-only bridge, by decision.
 *
 * **This client never creates a payment.** `cash_changer_recorder.go` states it
 * is "the single writer of the payments table for the cash-changer flow": on a
 * finished collection the workstation inserts the payment itself (idempotent on
 * the Glory transaction id), runs the order lifecycle and syncs UP. The POS's
 * job is to start the collection, poll it, and refetch the order when it ends.
 * Posting a payment here would double-charge the customer.
 *
 * Mirrors workstation-print-service: LAN only, never falls back to Cloud (the
 * endpoints do not exist there), and silent-no-op via `enabled` when no
 * workstation is paired.
 */

import { ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { SplitBillPaymentMetadata } from "./order-payment-service";
import { getWorkstationUrl, hasWorkstation } from "./workstation/base-url-resolver";

/** Audit context forwarded when the machine collects one row of a split bill. */
export type CashChangerSplitMetadata = Extract<
  SplitBillPaymentMetadata,
  { split_mode: string }
>;

function currentBase(): string {
  return hasWorkstation() ? getWorkstationUrl().replace(/\/+$/, "") : "";
}

/**
 * The machine's transaction lifecycle, verbatim from the adapter
 * (`internal/device/glory/types.go`). Kept as the wire vocabulary rather than
 * remapped to something friendlier — a second vocabulary is a second thing to
 * drift, and the money-relevant distinctions below live in these exact values.
 */
export type CashChangerStatus =
  | "beginDeposit" // 入金中 — accepting cash
  | "dispenseChange" // 出金中 — dispensing change
  | "waitPullOut" // つり銭抜き取り待ち — change dispensed, awaiting pickup
  | "finish" // 取引完了 — terminal, money taken, payment recorded
  | "cancel" // 取引キャンセル — terminal, deposited cash RETURNED
  | "abort" // 取引強制終了 — terminal, power/OS crash mid-transaction
  | "timeout" // 取引タイムアウト — terminal, cash KEPT by the machine
  | "failure"; // 取引エラー終了 — terminal, machine error

export interface CashChangerSession {
  session_id: string;
  order_id: string;
  running: boolean;
  status: CashChangerStatus | "";
  /** Set only on a recorded finish — the payment the WORKSTATION created. */
  payment_id: string;
  total: number;
  tendered: number;
  change: number;
  /** Terminal non-finish reason (shortage / timeout / failure / cancel). */
  error: string;
}

export interface CashChangerStart {
  session_id: string;
  order_id: string;
  total: number;
}

/**
 * Terminal states where the customer's cash is NOT back in their hand.
 *
 * `timeout` is the one that catches people out: the adapter documents it as
 * "deposit not completed in time; cash **KEPT**". Presenting it next to
 * `cancel` ("deposited cash returned") as one generic failure would tell a
 * cashier the customer has their money when the machine is still holding it.
 */
export function cashRetainedByMachine(status: CashChangerStatus | ""): boolean {
  return status === "timeout" || status === "abort" || status === "failure";
}

/**
 * #2535 B3 — `status === "finish"` MỘT MÌNH không phải là thành công.
 *
 * Workstation trả `finish` ngay cả khi đã thu tiền xong mà **ghi sổ hỏng**: máy
 * nhận tiền, thối tiền, rồi lượt INSERT payment thất bại. Snapshot lúc đó mang
 * `status: "finish"`, `payment_id: ""` và một chuỗi `error` — mà màn hình chỉ
 * render `error` trong khối "máy đang giữ tiền", nên thu ngân đọc được đúng hai
 * chữ "Đã thu xong" trong khi **không có dòng payment nào, đơn không đóng,
 * không gì lên Cloud**.
 *
 * `payment_id` là bằng chứng DUY NHẤT rằng workstation đã ghi được: nó chỉ được
 * gán sau khi `RecordCashPayment` trả về id (xem `CashChangerService.Collect`).
 * Nên "thành công" = có id, và không có lỗi kèm theo.
 */
export function cashCollectedAndRecorded(session: CashChangerSession): boolean {
  return session.status === "finish" && session.payment_id !== "" && session.error === "";
}

/**
 * Ca "đã thu tiền nhưng KHÔNG ghi được vào sổ" — trạng thái RIÊNG, không phải
 * một biến thể của thành công và cũng không phải tiền kẹt trong máy.
 *
 * Tiền nằm đúng chỗ (máy đã thối tiền xong), nên việc phải làm là ghi tay dòng
 * thu và đối soát — khác hẳn `cashRetainedByMachine`, nơi phải đi mở máy lấy
 * tiền ra. Workstation raise `cash_collected_not_recorded` cho đúng ca này.
 */
export function cashCollectedButNotRecorded(session: CashChangerSession): boolean {
  return session.status === "finish" && (session.payment_id === "" || session.error !== "");
}

async function call<T>(path: string, init: RequestInit): Promise<T> {
  const base = currentBase();
  if (!base) throw new ApiError(0, { message: "workstation unreachable" });

  let res: Response;
  try {
    // eslint-disable-next-line no-restricted-globals -- LAN-only endpoint: apiFetch resolves a Cloud base URL, and /api/v1/pos/cash-changer/* exists ONLY on the workstation
    res = await fetch(`${base}${path}`, {
      ...init,
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken() ?? ""}`,
        ...(init.headers ?? {}),
      },
    });
  } catch {
    // A LAN fetch failure is not an HTTP status — synthesise 0 so callers can
    // tell "workstation is off" from "machine refused", same as print does.
    throw new ApiError(0, { message: "workstation unreachable" });
  }

  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, body);
  return (body as { data: T }).data;
}

export const workstationCashChangerService = {
  /** False when no workstation is paired — callers must hide their UI. */
  get enabled(): boolean {
    return hasWorkstation();
  },

  /**
   * Start a collection.
   *
   * `amount` omitted → the workstation collects the whole OUTSTANDING balance,
   * read server-side. That is the default and the only shape used outside
   * split-bill: a client-asserted amount for cash a machine will physically
   * count is a number nobody can check afterwards.
   *
   * `amount` given (#2941) → one guest's share of a split bill. The workstation
   * still bounds it: `0 < amount <= outstanding`, else 422. The share itself
   * cannot be computed there — `lib/equal-split.ts` snaps to the currency minor
   * unit, skips locked rows, drops the remainder on the last row, and lets staff
   * override a row; the last three live in POS UI state, not in the
   * workstation's DB. So the number is computed HERE, once, and bounded THERE.
   * `metadata` is required with that amount (#2942) and is audit-only: the
   * workstation validates and forwards it but never uses it to calculate cash.
   *
   * `Tendered`/`Change` still come from the machine, never from this call.
   *
   * 503 = no machine configured · 409 = machine busy · 422 = amount not
   * positive, exceeds the outstanding balance, or order total not positive.
   */
  start(
    orderId: string,
    amount?: number,
    metadata?: CashChangerSplitMetadata
  ): Promise<CashChangerStart> {
    const body: {
      order_id: string;
      amount?: number;
      metadata?: CashChangerSplitMetadata;
    } = { order_id: orderId };
    if (amount !== undefined) body.amount = amount;
    if (metadata !== undefined) body.metadata = metadata;

    return call<CashChangerStart>("/api/v1/pos/cash-changer/collect", {
      method: "POST",
      body: JSON.stringify(body),
    });
  },

  /** Poll a session. 404 once the workstation has forgotten it. */
  status(sessionId: string): Promise<CashChangerSession> {
    return call<CashChangerSession>(
      `/api/v1/pos/cash-changer/collect/${encodeURIComponent(sessionId)}`,
      { method: "GET" },
    );
  },

  /** Ask the machine to return the deposited cash. */
  cancel(sessionId: string): Promise<{ session_id: string; canceling: boolean }> {
    return call(
      `/api/v1/pos/cash-changer/collect/${encodeURIComponent(sessionId)}/cancel`,
      { method: "POST" },
    );
  },
};
