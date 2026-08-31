import { describe, it, expect, vi, beforeEach } from "vitest";
import { createRealtimeDispatcher } from "../dispatcher";
import { LanWsClient } from "../lan-ws";
import { CloudEchoClient } from "../cloud-echo";

vi.mock("../lan-ws", () => ({
  LanWsClient: vi.fn(),
}));
vi.mock("../cloud-echo", () => ({
  CloudEchoClient: vi.fn(),
}));

beforeEach(() => {
  vi.clearAllMocks();
});

describe("createRealtimeDispatcher", () => {
  it("constructs LanWsClient for workstation mode", () => {
    createRealtimeDispatcher({ mode: "workstation", token: "tok", branchId: "b-1" });
    expect(LanWsClient).toHaveBeenCalledWith("tok");
    expect(CloudEchoClient).not.toHaveBeenCalled();
  });

  it("constructs CloudEchoClient for cloud mode", () => {
    createRealtimeDispatcher({ mode: "cloud", token: "tok", branchId: "b-1" });
    expect(CloudEchoClient).toHaveBeenCalledWith("tok", "b-1");
    expect(LanWsClient).not.toHaveBeenCalled();
  });
});
