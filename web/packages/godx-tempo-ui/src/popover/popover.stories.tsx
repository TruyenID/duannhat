import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { CalendarIcon } from "lucide-react";

import { Popover, PopoverContent, PopoverTrigger } from "../popover";
import { Button } from "../button";
import { Input } from "../input";
import { Label } from "../label";

const meta: Meta<typeof Popover> = {
  title: "UI/Popover",
  component: Popover,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Floating panel anchored to a trigger element. Use for inline editing, filter pickers, date pickers, or any rich content that's too big for a tooltip but doesn't deserve a full Dialog interruption.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Popover>;

export const Default: Story = {
  render: () => (
    <Popover defaultOpen>
      <PopoverTrigger asChild>
        <Button variant="outline">Open popover</Button>
      </PopoverTrigger>
      <PopoverContent>
        <div className="space-y-2">
          <h4 className="text-sm font-medium">Dimensions</h4>
          <p className="text-xs text-muted-foreground">
            Set the dimensions for the layer.
          </p>
        </div>
      </PopoverContent>
    </Popover>
  ),
};

export const InlineEditor: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Common pattern: tap a value in a list, popover opens with an inline editor. Lighter than a full Dialog when the field is small.",
      },
    },
  },
  render: () => (
    <Popover defaultOpen>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm">
          <CalendarIcon />
          2026-04-10
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72">
        <div className="space-y-3">
          <h4 className="text-sm font-medium">Reschedule</h4>
          <div className="grid gap-1.5">
            <Label htmlFor="date" className="text-xs">Date</Label>
            <Input id="date" type="date" defaultValue="2026-04-10" />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="time" className="text-xs">Time</Label>
            <Input id="time" type="time" defaultValue="14:30" />
          </div>
          <Button size="sm" className="w-full">Save</Button>
        </div>
      </PopoverContent>
    </Popover>
  ),
};
