import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach, beforeEach } from "vitest";

/**
 * Node 22+ có sẵn một `localStorage` thực nghiệm ở global, và nó CHE mất bản
 * jsdom cài lên `window`. Bản gốc rỗng và không có `setItem` nếu thiếu cờ
 * `--localstorage-file`, nên `AppProvider` (đọc locale từ localStorage lúc
 * khởi tạo) chết ngay dòng đầu. Cùng vá với `web/pos/vitest.setup.ts`.
 */
const storage = new Map<string, string>();
const polyfill: Storage = {
  get length() {
    return storage.size;
  },
  clear: () => storage.clear(),
  getItem: (k) => storage.get(k) ?? null,
  key: (i) => Array.from(storage.keys())[i] ?? null,
  removeItem: (k) => {
    storage.delete(k);
  },
  setItem: (k, v) => {
    storage.set(k, String(v));
  },
};
Object.defineProperty(globalThis, "localStorage", {
  value: polyfill,
  writable: false,
  configurable: true,
});

beforeEach(() => {
  storage.clear();
});

afterEach(() => {
  cleanup();
});
