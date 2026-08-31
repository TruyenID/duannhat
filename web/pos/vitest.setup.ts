import "@testing-library/jest-dom/vitest";
import { afterEach, beforeEach } from "vitest";
import { cleanup, configure } from "@testing-library/react";

/**
 * `waitFor` / `findBy*` default to a 1s budget, which is independent of
 * vitest's `testTimeout`. Component tests that mount a whole money screen
 * (#1183 — shift open/close, payment + void dialogs) routinely need longer
 * than that when the pool runs several jsdom files at once, producing
 * failures that are pure contention. 5s keeps genuinely stuck assertions
 * fast to spot while removing the flake.
 */
configure({ asyncUtilTimeout: 5000 });

/**
 * Node 22+ ships an experimental native `localStorage` global that shadows
 * the one jsdom installs on window. The native version is empty + has no
 * `setItem` unless `--localstorage-file` is passed. Polyfill here so tests
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

beforeEach(() => {
  storage.clear();
  // document is only available in jsdom env — files using `@vitest-environment
  // node` (architecture/pure-logic tests) skip cookie reset.
  if (typeof document !== "undefined") {
    document.cookie.split(";").forEach((c) => {
      const eqPos = c.indexOf("=");
      const name = (eqPos > -1 ? c.substring(0, eqPos) : c).trim();
      if (name) document.cookie = `${name}=; path=/; max-age=0`;
    });
  }
});

afterEach(() => {
  cleanup();
});
