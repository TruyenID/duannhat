import type { Meta, StoryObj } from "@storybook/react-vite";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@godxjp/ui";

/**
 * Visual catalog for the 3 density modes wired up in `globals.css` (Phase C).
 *
 * Switch the active mode by setting `data-density="compact"` or `"comfortable"`
 * on any ancestor element (typically `<html>` or `<body>`). The default mode
 * has no attribute and is what the codebase ships with today.
 *
 * Source: design-foundations-japanese.md §"Density modes". Compact matches
 * kintone-style data-dense list views. Comfortable hits the Digital Agency
 * 44 px touch target floor without per-element padding hacks.
 */
const meta: Meta = {
  title: "Foundations/Density",
  parameters: {
    docs: {
      description: {
        component:
          "Three runtime-switchable density modes via `[data-density]` attribute. The same components shift control heights, card padding, page chrome, and table row heights — no component code changes required.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

function DensityPanel({ mode, label }: { mode: string; label: string }) {
  return (
    <div data-density={mode} className="flex flex-col gap-3">
      <div className="text-xs font-mono text-muted-foreground">
        data-density=&quot;{mode}&quot; — {label}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Button>Save</Button>
        <Button variant="outline">Cancel</Button>
        <Button variant="ghost">Skip</Button>
      </div>
      <Input placeholder="Type something..." />
      <Card>
        <CardHeader>
          <CardTitle>Card title</CardTitle>
          <CardDescription>Description sits below.</CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-sm">
            Same Card markup. Padding, height, and spacing all flow from the
            density tokens.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}

export const SideBySide: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "All three modes shown together for direct comparison. The same components — Button, Input, Card — adopt the active density tokens automatically.",
      },
    },
  },
  render: () => (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 p-6">
      <DensityPanel mode="compact" label="kintone-style dense" />
      <div className="flex flex-col gap-3">
        <div className="text-xs font-mono text-muted-foreground">
          (no attribute) — current default
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button>Save</Button>
          <Button variant="outline">Cancel</Button>
          <Button variant="ghost">Skip</Button>
        </div>
        <Input placeholder="Type something..." />
        <Card>
          <CardHeader>
            <CardTitle>Card title</CardTitle>
            <CardDescription>Description sits below.</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm">Default mode — no overrides.</p>
          </CardContent>
        </Card>
      </div>
      <DensityPanel mode="comfortable" label="44 px touch target floor" />
    </div>
  ),
};

export const Compact: Story = {
  render: () => (
    <div className="p-6">
      <DensityPanel mode="compact" label="kintone-style dense (28 px controls, 12 px card padding)" />
    </div>
  ),
};

export const Comfortable: Story = {
  render: () => (
    <div className="p-6">
      <DensityPanel
        mode="comfortable"
        label="public-facing 44 px touch target (Digital Agency hard rule)"
      />
    </div>
  ),
};
