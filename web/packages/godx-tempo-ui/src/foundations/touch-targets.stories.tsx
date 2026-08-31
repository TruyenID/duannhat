import type { Meta, StoryObj } from "@storybook/react-vite";

import { Button } from "@godxjp/ui";

/**
 * Visual catalog for the touch target rule.
 *
 * Source: Digital Agency button accessibility page (公式 hard rule):
 * 「ボタンのターゲット領域は44 CSS px以上」 — pointer/touch targets must be
 * ≥ 44 CSS pixels. Enforces WCAG 2.1 SC 2.5.5 with padding when the
 * visible button is smaller (Small / X-Small variants).
 */
const meta: Meta = {
  title: "Foundations/Touch Targets",
  parameters: {
    docs: {
      description: {
        component:
          "Hard rule from the Japanese Digital Agency: every interactive element must have a 44 × 44 CSS pixel hit area, even if the visual button is smaller. Compact buttons MUST add transparent padding via `::before` or wrapper to reach the floor.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

const SIZES: { name: string; size: "xs" | "sm" | "default" | "lg" | "xl"; visualPx: number; ok: boolean }[] = [
  { name: "xs", size: "xs", visualPx: 24, ok: false },
  { name: "sm", size: "sm", visualPx: 28, ok: false },
  { name: "default", size: "default", visualPx: 32, ok: false },
  { name: "lg", size: "lg", visualPx: 36, ok: false },
  { name: "xl", size: "xl", visualPx: 44, ok: true },
];

export const SizeMatrix: Story = {
  render: () => (
    <div className="flex flex-col gap-4 p-6 max-w-2xl">
      <h2 className="text-xl font-medium">Button sizes vs 44 px touch floor</h2>
      <p className="text-sm text-muted-foreground">
        Only the <code className="font-mono text-xs">xl</code> variant clears
        the Digital Agency 44 px hard rule on its own. Smaller variants must
        add padding (or a wrapper hit area) on touch viewports.
      </p>
      <div className="border rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-muted/30 text-xs text-muted-foreground">
            <tr>
              <th className="py-2 px-3 text-left">size</th>
              <th className="py-2 px-3 text-left">visual height</th>
              <th className="py-2 px-3 text-left">≥ 44 px?</th>
              <th className="py-2 px-3 text-left">button</th>
            </tr>
          </thead>
          <tbody>
            {SIZES.map(({ name, size, visualPx, ok }) => (
              <tr key={name} className="border-t border-border">
                <td className="py-2 px-3 font-mono text-xs">{name}</td>
                <td className="py-2 px-3 font-mono text-xs">{visualPx} px</td>
                <td className="py-2 px-3">
                  {ok ? (
                    <span className="text-success font-medium">✓ pass</span>
                  ) : (
                    <span className="text-attention">needs hit padding</span>
                  )}
                </td>
                <td className="py-2 px-3">
                  <Button size={size}>{name}</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  ),
};

export const HitAreaVisualization: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "When the visible button is smaller than 44 px, the actual hit area should still be 44 × 44. The dashed outline shows the required hit zone around a 28 px small button.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-6 p-8 items-start">
      <p className="text-sm text-muted-foreground max-w-md">
        The dashed box is the required <code className="font-mono text-xs">44 × 44</code> hit area around a visually <code className="font-mono text-xs">28 px sm</code> button. On touch viewports the wrapper or <code className="font-mono text-xs">::before</code> pseudo-element should expand to fill it.
      </p>
      <div
        className="relative inline-flex items-center justify-center border-2 border-dashed border-attention"
        style={{ width: "44px", height: "44px" }}
      >
        <Button size="sm">28</Button>
      </div>
      <p className="text-xs text-muted-foreground">
        Cited: Digital Agency button accessibility page · WCAG 2.1 SC 2.5.5 Target Size · Apple HIG 44 pt minimum.
      </p>
    </div>
  ),
};
