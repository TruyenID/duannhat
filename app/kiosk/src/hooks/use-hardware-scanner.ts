"use client";

import { useEffect, useRef } from "react";
import { Platform } from "react-native";

/**
 * Listen for HID barcode/QR scanner (USB keyboard-wedge mode) on web.
 *
 * Cách scanner KBW hoạt động: nó "gõ" chuỗi ký tự + Enter rất nhanh
 * vào window. Phân biệt với human typing bằng tốc độ:
 *   - Scanner: ký tự cách nhau < 30ms
 *   - Human: ký tự cách nhau > 100ms
 *
 * Khi detect Enter sau 1 burst nhanh → coi như scan, gọi `onScan`.
 *
 * Native (iOS/Android) không dùng hook này — dùng `expo-camera` qua
 * `CameraView`. Hook tự no-op khi Platform.OS !== "web".
 */
interface UseHardwareScannerOptions {
  /** Bật/tắt listener — vd: tắt khi đang trong modal khác. */
  enabled?: boolean;
  /** Số ký tự min để coi là 1 scan hợp lệ (chống false positive khi user gõ tay). */
  minLength?: number;
  /** Ngưỡng ms giữa 2 keystroke để coi là scanner (mặc định 50ms). */
  maxKeyIntervalMs?: number;
}

export function useHardwareScanner(
  onScan: (code: string) => void,
  options: UseHardwareScannerOptions = {},
) {
  const { enabled = true, minLength = 4, maxKeyIntervalMs = 50 } = options;

  const bufferRef = useRef("");
  const lastKeyTimeRef = useRef(0);
  const onScanRef = useRef(onScan);

  // Always-fresh ref cho onScan để effect không re-attach mỗi render
  useEffect(() => {
    onScanRef.current = onScan;
  }, [onScan]);

  useEffect(() => {
    if (!enabled || Platform.OS !== "web") return;
    if (typeof window === "undefined") return;

    const handleKeyDown = (e: KeyboardEvent) => {
      // Bỏ qua nếu user đang gõ trong 1 input/textarea — scanner cũng gửi
      // keystroke nhưng nếu user đang focus input thì input tự nhận, không
      // cần intercept ở window level.
      const target = e.target as HTMLElement | null;
      if (
        target &&
        (target.tagName === "INPUT" ||
          target.tagName === "TEXTAREA" ||
          target.isContentEditable)
      ) {
        return;
      }

      const now = Date.now();
      const elapsed = now - lastKeyTimeRef.current;

      // Burst broken — reset buffer (human typing)
      if (elapsed > maxKeyIntervalMs && bufferRef.current.length > 0) {
        bufferRef.current = "";
      }
      lastKeyTimeRef.current = now;

      if (e.key === "Enter") {
        const code = bufferRef.current;
        bufferRef.current = "";
        lastKeyTimeRef.current = 0;
        if (code.length >= minLength) {
          onScanRef.current(code);
        }
        return;
      }

      // Chỉ gom ký tự printable (length === 1 với mod-key handling)
      if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        bufferRef.current += e.key;
      }
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [enabled, minLength, maxKeyIntervalMs]);
}
