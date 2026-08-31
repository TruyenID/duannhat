import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { toast } from "sonner";

import { Toaster } from "../sonner";
import { Button } from "../button";

const meta: Meta<typeof Toaster> = {
  title: "UI/Toaster",
  component: Toaster,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Sonner-powered toast notification system. Mount `<Toaster />` once at the app root (already done in `layout.tsx`); call `toast()` from anywhere to show a transient message. Use for non-blocking confirmations and async operation results.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Toaster>;

export const Demo: Story = {
  render: () => (
    <div className="flex flex-wrap gap-2">
      <Toaster />
      <Button onClick={() => toast("Saved successfully")}>
        Default toast
      </Button>
      <Button variant="outline" onClick={() => toast.success("Order created")}>
        Success
      </Button>
      <Button variant="outline" onClick={() => toast.error("Something went wrong")}>
        Error
      </Button>
      <Button variant="outline" onClick={() => toast.info("Sync in progress")}>
        Info
      </Button>
      <Button variant="outline" onClick={() => toast.warning("Low stock warning")}>
        Warning
      </Button>
    </div>
  ),
};
