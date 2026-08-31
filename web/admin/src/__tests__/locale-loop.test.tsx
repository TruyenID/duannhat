import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, act, screen } from "@testing-library/react";
import { AppProvider, useLocale, useTranslation } from "@/providers/app-provider";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <AppProvider>{children}</AppProvider>
      </QueryClientProvider>
    );
  };
}

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

describe("locale change render count", () => {
  it("does NOT infinite loop when locale changes", () => {
    let renderCount = 0;

    function TestComponent() {
      const { locale, setLocale, locales } = useLocale();
      const { t } = useTranslation();
      renderCount++;

      // Simulate what brand layout does — call t() for nav items
      const labels = [
        t("nav.dashboard"),
        t("nav.products"),
        t("nav.categories"),
        t("nav.materials"),
        t("nav.recipes"),
        t("nav.menus"),
        t("nav.shops"),
        t("nav.approvals"),
        t("nav.settings"),
      ];

      return (
        <div>
          <span data-testid="locale">{locale}</span>
          <span data-testid="count">{renderCount}</span>
          <span data-testid="label">{labels[0]}</span>
          <button data-testid="switch-en" onClick={() => setLocale("en")}>
            EN
          </button>
          <button data-testid="switch-ja" onClick={() => setLocale("ja")}>
            JA
          </button>
        </div>
      );
    }

    const Wrapper = createWrapper();
    render(<TestComponent />, { wrapper: Wrapper });

    // Initial render
    expect(screen.getByTestId("locale").textContent).toBe("ja");
    expect(screen.getByTestId("label").textContent).toBe("ダッシュボード");
    const initialCount = renderCount;

    // Switch to English
    act(() => {
      screen.getByTestId("switch-en").click();
    });

    expect(screen.getByTestId("locale").textContent).toBe("en");
    expect(screen.getByTestId("label").textContent).toBe("Dashboard");

    // Should NOT have rendered more than ~5 times (initial + state update + effects)
    const afterSwitchCount = renderCount;
    const extraRenders = afterSwitchCount - initialCount;
    console.log(`Renders after locale switch: ${extraRenders}`);
    expect(extraRenders).toBeLessThan(10);
  });

  it("does NOT infinite loop with nested state consumers", () => {
    let parentRenders = 0;
    let childRenders = 0;

    function Parent() {
      const { locale, setLocale } = useLocale();
      const { t } = useTranslation();
      parentRenders++;

      // Simulate brand layout creating navGroups with t()
      const navGroups = [
        { label: t("nav.overview"), items: [{ title: t("nav.dashboard") }] },
        { label: t("nav.catalog"), items: [{ title: t("nav.products") }] },
      ];

      return (
        <div>
          <span data-testid="parent-count">{parentRenders}</span>
          <button data-testid="switch" onClick={() => setLocale("en")}>
            Switch
          </button>
          <Child navGroups={navGroups} />
        </div>
      );
    }

    function Child({ navGroups }: { navGroups: { label: string; items: { title: string }[] }[] }) {
      childRenders++;
      return (
        <div>
          <span data-testid="child-count">{childRenders}</span>
          {navGroups.map((g) => (
            <div key={g.label}>{g.label}</div>
          ))}
        </div>
      );
    }

    const Wrapper = createWrapper();
    render(<Parent />, { wrapper: Wrapper });

    const initialParent = parentRenders;
    const initialChild = childRenders;

    act(() => {
      screen.getByTestId("switch").click();
    });

    const parentExtra = parentRenders - initialParent;
    const childExtra = childRenders - initialChild;
    console.log(`Parent renders: ${parentExtra}, Child renders: ${childExtra}`);

    expect(parentExtra).toBeLessThan(10);
    expect(childExtra).toBeLessThan(10);
  });
});
