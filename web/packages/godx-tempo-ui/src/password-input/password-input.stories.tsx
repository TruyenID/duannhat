import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { PasswordInput } from "../password-input";
import { Label } from "../label";

const meta: Meta<typeof PasswordInput> = {
  title: "UI/PasswordInput",
  component: PasswordInput,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Password input with built-in show/hide toggle. Inherits the same `size` variants as the base `<Input>` (xs/sm/default/lg/xl). Browser autofill UI is suppressed via CSS so the toggle eye stays the only reveal mechanism.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof PasswordInput>;

export const Default: Story = {
  args: { placeholder: "Enter password" },
};

export const Sizes: Story = {
  render: () => (
    <div className="flex flex-col gap-2 w-72">
      <PasswordInput size="sm" placeholder="sm" />
      <PasswordInput placeholder="default" />
      <PasswordInput size="lg" placeholder="lg" />
      <PasswordInput size="xl" placeholder="xl (login form)" />
    </div>
  ),
};

export const WithError: Story = {
  parameters: {
    docs: {
      description: {
        story: "Error prop: red message below + aria-invalid on the inner input.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-1.5 w-72">
      <Label htmlFor="pw">Password</Label>
      <PasswordInput
        id="pw"
        defaultValue="abc"
        error="Password must be at least 8 characters"
      />
    </div>
  ),
};

export const Disabled: Story = {
  render: () => (
    <PasswordInput disabled defaultValue="********" />
  ),
};
