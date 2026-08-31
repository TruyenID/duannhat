import type { Meta, StoryObj } from "@storybook/react-vite";

import { StatusBadge } from "../status-badge";

const meta: Meta<typeof StatusBadge> = {
  title: "Shared/StatusBadge",
  component: StatusBadge,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Domain-aware status pill — renders the right Badge color/variant for a known status string. Used across product / order / inventory list views.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof StatusBadge>;

export const All: Story = {
  render: () => (
    <div className="flex flex-wrap gap-2">
      {["active", "inactive", "draft", "pending", "approved", "rejected"].map((s) => (
        <StatusBadge key={s} status={s} />
      ))}
    </div>
  ),
};
