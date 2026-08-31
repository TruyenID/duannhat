/**
 * #1501 — hàng đợi cho hành động NHẸ, KHÔNG dính tiền.
 *
 * ## Cái gì được xếp hàng, và tại sao danh sách lại ngắn đến thế
 *
 * Issue nêu hai ví dụ — "note" và "draft gọi món". Đọc lại code thì cả hai đều
 * KHÔNG dùng được ở pos-web hôm nay:
 *
 *   - **Ghi chú cấp ĐƠN không có giao diện nào.** `useOrderFieldSave` có nhánh
 *     `note`, nhưng nó không có người gọi: hai chỗ dùng duy nhất
 *     (`use-table-assignment`) chỉ gửi `table_ids` và `guest_count`.
 *   - **Ghi chú cấp MÓN và "gọi món nháp" đều là đường tiền.** Chúng đi qua
 *     mutation trên dòng đơn, tức là đổi hoá đơn; và #1148 quy định sửa dòng
 *     là pending-only, có cổng theo TRẠNG THÁI ở máy chủ — một cổng không thể
 *     đánh giá khi đang offline. Xếp hàng chúng là lén đưa việc bán hàng
 *     offline vào trình duyệt, đúng thứ #1170/#1092 nói là vai của workstation.
 *
 * Còn lại đúng một ứng viên thật: **đổi trạng thái bàn** (trống / có khách /
 * đang dọn). Không phải tiền, có giao diện đang chạy, và ghi đè cuối cùng
 * thắng nên phát lại là idempotent tự nhiên.
 *
 * `LightActionType` là union ĐÓNG, có test ghim: thêm một kiểu dính tiền vào
 * đây thì đỏ.
 *
 * ## Vì sao có TTL
 *
 * Phát lại một trạng thái bàn 40 phút tuổi thì nhiều khả năng nó đang đè lên
 * một sự thật MỚI HƠN do máy khác ghi, chứ không còn là "gửi muộn". Quá
 * `LIGHT_ACTION_TTL_MS` thì bỏ, và nói ra bằng toast — im lặng bỏ đi cũng tệ
 * ngang im lặng ghi đè.
 */
import {
  deleteLightAction,
  putLightAction,
  readLightActions,
  type LightAction,
} from "./idb";

/** Union ĐÓNG. Không có kiểu nào ở đây được phép chạm vào tiền. */
export type LightActionType = "table.status";

export const LIGHT_ACTION_TYPES: readonly LightActionType[] = ["table.status"];

/** Quá mốc này, phát lại nguy hiểm hơn là bỏ. */
export const LIGHT_ACTION_TTL_MS = 15 * 60_000;

export interface TableStatusAction {
  type: "table.status";
  payload: { shopSlug: string; tableId: string; status: string };
}

export type LightActionInput = TableStatusAction;

function newId(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export async function enqueueLightAction(
  input: LightActionInput,
): Promise<string> {
  const id = newId();
  await putLightAction({
    id,
    type: input.type,
    payload: { ...input.payload },
    queuedAt: Date.now(),
  });
  return id;
}

export async function countLightActions(): Promise<number> {
  return (await readLightActions()).length;
}

export interface ReplayOutcome {
  replayed: number;
  /** Bị bỏ vì quá TTL — phát lại sẽ đè lên sự thật mới hơn. */
  expired: number;
  /** Vẫn nằm trong hàng đợi (mạng còn hỏng). */
  kept: number;
  /** Bị bỏ vì máy chủ từ chối dứt khoát (4xx/5xx) — thử lại mãi cũng thế. */
  rejected: number;
}

export type LightActionRunner = (action: LightAction) => Promise<void>;

/**
 * Chạy hết hàng đợi theo THỨ TỰ XẾP HÀNG.
 *
 * `isNetworkFailure` phân biệt "chưa gửi được" với "máy chủ đã từ chối":
 * lỗi mạng thì giữ lại chờ lần sau; một câu trả lời 4xx/5xx là dứt khoát, giữ
 * lại chỉ tạo ra một hàng đợi không bao giờ cạn.
 */
export async function replayLightActions(
  run: LightActionRunner,
  isNetworkFailure: (err: unknown) => boolean,
  now: number = Date.now(),
): Promise<ReplayOutcome> {
  const actions = await readLightActions();
  const outcome: ReplayOutcome = {
    replayed: 0,
    expired: 0,
    kept: 0,
    rejected: 0,
  };

  for (const action of actions) {
    if (now - action.queuedAt > LIGHT_ACTION_TTL_MS) {
      await deleteLightAction(action.id);
      outcome.expired += 1;
      continue;
    }

    try {
      await run(action);
      await deleteLightAction(action.id);
      outcome.replayed += 1;
    } catch (err) {
      if (isNetworkFailure(err)) {
        // Vẫn chưa có mạng. Dừng luôn cả vòng: cố nốt phần còn lại chỉ tạo ra
        // một chuỗi timeout dài, và thứ tự phải giữ nguyên cho lần sau.
        outcome.kept = actions.length - outcome.replayed - outcome.expired;
        return outcome;
      }
      await deleteLightAction(action.id);
      outcome.rejected += 1;
    }
  }

  return outcome;
}
