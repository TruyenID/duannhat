import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import { ChevronDown } from "lucide-react";

import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "../collapsible";
import { Button } from "../button";

const meta: Meta<typeof Collapsible> = {
  title: "UI/Collapsible",
  component: Collapsible,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Single show/hide region. The atomic primitive that `<Accordion>` is built on. Use directly when you need exactly one collapsible section, not a stack.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Collapsible>;

export const Default: Story = {
  render: () => {
    const [open, setOpen] = useState(false);
    return (
      <Collapsible open={open} onOpenChange={setOpen} className="w-80 space-y-2">
        <CollapsibleTrigger asChild>
          <Button variant="outline" className="w-full justify-between">
            Advanced options
            <ChevronDown className={open ? "rotate-180" : ""} />
          </Button>
        </CollapsibleTrigger>
        <CollapsibleContent className="rounded-md border p-3 text-sm space-y-1">
          <p>Hidden setting 1</p>
          <p>Hidden setting 2</p>
          <p>Hidden setting 3</p>
        </CollapsibleContent>
      </Collapsible>
    );
  },
};
