import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { Check, X } from "lucide-react";

import { Badge } from "../badge";

const meta: Meta<typeof Badge> = {
  title: "UI/Badge",
  component: Badge,
  tags: ["autodocs"],
  args: { children: "Badge" },
  argTypes: {
    variant: {
      control: "select",
      options: ["default", "destructive", "secondary", "outline", "soft"],
    },
    color: {
      control: "select",
      options: ["primary", "destructive", "success", "warning", "info"],
    },
  },
  parameters: {
    docs: {
      description: {
        component:
          "Inline status descriptor. Combines `variant` (visual style: solid / outline / soft / secondary / legacy destructive) with `color` (semantic intent: primary / destructive / success / warning / info). Use `<Badge variant=\"soft\" color=\"success\">` for the design system's standard \"approved\" pill, etc.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Badge>;

export const Default: Story = {};

export const Variants: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-wrap gap-2">
      <Badge>Default</Badge>
      <Badge variant="destructive">Destructive</Badge>
      <Badge variant="secondary">Secondary</Badge>
      <Badge variant="outline">Outline</Badge>
      <Badge variant="soft">Soft</Badge>
    </div>
  ),
};

export const Colors: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-3">
      {(["default", "outline", "soft"] as const).map((variant) => (
        <div key={variant} className="flex flex-wrap items-center gap-2">
          <span className="w-16 text-xs text-muted-foreground">{variant}</span>
          {(["primary", "destructive", "success", "warning", "info"] as const).map((color) => (
            <Badge key={color} variant={variant} color={color}>
              {color}
            </Badge>
          ))}
        </div>
      ))}
    </div>
  ),
};

export const WithIcon: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-wrap gap-2">
      <Badge color="success"><Check />Approved</Badge>
      <Badge color="destructive"><X />Rejected</Badge>
      <Badge variant="soft" color="warning">Pending review</Badge>
    </div>
  ),
};

export const StatusList: Story = {
  parameters: {
    controls: { disable: true },
    docs: {
      description: {
        story:
          "Soft variant is the design system default for status pills inside tables and lists — high information density without dominating the row.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-2 w-72">
      <div className="flex items-center justify-between">
        <span className="text-sm">Order #1042</span>
        <Badge variant="soft" color="success">Delivered</Badge>
      </div>
      <div className="flex items-center justify-between">
        <span className="text-sm">Order #1043</span>
        <Badge variant="soft" color="warning">In transit</Badge>
      </div>
      <div className="flex items-center justify-between">
        <span className="text-sm">Order #1044</span>
        <Badge variant="soft" color="destructive">Cancelled</Badge>
      </div>
      <div className="flex items-center justify-between">
        <span className="text-sm">Order #1045</span>
        <Badge variant="soft" color="info">Processing</Badge>
      </div>
    </div>
  ),
};
