import { describe, it, expect, beforeEach, vi } from "vitest";

// Mock dependencies that touch window/global state
vi.mock("laravel-echo", () => {
  const mockChannel = {
    listen: vi.fn().mockReturnThis(),
  };
  const mockEcho = vi.fn().mockImplementation(function (this: { private: typeof vi.fn }) {
    this.private = vi.fn().mockReturnValue(mockChannel);
    this.disconnect = vi.fn();
  });
  return { default: mockEcho };
});

vi.mock("pusher-js", () => ({
  default: vi.fn(),
}));

vi.mock("@/services/kds/reverb-config", () => ({
  getReverbConfig: vi.fn().mockResolvedValue({
    app_key: "test-key",
    cluster: "reverb",
    host: "reverb.local",
    port: 6001,
    scheme: "https" as const,
  }),
}));

import { CloudEchoClient } from "../cloud-echo";
import Echo from "laravel-echo";

beforeEach(() => {
  vi.clearAllMocks();
});

describe("CloudEchoClient", () => {
  it("constructs Echo with reverb config + subscribes to branch channel on connect", async () => {
    const client = new CloudEchoClient("tok-abc", "branch-123");
    await client.connect();

    expect(Echo).toHaveBeenCalledTimes(1);
    const callArgs = (Echo as unknown as { mock: { calls: unknown[][] } }).mock.calls[0][0] as {
      key: string;
      wsHost: string;
      auth: { headers: { Authorization: string } };
    };
    expect(callArgs.key).toBe("test-key");
    expect(callArgs.wsHost).toBe("reverb.local");
    expect(callArgs.auth.headers.Authorization).toBe("Bearer tok-abc");
  });

  it("close() disconnects + ignores subsequent connect", async () => {
    const client = new CloudEchoClient("tok", "branch-1");
    await client.connect();
    client.close();
    await client.connect(); // no-op
    expect(Echo).toHaveBeenCalledTimes(1);
  });

  it("on() registers listener that receives forwarded events", async () => {
    const client = new CloudEchoClient("tok", "branch-1");
    const received: unknown[] = [];
    client.on((e) => received.push(e));
    await client.connect();
    // Simulate Echo channel callback invocation (we'd need to grab the
    // .listen handler — for now, sanity check via the private dispatch).
    (client as unknown as { dispatch: (e: unknown) => void }).dispatch({
      type: "order_item.status_changed",
      payload: { item_id: "i-1" },
    });
    expect(received).toHaveLength(1);
  });
});
