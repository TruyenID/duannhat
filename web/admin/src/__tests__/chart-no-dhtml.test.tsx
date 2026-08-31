/**
 * Bug 3 regression: dangerouslySetInnerHTML XSS in chart.tsx
 *
 * ChartContainer must NOT inject a <style> tag via dangerouslySetInnerHTML.
 * Colors should be provided via inline CSS custom properties instead.
 */
import { describe, it, expect, vi } from "vitest";
import { render } from "@testing-library/react";
import { ChartContainer, type ChartConfig } from "@/app/hq/[brandSlug]/dashboard/components/chart";

// Recharts ResponsiveContainer requires a measured parent — stub it in jsdom
vi.mock("recharts", async () => {
  const actual = await vi.importActual<typeof import("recharts")>("recharts");
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
      <div data-testid="responsive-container">{children}</div>
    ),
  };
});

const config: ChartConfig = {
  revenue: { label: "Revenue", color: "#3b82f6" },
  expenses: {
    label: "Expenses",
    theme: { light: "#ef4444", dark: "#f87171" },
  },
};

describe("ChartContainer (Bug 3 — no dangerouslySetInnerHTML)", () => {
  it("does NOT inject a <style> tag via dangerouslySetInnerHTML", () => {
    const { container } = render(
      <ChartContainer config={config}>
        <div />
      </ChartContainer>
    );

    // There must be no <style> element in the rendered output
    const styleEls = container.querySelectorAll("style");
    expect(styleEls.length).toBe(0);
  });

  it("exposes CSS custom properties on the container element instead", () => {
    const { container } = render(
      <ChartContainer config={config} id="test-chart">
        <div />
      </ChartContainer>
    );

    // The chart wrapper div should carry --color-* custom properties
    const chartEl = container.querySelector("[data-slot='chart']") as HTMLElement | null;
    expect(chartEl).not.toBeNull();

    const style = chartEl!.getAttribute("style") ?? "";
    // At least one color variable must be inlined
    expect(style).toMatch(/--color-/);
  });
});
