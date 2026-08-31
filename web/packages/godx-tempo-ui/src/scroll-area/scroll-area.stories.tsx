import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { ScrollArea } from "../scroll-area";

const meta: Meta<typeof ScrollArea> = {
  title: "UI/ScrollArea",
  component: ScrollArea,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Custom scrollable container with cross-platform consistent scrollbars (Radix). Use when you want native-feeling scroll inside a fixed-size region — sidebar lists, dropdown panels, long tag lists.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof ScrollArea>;

export const Default: Story = {
  render: () => (
    <ScrollArea className="h-48 w-72 rounded-md border p-4 text-sm">
      {Array.from({ length: 30 }, (_, i) => (
        <p key={i} className="py-1">Item {i + 1}</p>
      ))}
    </ScrollArea>
  ),
};

export const Horizontal: Story = {
  render: () => (
    <ScrollArea className="w-96 whitespace-nowrap rounded-md border">
      <div className="flex gap-3 p-4">
        {Array.from({ length: 12 }, (_, i) => (
          <div key={i} className="size-24 shrink-0 rounded-md bg-muted flex items-center justify-center text-xs">
            Tile {i + 1}
          </div>
        ))}
      </div>
    </ScrollArea>
  ),
};
