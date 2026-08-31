import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { Label } from "../label";
import { Input } from "../input";
import { Checkbox } from "../checkbox";

const meta: Meta<typeof Label> = {
  title: "UI/Label",
  component: Label,
  tags: ["autodocs"],
  args: {
    children: "Email address",
  },
  parameters: {
    docs: {
      description: {
        component:
          "Accessible label that auto-associates with a form control via `htmlFor`. Built on Radix UI Label so it also reacts to peer/group `disabled` state through CSS.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Label>;

export const Default: Story = {};

export const WithInput: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-1.5 w-72">
      <Label htmlFor="email">Email address</Label>
      <Input id="email" type="email" placeholder="you@example.com" />
    </div>
  ),
};

export const WithCheckbox: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <Label htmlFor="terms" className="gap-2">
      <Checkbox id="terms" />
      Accept terms and conditions
    </Label>
  ),
};

export const Required: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-1.5 w-72">
      <Label htmlFor="name">
        Name
        <span className="text-red-500">*</span>
      </Label>
      <Input id="name" placeholder="Required" />
    </div>
  ),
};

export const Disabled: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-1.5 w-72">
      <Label htmlFor="locked">Locked field</Label>
      <Input id="locked" disabled value="Read only" />
    </div>
  ),
};
