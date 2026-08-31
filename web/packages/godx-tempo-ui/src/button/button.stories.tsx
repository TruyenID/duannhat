import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { fn } from "storybook/test";
import { Save, Trash2 } from "lucide-react";

import { Button } from "../button";
import { Spinner } from "../spinner";

/**
 * Template story — refer to `frontend/plans/ui-library-roadmap.md` Phase 2
 * "Story quality bar" before adding new component stories. Every story file
 * MUST include: title, autodocs tag, ≥3 named stories, controls for tweakable
 * props, and zero console errors at render time.
 */
const meta: Meta<typeof Button> = {
  title: "UI/Button",
  component: Button,
  tags: ["autodocs"],
  args: {
    children: "Button",
    onClick: fn(),
  },
  argTypes: {
    variant: {
      control: "select",
      options: ["default", "destructive", "secondary", "outline", "soft", "ghost", "link"],
    },
    color: {
      control: "select",
      options: ["primary", "destructive", "success", "warning", "info"],
    },
    size: {
      control: "select",
      options: ["xs", "sm", "default", "lg", "xl", "icon"],
    },
    disabled: { control: "boolean" },
  },
  parameters: {
    docs: {
      description: {
        component:
          "Primary button primitive. Combines `variant` (visual style) and `color` (semantic intent) — see the props table for valid combinations. For loading affordance use `<Spinner>` from `@/components/ui/spinner` inside the button.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Button>;

// ─── Atomic states ──────────────────────────────────────────────────────────

export const Default: Story = {};

export const Destructive: Story = {
  args: { variant: "default", color: "destructive", children: "Delete" },
};

export const Disabled: Story = {
  args: { disabled: true, children: "Can't click" },
};

// ─── Variant matrix ─────────────────────────────────────────────────────────

export const Variants: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-wrap gap-2">
      <Button variant="default">Default</Button>
      <Button variant="destructive">Destructive</Button>
      <Button variant="secondary">Secondary</Button>
      <Button variant="outline">Outline</Button>
      <Button variant="soft">Soft</Button>
      <Button variant="ghost">Ghost</Button>
      <Button variant="link">Link</Button>
    </div>
  ),
};

export const Colors: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-3">
      {(["default", "outline", "soft", "ghost", "link"] as const).map((variant) => (
        <div key={variant} className="flex flex-wrap items-center gap-2">
          <span className="w-16 text-xs text-muted-foreground">{variant}</span>
          {(["primary", "destructive", "success", "warning", "info"] as const).map((color) => (
            <Button key={color} variant={variant} color={color}>
              {color}
            </Button>
          ))}
        </div>
      ))}
    </div>
  ),
};

export const Sizes: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex items-center gap-2">
      <Button size="xs">Extra small</Button>
      <Button size="sm">Small</Button>
      <Button size="default">Default</Button>
      <Button size="lg">Large</Button>
      <Button size="xl">Extra large</Button>
      <Button size="icon" aria-label="Save"><Save /></Button>
    </div>
  ),
};

// ─── With icons ─────────────────────────────────────────────────────────────

export const WithLeadingIcon: Story = {
  args: { children: <><Save />Save</> },
};

export const WithTrailingIcon: Story = {
  args: { variant: "destructive", children: <>Delete<Trash2 /></> },
};

// ─── Loading state — uses <Spinner>, never raw Loader2 ─────────────────────

export const Loading: Story = {
  args: {
    disabled: true,
    children: (
      <>
        <Spinner />
        Saving…
      </>
    ),
  },
  parameters: {
    docs: {
      description: {
        story:
          "Loading state uses `<Spinner>` (from `@/components/ui/spinner`) for the spinning indicator. Never import `Loader2` from `lucide-react` directly — `<Spinner>` ships with built-in `role=\"status\"` and `aria-label=\"Loading\"` for a11y.",
      },
    },
  },
};
