import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { Bold } from "lucide-react";

import { Toggle } from "../toggle";

const meta: Meta<typeof Toggle> = {
  title: "UI/Toggle",
  component: Toggle,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Two-state on/off button (pressed / unpressed). Use for inline toolbar controls (bold, italic, underline). For boolean settings prefer `<Switch>`; for opt-in groups prefer `<ToggleGroup>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Toggle>;

export const Default: Story = {
  render: () => (
    <Toggle aria-label="Toggle bold"><Bold /></Toggle>
  ),
};

export const WithText: Story = {
  render: () => (
    <Toggle><Bold />Bold</Toggle>
  ),
};

export const Outline: Story = {
  render: () => (
    <Toggle variant="outline"><Bold /></Toggle>
  ),
};
