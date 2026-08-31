import { describe, it, expect, beforeEach } from "vitest";
import {
  getMode,
  setMode,
  resolveBaseUrl,
  markWorkstationUnreachable,
  resetUnreachable,
  isUsingWorkstation,
} from "../base-url-resolver";

beforeEach(() => {
  localStorage.clear();
  resetUnreachable();
});

describe("base-url-resolver", () => {
  it("defaults to auto mode", () => {
    expect(getMode()).toBe("auto");
  });

  it("auto picks workstation first", () => {
    const r = resolveBaseUrl();
    expect(r.via).toBe("workstation");
  });

  it("auto falls back to cloud after markUnreachable", () => {
    markWorkstationUnreachable();
    const r = resolveBaseUrl();
    expect(r.via).toBe("cloud");
  });

  it("forced workstation mode ignores unreachable flag", () => {
    setMode("workstation");
    markWorkstationUnreachable();
    expect(resolveBaseUrl().via).toBe("workstation");
  });

  it("forced cloud mode", () => {
    setMode("cloud");
    expect(resolveBaseUrl().via).toBe("cloud");
  });

  it("resetUnreachable clears backoff", () => {
    markWorkstationUnreachable();
    expect(isUsingWorkstation()).toBe(false);
    resetUnreachable();
    expect(isUsingWorkstation()).toBe(true);
  });
});
