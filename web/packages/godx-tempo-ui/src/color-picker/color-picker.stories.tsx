import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { ColorPicker } from "../color-picker";
import { Label } from "../label";

const meta: Meta<typeof ColorPicker> = {
  title: "UI/ColorPicker",
  component: ColorPicker,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Color picker with preset grid + custom hex input. State is a hex string. Use for tag/category color selection in admin screens — for the broader design-system color palette see Foundations / Colors.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof ColorPicker>;

export const Default: Story = {
  render: () => {
    const [color, setColor] = useState("#3B82F6");
    return (
      <div className="flex flex-col gap-1.5 w-64">
        <Label>Tag color</Label>
        <ColorPicker value={color} onChange={setColor} />
        <span className="text-xs text-muted-foreground font-mono">{color}</span>
      </div>
    );
  },
};

export const PresetsOnly: Story = {
  render: () => {
    const [color, setColor] = useState("#10b981");
    return (
      <div className="flex flex-col gap-1.5 w-64">
        <Label>Category color</Label>
        <ColorPicker value={color} onChange={setColor} showInput={false} />
      </div>
    );
  },
};

export const InputOnly: Story = {
  render: () => {
    const [color, setColor] = useState("#b7282e");
    return (
      <div className="flex flex-col gap-1.5 w-64">
        <Label>Custom hex (茜)</Label>
        <ColorPicker value={color} onChange={setColor} showPresets={false} />
      </div>
    );
  },
};
