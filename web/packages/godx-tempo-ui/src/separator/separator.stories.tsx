import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { Separator } from "../separator";

const meta: Meta<typeof Separator> = {
  title: "UI/Separator",
  component: Separator,
  tags: ["autodocs"],
  argTypes: {
    orientation: { control: "select", options: ["horizontal", "vertical"] },
  },
  parameters: {
    docs: {
      description: {
        component:
          "Visual divider rendered as a 1 px line. Borders not shadows for hierarchy is the JP enterprise convention — use Separator liberally to chunk content within Cards instead of reaching for shadows.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Separator>;

export const Horizontal: Story = {
  render: () => (
    <div className="w-64">
      <p className="text-sm">Above the line</p>
      <Separator className="my-3" />
      <p className="text-sm">Below the line</p>
    </div>
  ),
};

export const Vertical: Story = {
  render: () => (
    <div className="flex h-5 items-center gap-3 text-sm">
      <span>Home</span>
      <Separator orientation="vertical" />
      <span>Products</span>
      <Separator orientation="vertical" />
      <span>Settings</span>
    </div>
  ),
};

export const InMetaList: Story = {
  parameters: {
    docs: {
      description: {
        story: "Common pattern: Separator between meta items in a card header (date · author · category).",
      },
    },
  },
  render: () => (
    <div className="flex h-4 items-center gap-2 text-xs text-muted-foreground">
      <span>2026-04-10</span>
      <Separator orientation="vertical" />
      <span>satoshi01</span>
      <Separator orientation="vertical" />
      <span>Engineering</span>
    </div>
  ),
};
