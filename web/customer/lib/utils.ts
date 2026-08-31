import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Short order code shown to customers: the last 4 characters of the full code.
 * Ví dụ "ORD-2026-4263" → "4263". Full code vẫn dùng cho QR / API / link.
 * Returns "" for empty/nullish input so callers can guard easily.
 */
export function shortOrderCode(code: string | null | undefined): string {
  return code ? code.slice(-4) : ""
}
