import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import { Skeleton } from "../skeleton";
import { Card, CardContent, CardHeader } from "../card";

const meta: Meta<typeof Skeleton> = {
  title: "UI/Skeleton",
  component: Skeleton,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Placeholder block with a pulse animation while content is loading. Use to preserve layout during fetches — prevents content jump when data arrives. Compose with `className` for any size/shape.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Skeleton>;

export const TextLine: Story = {
  args: { className: "h-4 w-48" },
};

export const Circle: Story = {
  args: { className: "size-12 rounded-full" },
};

export const Paragraph: Story = {
  render: () => (
    <div className="space-y-2 w-72">
      <Skeleton className="h-4 w-full" />
      <Skeleton className="h-4 w-11/12" />
      <Skeleton className="h-4 w-3/4" />
      <Skeleton className="h-4 w-5/6" />
    </div>
  ),
};

export const CardSkeleton: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Full Card skeleton — preserves the exact final layout so the page doesn't jump when data lands.",
      },
    },
  },
  render: () => (
    <Card className="w-80">
      <CardHeader>
        <Skeleton className="h-5 w-32" />
        <Skeleton className="h-3 w-48 mt-1" />
      </CardHeader>
      <CardContent className="space-y-2">
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-5/6" />
        <Skeleton className="h-3 w-4/6" />
      </CardContent>
    </Card>
  ),
};

export const ListSkeleton: Story = {
  render: () => (
    <div className="space-y-3 w-80">
      {Array.from({ length: 4 }, (_, i) => (
        <div key={i} className="flex items-center gap-3">
          <Skeleton className="size-10 rounded-full" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3 w-1/2" />
            <Skeleton className="h-3 w-3/4" />
          </div>
        </div>
      ))}
    </div>
  ),
};
