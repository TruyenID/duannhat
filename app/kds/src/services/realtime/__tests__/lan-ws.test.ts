import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { LanWsClient } from "../lan-ws";

// Minimal WebSocket-like stub for tests
class FakeWS extends EventTarget {
  static OPEN = 1;
  static CLOSED = 3;
  readyState = 0;
  sentMessages: string[] = [];
  constructor() {
    super();
  }
  send(data: string) {
    this.sentMessages.push(data);
  }
  close(_code?: number, _reason?: string) {
    this.readyState = FakeWS.CLOSED;
    this.dispatchEvent(new CloseEvent("close", { code: 1000 }));
  }
  // helpers for tests
  __open() {
    this.readyState = FakeWS.OPEN;
    this.dispatchEvent(new Event("open"));
  }
  __message(data: string) {
    this.dispatchEvent(new MessageEvent("message", { data }));
  }
  __close(code: number) {
    this.readyState = FakeWS.CLOSED;
    this.dispatchEvent(new CloseEvent("close", { code }));
  }
}

(globalThis as unknown as { WebSocket: { OPEN: number; CLOSED: number } }).WebSocket = FakeWS as never;

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("LanWsClient", () => {
  it("sends auth message immediately on open", () => {
    const ws = new FakeWS() as unknown as WebSocket & FakeWS;
    const client = new LanWsClient("tok-abc", "http://ws.local", () => ws);
    client.connect();
    ws.__open();
    expect(ws.sentMessages).toHaveLength(1);
    const sent = JSON.parse(ws.sentMessages[0]);
    expect(sent.type).toBe("auth");
    expect(sent.payload.token).toBe("tok-abc");
  });

  it("transitions to ready after auth_ok message", () => {
    const ws = new FakeWS() as unknown as WebSocket & FakeWS;
    const client = new LanWsClient("tok", "http://ws.local", () => ws);
    client.connect();
    ws.__open();
    expect(client.isReady).toBe(false);
    ws.__message(JSON.stringify({ type: "auth_ok" }));
    expect(client.isReady).toBe(true);
  });

  it("fans out auth_ok and regular events to listeners", () => {
    const ws = new FakeWS() as unknown as WebSocket & FakeWS;
    const client = new LanWsClient("tok", "http://ws.local", () => ws);
    const received: unknown[] = [];
    client.on((e) => received.push(e));
    client.connect();
    ws.__open();
    ws.__message(JSON.stringify({ type: "auth_ok" }));
    ws.__message(JSON.stringify({ type: "order_item.status_changed", payload: { item_id: "i-1" } }));
    // auth_ok is now forwarded so RealtimeProvider can set isConnected=true
    expect(received).toHaveLength(2);
    expect((received[0] as { type: string }).type).toBe("auth_ok");
    expect((received[1] as { type: string }).type).toBe("order_item.status_changed");
  });

  it("stops reconnecting on 4401 close", () => {
    const factory = vi.fn().mockImplementation(() => new FakeWS()) as unknown as (url: string) => WebSocket;
    const client = new LanWsClient("bad-tok", "http://ws.local", factory);
    client.connect();
    const ws = (factory as unknown as { mock: { results: { value: FakeWS }[] } }).mock.results[0].value;
    ws.__open();
    ws.__close(4401);
    // Advance time to confirm no reconnect attempt
    vi.advanceTimersByTime(2_000);
    expect((factory as unknown as { mock: { calls: unknown[] } }).mock.calls).toHaveLength(1);
  });

  it("reconnects after non-4401 close with backoff", () => {
    const factory = vi.fn().mockImplementation(() => new FakeWS()) as unknown as (url: string) => WebSocket;
    const client = new LanWsClient("tok", "http://ws.local", factory);
    client.connect();
    const ws1 = (factory as unknown as { mock: { results: { value: FakeWS }[] } }).mock.results[0].value;
    ws1.__open();
    ws1.__close(1006); // abnormal closure
    // First reconnect after 1s
    vi.advanceTimersByTime(1_001);
    expect((factory as unknown as { mock: { calls: unknown[] } }).mock.calls).toHaveLength(2);
  });

  it("close() stops reconnect + ignores new events", () => {
    const factory = vi.fn().mockImplementation(() => new FakeWS()) as unknown as (url: string) => WebSocket;
    const client = new LanWsClient("tok", "http://ws.local", factory);
    client.connect();
    client.close();
    vi.advanceTimersByTime(60_000);
    expect((factory as unknown as { mock: { calls: unknown[] } }).mock.calls).toHaveLength(1); // only initial
  });
});
