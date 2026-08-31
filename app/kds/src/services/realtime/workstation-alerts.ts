/**
 * #1806 S2 — trạng thái alert của máy trạm, phía KDS.
 *
 * Store cấp module chứ không phải React context, vì nguồn ghi (`RealtimeProvider`)
 * và nơi đọc (banner) nằm ở hai nhánh cây khác nhau, và alert phải sống sót qua
 * mọi lần điều hướng trong ca — nó mô tả trạng thái của MÁY, không của màn hình.
 *
 * Không tự gọi API: máy trạm đã đẩy `alert.snapshot` ngay sau `auth_ok`, nên
 * thêm một lượt fetch ở đây là hai nguồn chân lý cho cùng một câu hỏi.
 */

export interface WorkstationAlert {
  id?: string;
  kind: string;
  subject: string;
  severity: "critical" | "warning" | "info";
  title: string;
  count?: number;
}

type Listener = (alerts: WorkstationAlert[]) => void;

let alerts: WorkstationAlert[] = [];
const listeners = new Set<Listener>();

function emit() {
  // Sao chép mảng: React so sánh tham chiếu, sửa tại chỗ thì không re-render.
  const snapshot = [...alerts];
  for (const l of listeners) l(snapshot);
}

/** Khoá gộp — PHẢI giống hệt phía Go (`(kind, subject)`). */
function keyOf(a: { kind: string; subject: string }): string {
  return `${a.kind}::${a.subject}`;
}

export const workstationAlerts = {
  subscribe(l: Listener): () => void {
    listeners.add(l);
    l([...alerts]);
    return () => {
      listeners.delete(l);
    };
  },

  /**
   * Thay TOÀN BỘ danh sách — dùng cho `alert.snapshot` lúc kết nối lại.
   *
   * Phải là thay, không phải gộp: sự cố đã hết trong lúc mất kết nối sẽ không
   * có sự kiện `alert.resolved` nào tới, nên gộp sẽ để lại một banner đỏ vĩnh
   * viễn cho một vấn đề đã được sửa.
   */
  replace(next: WorkstationAlert[]) {
    alerts = next;
    emit();
  },

  raise(a: WorkstationAlert) {
    const k = keyOf(a);
    const i = alerts.findIndex((x) => keyOf(x) === k);
    if (i >= 0) alerts[i] = a;
    else alerts = [...alerts, a];
    emit();
  },

  resolve(kind: string, subject: string) {
    const k = keyOf({ kind, subject });
    alerts = alerts.filter((x) => keyOf(x) !== k);
    emit();
  },

  /** Mất kết nối tới máy trạm = KHÔNG biết gì, không phải "không có sự cố". */
  clear() {
    alerts = [];
    emit();
  },
};
