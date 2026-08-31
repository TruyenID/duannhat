import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import { expect, fn, userEvent, within } from "storybook/test";

import { Checkbox } from "../checkbox";
import { Label } from "../label";

const meta: Meta<typeof Checkbox> = {
  title: "UI/Checkbox",
  component: Checkbox,
  tags: ["autodocs"],
  args: {
    onCheckedChange: fn(),
  },
  parameters: {
    docs: {
      description: {
        component:
          "Checkable input for selecting one or more options from a set. Built on Radix UI Checkbox so it supports checked / unchecked / indeterminate states out of the box. Always pair with a `<Label>` for accessibility.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Checkbox>;

export const Default: Story = {};

export const Checked: Story = {
  args: { defaultChecked: true },
};

export const Indeterminate: Story = {
  args: { checked: "indeterminate" },
};

export const Disabled: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-3">
      <Label htmlFor="d1" className="gap-2">
        <Checkbox id="d1" disabled />
        Unchecked + disabled
      </Label>
      <Label htmlFor="d2" className="gap-2">
        <Checkbox id="d2" disabled defaultChecked />
        Checked + disabled
      </Label>
    </div>
  ),
};

export const WithLabel: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <Label htmlFor="terms" className="gap-2">
      <Checkbox id="terms" />
      I accept the terms and conditions
    </Label>
  ),
};

export const ControlledList: Story = {
  parameters: {
    controls: { disable: true },
    docs: {
      description: {
        story:
          "Controlled checkbox group — clicking each row toggles its state and the parent state list re-renders. Pattern used by category pickers, permission grids, etc.",
      },
    },
  },
  render: () => {
    const items = ["Apple", "Banana", "Cherry", "Durian"];
    const [selected, setSelected] = useState<Set<string>>(new Set(["Apple"]));
    return (
      <div className="flex flex-col gap-2">
        {items.map((item) => (
          <Label key={item} htmlFor={item} className="gap-2 cursor-pointer">
            <Checkbox
              id={item}
              checked={selected.has(item)}
              onCheckedChange={(c) => {
                setSelected((prev) => {
                  const next = new Set(prev);
                  if (c === true) next.add(item);
                  else next.delete(item);
                  return next;
                });
              }}
            />
            {item}
          </Label>
        ))}
        <p className="mt-2 text-xs text-muted-foreground">
          Selected: {Array.from(selected).join(", ") || "(none)"}
        </p>
      </div>
    );
  },
};

// ─── Interaction test (browser-mode) ────────────────────────────────────────

export const ClickToggles: Story = {
  parameters: { controls: { disable: true } },
  render: (args) => (
    <Label className="gap-2">
      <Checkbox aria-label="terms" {...args} />
      Click me
    </Label>
  ),
  play: async ({ canvasElement, args }) => {
    const canvas = within(canvasElement);
    const checkbox = canvas.getByRole("checkbox");
    await userEvent.click(checkbox);
    expect(args.onCheckedChange).toHaveBeenCalledTimes(1);
    expect(args.onCheckedChange).toHaveBeenCalledWith(true);
  },
};
