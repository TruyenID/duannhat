import { render, screen } from "@testing-library/react";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { ConnectionBadge } from "../connection-badge";
import { I18nProvider } from "@/i18n";
import { setMode, resetUnreachable } from "@/services/base-url-resolver";

vi.mock("@/providers/use-realtime", () => ({
  useRealtime: () => ({ recordBumpKey: vi.fn(), isConnected: false }),
}));

beforeEach(() => {
  localStorage.clear();
  resetUnreachable();
});

describe("ConnectionBadge", () => {
  it("shows workstation mode by default (auto mode + reachable)", () => {
    render(
      <I18nProvider>
        <ConnectionBadge />
      </I18nProvider>
    );
    const badge = screen.getByTestId("connection-badge");
    expect(badge.dataset.mode).toBe("workstation");
  });

  it("shows cloud mode when forced", () => {
    setMode("cloud");
    render(
      <I18nProvider>
        <ConnectionBadge />
      </I18nProvider>
    );
    const badge = screen.getByTestId("connection-badge");
    expect(badge.dataset.mode).toBe("cloud");
  });
});
