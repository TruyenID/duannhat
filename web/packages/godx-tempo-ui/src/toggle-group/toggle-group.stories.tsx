import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { AlignCenter, AlignLeft, AlignRight, Bold, Italic, Underline } from "lucide-react";

import { ToggleGroup, ToggleGroupItem } from "../toggle-group";

const meta: Meta<typeof ToggleGroup> = {
  title: "UI/ToggleGroup",
  component: ToggleGroup,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Group of `<Toggle>` buttons sharing exclusive (`type=\"single\"`) or inclusive (`type=\"multiple\"`) state. Common for text formatting toolbars and view-mode selectors.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof ToggleGroup>;

export const Multiple: Story = {
  render: () => (
    <ToggleGroup type="multiple">
      <ToggleGroupItem value="bold" aria-label="Bold"><Bold /></ToggleGroupItem>
      <ToggleGroupItem value="italic" aria-label="Italic"><Italic /></ToggleGroupItem>
      <ToggleGroupItem value="underline" aria-label="Underline"><Underline /></ToggleGroupItem>
    </ToggleGroup>
  ),
};

export const Single: Story = {
  render: () => (
    <ToggleGroup type="single" defaultValue="left">
      <ToggleGroupItem value="left" aria-label="Align left"><AlignLeft /></ToggleGroupItem>
      <ToggleGroupItem value="center" aria-label="Align center"><AlignCenter /></ToggleGroupItem>
      <ToggleGroupItem value="right" aria-label="Align right"><AlignRight /></ToggleGroupItem>
    </ToggleGroup>
  ),
};
