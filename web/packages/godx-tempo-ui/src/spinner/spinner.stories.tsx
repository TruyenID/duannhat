import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { Spinner } from "../spinner";
import { Button } from "../button";

const meta: Meta<typeof Spinner> = {
  title: "UI/Spinner",
  component: Spinner,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "The canonical loading indicator. Wraps `Loader2Icon` with built-in `role=\"status\"` + `aria-label=\"Loading\"` for accessibility. **Always import from `@/components/ui/spinner` — never use raw `Loader2` from `lucide-react`** (enforced by ESLint `no-restricted-imports`).",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Spinner>;

export const Default: Story = {};

export const Sizes: Story = {
  render: () => (
    <div className="flex items-center gap-4">
      <Spinner className="size-3" />
      <Spinner className="size-4" />
      <Spinner className="size-5" />
      <Spinner className="size-6" />
      <Spinner className="size-8" />
    </div>
  ),
};

export const Colors: Story = {
  render: () => (
    <div className="flex items-center gap-4">
      <Spinner className="text-foreground" />
      <Spinner className="text-muted-foreground" />
      <Spinner className="text-primary" />
      <Spinner className="text-destructive" />
    </div>
  ),
};

export const InsideButton: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Inside a Button during async actions. The Spinner inherits the button's text color via `currentColor`, so no extra styling is needed.",
      },
    },
  },
  render: () => (
    <div className="flex items-center gap-3">
      <Button disabled>
        <Spinner />
        Saving…
      </Button>
      <Button variant="outline" disabled>
        <Spinner />
        Loading
      </Button>
      <Button variant="destructive" disabled>
        <Spinner />
        Deleting
      </Button>
    </div>
  ),
};
