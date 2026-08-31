import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { AlertCircle, CheckCircle2, Info } from "lucide-react";

import { Alert, AlertDescription, AlertTitle } from "../alert";

const meta: Meta<typeof Alert> = {
  title: "UI/Alert",
  component: Alert,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Inline informational message — banners for system events, form-level errors, or page-level notifications. For transient toast messages use `<Toaster>` (sonner) instead.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Alert>;

export const Default: Story = {
  render: () => (
    <Alert className="w-96">
      <Info />
      <AlertTitle>Heads up!</AlertTitle>
      <AlertDescription>You can add icons inside an alert via the lucide library.</AlertDescription>
    </Alert>
  ),
};

export const Destructive: Story = {
  render: () => (
    <Alert variant="destructive" className="w-96">
      <AlertCircle />
      <AlertTitle>Error</AlertTitle>
      <AlertDescription>Your session has expired. Please log in again.</AlertDescription>
    </Alert>
  ),
};

export const Success: Story = {
  render: () => (
    <Alert className="w-96 border-success/50 text-success">
      <CheckCircle2 />
      <AlertTitle>Saved</AlertTitle>
      <AlertDescription>Your changes were saved successfully.</AlertDescription>
    </Alert>
  ),
};
