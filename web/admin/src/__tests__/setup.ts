import { vi } from "vitest";
import "@testing-library/jest-dom/vitest";
import { configure } from "@testing-library/react";

// testing-library's default `waitFor` / `findBy*` budget is 1 000 ms. That is
// plenty on an idle laptop and NOT plenty when 37 jsdom files run in parallel
// on a CI runner — a screen whose data arrives on the second microtask tick
// starts failing intermittently, and an intermittent gate gets ignored. 5 s is
// still fast enough that a genuinely-never-rendered element fails promptly.
configure({ asyncUtilTimeout: 5_000 });

// Pure-logic test files opt into the much faster `node` environment with a
// `@vitest-environment node` pragma. There is no DOM there, so every browser
// shim below is gated — without this the setup file itself throws on `window`
// and the file fails before its first assertion runs.
const HAS_DOM = typeof window !== "undefined";

// Mock localStorage
const store: Record<string, string> = {};
const localStorageMock = {
  getItem: (key: string) => store[key] ?? null,
  setItem: (key: string, value: string) => {
    store[key] = value;
  },
  removeItem: (key: string) => {
    delete store[key];
  },
  clear: () => {
    Object.keys(store).forEach((k) => delete store[k]);
  },
  get length() {
    return Object.keys(store).length;
  },
  key: (i: number) => Object.keys(store)[i] ?? null,
};
if (HAS_DOM) {
  Object.defineProperty(window, "localStorage", { value: localStorageMock });
}

// Mock fetch
globalThis.fetch = vi.fn(() =>
  Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve({}),
  } as Response)
);

// Mock ResizeObserver (required by Radix UI Popover, Slider, etc.)
class ResizeObserverMock {
  observe() {}
  unobserve() {}
  disconnect() {}
}
globalThis.ResizeObserver = ResizeObserverMock as unknown as typeof ResizeObserver;

// Mock pointer capture (required by Radix Select, Slider)
if (HAS_DOM) {
  if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = () => false;
  }
  if (!Element.prototype.setPointerCapture) {
    Element.prototype.setPointerCapture = () => {};
  }
  if (!Element.prototype.releasePointerCapture) {
    Element.prototype.releasePointerCapture = () => {};
  }

  // Mock matchMedia
  Object.defineProperty(window, "matchMedia", {
    value: (query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }),
  });
}
