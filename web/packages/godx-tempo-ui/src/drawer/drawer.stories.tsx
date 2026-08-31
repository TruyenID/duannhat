import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Drawer,
  DrawerBody,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "../drawer";
import { Button } from "../button";

const meta: Meta<typeof Drawer> = {
  title: "UI/Drawer",
  component: Drawer,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Vaul-powered swipeable drawer. Mobile-first — slides up from the bottom by default. For desktop side panels (no swipe) use `<Sheet>`. For centered modals use `<Dialog>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Drawer>;

export const Default: Story = {
  render: () => (
    <Drawer>
      <DrawerTrigger asChild><Button variant="outline">Open drawer</Button></DrawerTrigger>
      <DrawerContent>
        <DrawerHeader>
          <DrawerTitle>Move task</DrawerTitle>
          <DrawerDescription>Pick a new column for this task.</DrawerDescription>
        </DrawerHeader>
        <DrawerBody>
          <p className="text-sm">Drag the handle at the top to dismiss.</p>
        </DrawerBody>
        <DrawerFooter>
          <Button>Save</Button>
        </DrawerFooter>
      </DrawerContent>
    </Drawer>
  ),
};
