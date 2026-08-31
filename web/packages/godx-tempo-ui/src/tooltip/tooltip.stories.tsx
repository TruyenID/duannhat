import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { InfoIcon, SettingsIcon } from "lucide-react";

import { Tooltip, TooltipContent, TooltipTrigger } from "../tooltip";
import { Button } from "../button";

const meta: Meta<typeof Tooltip> = {
  title: "UI/Tooltip",
  component: Tooltip,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Floating label that appears on hover/focus. Built on Radix UI Tooltip with a built-in zero-delay `TooltipProvider`. Use for explaining icon-only buttons or providing extra context that would clutter the visible UI.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Tooltip>;

export const Default: Story = {
  render: () => (
    <Tooltip defaultOpen>
      <TooltipTrigger asChild>
        <Button variant="outline">Hover me</Button>
      </TooltipTrigger>
      <TooltipContent>
        <p>Tooltip content</p>
      </TooltipContent>
    </Tooltip>
  ),
};

export const OnIconButton: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Most common use: explaining what an icon-only button does. Required for a11y when there's no visible label.",
      },
    },
  },
  render: () => (
    <div className="flex items-center gap-2">
      <Tooltip defaultOpen>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="icon" aria-label="Information">
            <InfoIcon />
          </Button>
        </TooltipTrigger>
        <TooltipContent>
          <p>Show more details about this row</p>
        </TooltipContent>
      </Tooltip>

      <Tooltip>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="icon" aria-label="Settings">
            <SettingsIcon />
          </Button>
        </TooltipTrigger>
        <TooltipContent>
          <p>Open settings</p>
        </TooltipContent>
      </Tooltip>
    </div>
  ),
};

export const Sides: Story = {
  parameters: {
    docs: {
      description: {
        story: "Tooltips can anchor to any side of their trigger via the `side` prop on `TooltipContent`.",
      },
    },
  },
  render: () => (
    <div className="grid grid-cols-2 gap-12 p-12">
      {(["top", "right", "bottom", "left"] as const).map((side) => (
        <Tooltip key={side} defaultOpen>
          <TooltipTrigger asChild>
            <Button variant="outline">{side}</Button>
          </TooltipTrigger>
          <TooltipContent side={side}>
            <p>{side} tooltip</p>
          </TooltipContent>
        </Tooltip>
      ))}
    </div>
  ),
};
