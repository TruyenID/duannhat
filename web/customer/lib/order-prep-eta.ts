/**
 * ETA bếp cho status banner "Đang chuẩn bị" trên trang chi tiết đơn hàng.
 *
 * Nằm ở `lib/` để (a) hai trang chi tiết — guest và account — dùng chung một
 * phép tính, và (b) test được: đây là số phút mà khách nhìn để quyết định lúc
 * nào ra quán lấy đồ, nên sai một nhánh ở đây là khách đi sớm hoặc đi muộn.
 *
 * `nowMs` là tham số, không phải `Date.now()` đọc lén bên trong — nếu không thì
 * mọi case đều phải chạy đúng thời điểm mới kiểm được.
 */

export interface PrepEtaInput {
  placedAt: string | null;
  estimatedReadyTime: string | null;
  actualReadyTime: string | null;
  preparationMinutes: number | null;
  totalQty: number;
  /** Mốc "bây giờ" tính bằng ms (call site truyền `Date.now()`). */
  nowMs: number;
}

export type PrepEta =
  | {
      label: true;
      labelKey: "readyAt" | "readyInMinutes";
      params: Record<string, string | number>;
    }
  | { label: false; fallbackMinutes: number };

/**
 * Priority đọc dữ liệu:
 *   1. `estimatedReadyTime` (admin/BE set sẵn)
 *   2. `placedAt + preparationMinutes` → tự tính giờ dự kiến
 *   3. Fallback heuristic 15 + 3×qty phút (chặn trên 60)
 *
 * `actualReadyTime` không xét ở đây: bếp đã xong thì banner rơi vào nhánh
 * "ready" trước khi tới hàm này.
 */
export function computePrepEta(input: PrepEtaInput): PrepEta {
  const { placedAt, estimatedReadyTime, preparationMinutes, totalQty, nowMs } =
    input;

  // Source 1: estimated_ready_time (admin/BE set sẵn)
  let readyTs: number | null = null;
  if (estimatedReadyTime) {
    const ts = Date.parse(estimatedReadyTime);
    if (!Number.isNaN(ts)) readyTs = ts;
  }

  // Source 2: placed_at + preparation_minutes
  if (
    readyTs == null &&
    placedAt &&
    typeof preparationMinutes === "number" &&
    preparationMinutes > 0
  ) {
    const placedTs = Date.parse(placedAt);
    if (!Number.isNaN(placedTs)) {
      readyTs = placedTs + preparationMinutes * 60_000;
    }
  }

  if (readyTs != null) {
    // Đã qua giờ dự kiến → vẫn show heuristic 5 phút nữa (tránh "ETA quá
    // khứ" làm khách bối rối). Trường hợp này thường do bếp delay.
    const diffMs = readyTs - nowMs;
    if (diffMs <= 0) {
      return { label: false, fallbackMinutes: 5 };
    }
    const diffMinutes = Math.ceil(diffMs / 60_000);
    if (diffMinutes <= 60) {
      // Ưu tiên "Xong trong X phút" cho khoảng ngắn (dễ tracking)
      return {
        label: true,
        labelKey: "readyInMinutes",
        params: { minutes: diffMinutes },
      };
    }
    // Xa hơn 1 tiếng → show giờ tuyệt đối "Dự kiến xong lúc HH:MM"
    const d = new Date(readyTs);
    const hh = String(d.getHours()).padStart(2, "0");
    const mi = String(d.getMinutes()).padStart(2, "0");
    return {
      label: true,
      labelKey: "readyAt",
      params: { time: `${hh}:${mi}` },
    };
  }

  // Source 3: fallback heuristic — chỉ trigger khi BE chưa set
  // estimated_ready_time + preparation_minutes (admin chưa configure)
  return { label: false, fallbackMinutes: Math.min(60, 15 + totalQty * 3) };
}
