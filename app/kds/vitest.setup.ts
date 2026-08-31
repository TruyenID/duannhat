import "fake-indexeddb/auto";
import "@testing-library/jest-dom";

/**
 * Node 22+ ships an experimental native `localStorage` global that shadows
 * the one jsdom installs on window. The native version is empty + has no
 * `.clear()` unless `--localstorage-file` is passed. Polyfill here so tests
 * that touch localStorage work consistently.
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

/**
 * Mock Audio API for tests. Provides play/pause methods that return resolved promises.
 */
class MockAudioElement implements Partial<HTMLAudioElement> {
  src: string;
  preload: string = "";
  currentTime: number = 0;

  constructor(src?: string) {
    this.src = src || "";
  }

  async play(): Promise<void> {
    return Promise.resolve();
  }

  pause(): void {
    // no-op in tests
  }

  addEventListener(): void {
    // no-op in tests
  }

  removeEventListener(): void {
    // no-op in tests
  }
}

Object.defineProperty(globalThis, "Audio", {
  value: MockAudioElement,
  writable: true,
  configurable: true,
});

/**
 * jsdom doesn't ship `matchMedia`. Sonner's Toaster uses it for `theme="system"`,
 * and AppProvider uses it for system-theme tracking. Stub a passive matcher.
 */
if (typeof window !== "undefined" && !window.matchMedia) {
  Object.defineProperty(window, "matchMedia", {
    value: (query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => {},
      removeListener: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => false,
    }),
    writable: true,
    configurable: true,
  });
}
