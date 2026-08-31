import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useEffect, useState } from "react";

import { Progress } from "../progress";

const meta: Meta<typeof Progress> = {
  title: "UI/Progress",
  component: Progress,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Determinate progress bar — value 0–100. For indeterminate loading use `<Spinner>` instead.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Progress>;

export const Default: Story = {
  render: () => <Progress value={45} className="w-72" />,
};

export const Animated: Story = {
  render: () => {
    const [v, setV] = useState(0);
    useEffect(() => {
      const id = setInterval(() => setV((p) => (p >= 100 ? 0 : p + 5)), 200);
      return () => clearInterval(id);
    }, []);
    return <Progress value={v} className="w-72" />;
  },
};

export const Stages: Story = {
  render: () => (
    <div className="space-y-2 w-72">
      {[10, 25, 50, 75, 100].map((v) => (
        <div key={v} className="flex items-center gap-3">
          <span className="text-xs w-8">{v}%</span>
          <Progress value={v} className="flex-1" />
        </div>
      ))}
    </div>
  ),
};
