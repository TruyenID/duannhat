import { render } from "@testing-library/react";
import { describe, it, expect, beforeEach, vi, afterEach } from "vitest";
import { WakeLockProvider } from "../WakeLockProvider";

interface GlobalWithNavigator extends Global {
  navigator: Navigator & { wakeLock?: { request: (type: "screen") => Promise<{ release: () => Promise<void> }> } };
}

let originalNavigator: Navigator;

beforeEach(() => {
  originalNavigator = (global as unknown as GlobalWithNavigator).navigator;
  vi.restoreAllMocks();
});

afterEach(() => {
  (global as unknown as GlobalWithNavigator).navigator = originalNavigator;
  vi.restoreAllMocks();
});

describe("WakeLockProvider", () => {
  it("calls wakeLock.request('screen') on mount when supported", async () => {
    const releaseSpy = vi.fn().mockResolvedValue(undefined);
    const requestSpy = vi.fn().mockResolvedValue({ release: releaseSpy });

    (global as unknown as GlobalWithNavigator).navigator = {
      ...originalNavigator,
      wakeLock: { request: requestSpy },
    };

    render(<WakeLockProvider>child</WakeLockProvider>);

    // Give the async promise a chance to resolve
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(requestSpy).toHaveBeenCalledWith("screen");
  });

  it("is a no-op when wakeLock API unavailable", () => {
    (global as unknown as GlobalWithNavigator).navigator = {
      ...originalNavigator,
      wakeLock: undefined as unknown as undefined,
    };
    // Should not throw
    expect(() => render(<WakeLockProvider>child</WakeLockProvider>)).not.toThrow();
  });
});
