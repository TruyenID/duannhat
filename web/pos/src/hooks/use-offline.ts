/**
 * #1501 — một câu hỏi duy nhất cho toàn app: "POS đang mất kết nối máy chủ?"
 *
 * Tách khỏi `lib/network-status.ts` để mọi component chỉ import một hook, thay
 * vì mỗi chỗ tự gọi `isOffline(useNetworkStatus())` — hai chỗ quên `isOffline`
 * và chỉ đọc `browserOnline` là hai chỗ nghĩ mình đang online trong khi
 * workstation đã tắt.
 */
import { isOffline, useNetworkStatus } from "@/lib/network-status";

export function useIsOffline(): boolean {
  return isOffline(useNetworkStatus());
}
