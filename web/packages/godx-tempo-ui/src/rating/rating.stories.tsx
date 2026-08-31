import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { Rating } from "../rating";

const meta: Meta<typeof Rating> = {
  title: "UI/Rating",
  component: Rating,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Star rating input. Supports 5-star (default) or any `max`, half-star precision via `allowHalf`, and read-only display mode for showing existing scores.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Rating>;

export const Default: Story = {
  render: () => {
    const [v, setV] = useState(3);
    return <Rating value={v} onChange={setV} />;
  },
};

export const HalfStars: Story = {
  render: () => {
    const [v, setV] = useState(3.5);
    return <Rating value={v} onChange={setV} allowHalf />;
  },
};

export const ReadOnly: Story = {
  render: () => <Rating value={4} readonly />,
};

export const Sizes: Story = {
  render: () => (
    <div className="flex flex-col gap-3">
      <Rating value={4} size="sm" readonly />
      <Rating value={4} size="md" readonly />
      <Rating value={4} size="lg" readonly />
    </div>
  ),
};
