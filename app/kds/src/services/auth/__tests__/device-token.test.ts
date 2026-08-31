import { describe, it, expect, beforeEach } from "vitest";
import {
  getDeviceToken,
  setDeviceToken,
  clearDeviceToken,
  isPaired,
  getDeviceInfo,
} from "../device-token";

beforeEach(() => localStorage.clear());

describe("device-token storage", () => {
  it("round-trips token", () => {
    setDeviceToken("tok-123", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });
    expect(getDeviceToken()).toBe("tok-123");
    expect(getDeviceInfo()?.id).toBe("d-1");
    expect(isPaired()).toBe(true);
  });

  it("returns null when unpaired", () => {
    expect(getDeviceToken()).toBeNull();
    expect(getDeviceInfo()).toBeNull();
    expect(isPaired()).toBe(false);
  });

  it("clears token + info", () => {
    setDeviceToken("tok-x", {
      id: "d",
      name: "n",
      type: "kds",
      branch_id: "b",
      branch_name: "",
    });
    clearDeviceToken();
    expect(getDeviceToken()).toBeNull();
    expect(getDeviceInfo()).toBeNull();
  });
});
