import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { Slider } from "../slider";
import { Label } from "../label";

const meta: Meta<typeof Slider> = {
  title: "UI/Slider",
  component: Slider,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Numeric range input with one or more thumbs. Pass a single-element array for single-value mode, two elements for range mode.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Slider>;

export const Single: Story = {
  render: () => {
    const [v, setV] = useState([50]);
    return (
      <div className="w-80 space-y-2">
        <Label>Volume: {v[0]}%</Label>
        <Slider value={v} onValueChange={setV} max={100} step={1} />
      </div>
    );
  },
};

export const Range: Story = {
  render: () => {
    const [v, setV] = useState([20, 80]);
    return (
      <div className="w-80 space-y-2">
        <Label>Price range: ¥{v[0] * 100} – ¥{v[1] * 100}</Label>
        <Slider value={v} onValueChange={setV} max={100} step={1} />
      </div>
    );
  },
};

export const Stepped: Story = {
  render: () => {
    const [v, setV] = useState([4]);
    return (
      <div className="w-80 space-y-2">
        <Label>Servings: {v[0]}</Label>
        <Slider value={v} onValueChange={setV} min={1} max={10} step={1} />
      </div>
    );
  },
};
