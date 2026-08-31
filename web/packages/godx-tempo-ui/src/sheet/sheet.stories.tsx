import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "../sheet";
import { Button } from "../button";

const meta: Meta<typeof Sheet> = {
  title: "UI/Sheet",
  component: Sheet,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Side panel that slides in from any edge (top/right/bottom/left). Desktop-first — for mobile swipe-to-dismiss use `<Drawer>`. For centered modals use `<Dialog>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Sheet>;

export const Right: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild><Button variant="outline">Open right</Button></SheetTrigger>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>Filter products</SheetTitle>
          <SheetDescription>Refine the product list.</SheetDescription>
        </SheetHeader>
      </SheetContent>
    </Sheet>
  ),
};

export const Left: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild><Button variant="outline">Open left</Button></SheetTrigger>
      <SheetContent side="left">
        <SheetHeader>
          <SheetTitle>Navigation</SheetTitle>
        </SheetHeader>
      </SheetContent>
    </Sheet>
  ),
};

export const WithFooter: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild><Button>Open settings</Button></SheetTrigger>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>Settings</SheetTitle>
          <SheetDescription>Adjust your preferences.</SheetDescription>
        </SheetHeader>
        <SheetFooter>
          <Button>Save</Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  ),
};
