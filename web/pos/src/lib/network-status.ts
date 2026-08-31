/**
 * #1501 — trạng thái "POS có với tới máy chủ được không".
 *
 * ## Vì sao không dùng thẳng `navigator.onLine`
 *
 * `navigator.onLine === false` là bằng chứng chắc chắn có mất mạng, nhưng
 * `true` gần như không chứng minh gì: máy vẫn thấy card mạng lên trong khi
 * router chết, DNS hỏng, hoặc — trường hợp thường gặp nhất ở quán — Wi-Fi
 * vẫn nối nhưng workstation LAN đã tắt và Cloud thì ở ngoài Internet. Một
 * banner chỉ tin `navigator.onLine` sẽ im lặng đúng lúc thu ngân cần nó nhất.
 *
 * Nên tín hiệu thật đến từ chính `apiFetch`: mỗi lời gọi kết thúc bằng LỖI
 * MẠNG (không nhận được byte nào từ bất kỳ host nào) đẩy bộ đếm lên, mỗi lần
 * chạm được máy chủ đưa nó về 0. Lưu ý 4xx/5xx **là** chạm được máy chủ —
 * chúng chứng minh có mạng, nên chúng reset bộ đếm.
 *
 * ## Vì sao ngưỡng là 2 chứ không phải 1
 *
 * Một request lẻ bị huỷ (đổi tab, timeout LAN 3s trong lúc workstation đang
 * bận) là chuyện thường ngày. Nhảy banner "MẤT KẾT NỐI" rồi tắt ngay sau đó
 * làm thu ngân học cách phớt lờ nó — lúc mất mạng thật thì banner đã mất uy
 * tín. Hai lần liên tiếp thì trong thực tế chỉ vài giây, mà nhiễu thì gần như
 * hết.
 *
 * Trạng thái là module state + `useSyncExternalStore`, cùng khuôn với
 * `services/workstation/request-stats.ts` — không thêm provider mới, và
 * `apiFetch` (một hàm thuần, không có React context) gọi được trực tiếp.
 */
import { useSyncExternalStore } from "react";

export const OFFLINE_FAILURE_THRESHOLD = 2;

export interface NetworkStatus {
  /** `navigator.onLine` — chỉ dùng cho chiều PHỦ ĐỊNH (false ⇒ chắc chắn mất). */
  browserOnline: boolean;
  /** Số lời gọi apiFetch liên tiếp kết thúc bằng lỗi mạng. */
  consecutiveNetworkFailures: number;
  /**
   * epoch ms lần cuối POS này thật sự nói chuyện được với máy chủ. `null` =
   * chưa lần nào trong phiên (và cũng chưa hydrate được gì từ cache).
   */
  lastSyncedAt: number | null;
  /** Bump mỗi lần đổi để `useSyncExternalStore` nhận ra. */
  version: number;
}

function initialStatus(): NetworkStatus {
  return {
    browserOnline:
      typeof navigator === "undefined" ? true : navigator.onLine !== false,
    consecutiveNetworkFailures: 0,
    lastSyncedAt: null,
    version: 0,
  };
}

let status: NetworkStatus = initialStatus();
const listeners = new Set<() => void>();

function set(next: Partial<NetworkStatus>): void {
  status = { ...status, ...next, version: status.version + 1 };
  for (const fn of listeners) fn();
}

/** Kết quả cuối cùng của MỘT lời gọi apiFetch (đã tính cả fallback LAN→Cloud). */
export function markApiOutcome(outcome: "reached-server" | "network-error"): void {
  if (outcome === "reached-server") {
    set({ consecutiveNetworkFailures: 0, lastSyncedAt: Date.now() });
    return;
  }
  set({ consecutiveNetworkFailures: status.consecutiveNetworkFailures + 1 });
}

/**
 * Gieo tuổi dữ liệu từ IndexedDB khi khởi động.
 *
 * Chỉ gieo khi phiên này CHƯA hề chạm được máy chủ: một lần fetch thành công
 * bao giờ cũng mới hơn bất cứ ảnh chụp nào, nên nó phải thắng. Không có việc
 * này thì banner offline lúc khởi động nguội chỉ nói được "không biết dữ liệu
 * cũ tới đâu", tức đúng cái tệ nhất — hiện số liệu cũ mà không nói là cũ.
 */
export function seedLastSyncedAt(timestamp: number | null): void {
  if (timestamp === null) return;
  if (status.lastSyncedAt !== null) return;
  set({ lastSyncedAt: timestamp });
}

export function isOffline(s: NetworkStatus): boolean {
  if (!s.browserOnline) return true;
  return s.consecutiveNetworkFailures >= OFFLINE_FAILURE_THRESHOLD;
}

export function getNetworkStatus(): NetworkStatus {
  return status;
}

/** Seam cho test + HMR. */
export function resetNetworkStatus(): void {
  status = initialStatus();
  for (const fn of listeners) fn();
}

function subscribe(fn: () => void): () => void {
  listeners.add(fn);
  return () => {
    listeners.delete(fn);
  };
}

/**
 * Gắn listener `online` / `offline` của trình duyệt. Trả về hàm gỡ.
 *
 * Cố ý KHÔNG chạy như side-effect lúc import: một module tự gắn listener vào
 * `window` khi được nạp thì không test được và cũng không tắt được. Component
 * banner gọi nó trong `useEffect`.
 */
export function installNetworkListeners(): () => void {
  if (typeof window === "undefined") return () => {};

  const goOnline = () => {
    // Card mạng lên KHÔNG có nghĩa là máy chủ với tới được — chỉ xoá bằng
    // chứng phủ định và để lời gọi API kế tiếp nói lời cuối. Nếu ở đây reset
    // luôn bộ đếm thất bại thì rút dây rồi cắm lại vào một hub chết sẽ tắt
    // banner trong khi POS vẫn không gọi được gì.
    set({ browserOnline: true });
  };
  const goOffline = () => set({ browserOnline: false });

  window.addEventListener("online", goOnline);
  window.addEventListener("offline", goOffline);
  return () => {
    window.removeEventListener("online", goOnline);
    window.removeEventListener("offline", goOffline);
  };
}

export function useNetworkStatus(): NetworkStatus {
  return useSyncExternalStore(subscribe, getNetworkStatus, getNetworkStatus);
}
