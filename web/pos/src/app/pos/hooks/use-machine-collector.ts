/**
 * useMachineCollector — bắc cầu từ `useCashChanger` (đẩy, theo poll) sang một
 * lời gọi `await` được, để tab chia bill hỏi "thu hàng này bằng máy" như một
 * hành động bình thường (#2946).
 *
 * ## Vì sao cần một lớp riêng thay vì gọi thẳng `start()`
 *
 * `useCashChanger` là một MÁY TRẠNG THÁI SỐNG cho MỘT màn hình: `start()` trả
 * về ngay, rồi kết quả nhỏ giọt vào `session` qua nhiều lượt render. Màn thu
 * tiền đọc kiểu đó vì nó chỉ có một lượt thu tại một thời điểm. Tab chia bill
 * thì có N hàng, mỗi hàng một trạng thái riêng, nên nó cần biết **hàng nào**
 * vừa xong — tức cần một lời hứa gắn với đúng lượt gọi.
 *
 * ## Ba thứ load-bearing, đảo lại là sai TIỀN
 *
 * | Thứ | Vì sao |
 * |---|---|
 * | Chỉ giải quyết khi `session_id` KHÁC cái thấy lúc gọi | `start()` là async: giữa lúc gắn lời hứa và lúc phiên mới hạ cánh, effect có thể chạy một lượt với phiên TRƯỚC đã terminal. Không có vế này thì hàng thứ hai "thành công" ngay lập tức bằng kết quả của hàng thứ nhất |
 * | Điều kiện thành công là `cashCollectedAndRecorded`, không phải `finish` | Máy trả `finish` cả cho ca đã thu tiền mà ghi sổ hỏng (#2535 B3). Đánh dấu hàng đó đã trả là nói dối về một khoản chưa vào sổ |
 * | `outcomeUnknown` ⇒ `null`, KHÔNG phải ném lỗi | "Không còn biết" khác "hỏng". Cả hai đều không được đánh dấu đã trả, nhưng ca này phải để overlay nói rõ là tiền có thể đang trong máy |
 *
 * Lớp này **không** tạo payment và không thể tạo — nó chỉ đọc `session`. Người
 * ghi duy nhất vẫn là máy trạm (`web/pos/CLAUDE.md` §釣銭機).
 */

import { useCallback, useEffect, useRef, useState } from "react";
import {
  cashCollectedAndRecorded,
  type CashChangerSession,
  type CashChangerSplitMetadata,
} from "@/services/workstation-cash-changer-service";
import type { UseCashChangerResult } from "./use-cash-changer";

/** Payment do MÁY TRẠM tạo, cộng số tiền MÁY đếm được. */
export interface MachineCollection {
  id: string;
  tendered?: number;
  change?: number;
}

interface Pending {
  /** `session_id` đang hiện trên hook lúc gắn lời hứa; "" khi chưa có phiên nào. */
  startedFrom: string;
  resolve: (result: MachineCollection | null) => void;
}

export interface UseMachineCollectorResult {
  /** Máy có mặt và KHÔNG có lượt thu nào đang chạy. */
  idle: boolean;
  /**
   * Thu `amount` cho `orderId`. Giải quyết bằng payment của máy trạm khi tiền
   * đã vào sổ, hoặc `null` cho MỌI kết cục khác (huỷ · tiền kẹt · thu được mà
   * ghi sổ hỏng · mất dấu). `null` không bao giờ được đọc thành "đã trả".
   */
  collect: (
    orderId: string,
    amount: number,
    metadata: CashChangerSplitMetadata
  ) => Promise<MachineCollection | null>;
}

export function useMachineCollector(cashChanger: UseCashChangerResult): UseMachineCollectorResult {
  const pendingRef = useRef<Pending | null>(null);
  /**
   * Bản sao ĐỌC ĐƯỢC LÚC RENDER của `pendingRef`.
   *
   * Ref không kích hoạt render, nên tính `idle` từ nó sẽ cho một giá trị cũ:
   * nút của các hàng khác không khoá lại vào đúng lúc lượt thu bắt đầu. Ref
   * giữ cho resolver (thứ chỉ chạy trong effect/handler), state giữ cho giao
   * diện — hai vai khác nhau, không phải trùng lặp.
   */
  const [collecting, setCollecting] = useState(false);

  const settle = (result: MachineCollection | null) => {
    const pending = pendingRef.current;
    if (!pending) return;
    pendingRef.current = null;
    setCollecting(false);
    pending.resolve(result);
  };

  const { session, busy, error, outcomeUnknown, start, available } = cashChanger;

  useEffect(() => {
    const pending = pendingRef.current;
    if (!pending) return;

    // Poll bỏ cuộc mà chưa từng thấy trạng thái terminal. Không đoán.
    if (outcomeUnknown) {
      settle(null);
      return;
    }

    const isNewSession = session !== null && session.session_id !== pending.startedFrom;

    // `start()` hỏng ngay từ đầu (máy trạm không với tới được, 422 vượt dư nợ).
    // Không có phiên mới nào sẽ tới, nên chờ tiếp là treo hàng đó vĩnh viễn.
    if (!isNewSession) {
      if (error !== null && !busy) settle(null);
      return;
    }

    if (session.running) return;

    settle(machineResultOf(session));
  }, [session, busy, error, outcomeUnknown]);

  const collect = useCallback(
    (orderId: string, amount: number, metadata: CashChangerSplitMetadata) =>
      new Promise<MachineCollection | null>((resolve) => {
        // Một lượt thu đang chạy: máy chỉ có MỘT. Nút đã bị khoá bởi `idle`,
        // đây là dây an toàn cho cái khoá đó — và nó KHÔNG được cướp lời hứa
        // đang treo, vì hàng kia sẽ không bao giờ được trả lời.
        if (pendingRef.current) {
          resolve(null);
          return;
        }
        pendingRef.current = { startedFrom: session?.session_id ?? "", resolve };
        setCollecting(true);
        void start(orderId, amount, metadata);
      }),
    [session, start]
  );

  return {
    idle: available && !collecting && !busy && !session?.running,
    collect,
  };
}

function machineResultOf(session: CashChangerSession): MachineCollection | null {
  if (!cashCollectedAndRecorded(session)) return null;

  return {
    id: session.payment_id,
    // Số MÁY đếm. pos-web không tự tính hai con số này cho luồng 釣銭機 —
    // khách bỏ tiền vào máy, không đưa cho thu ngân.
    tendered: session.tendered,
    change: session.change,
  };
}
