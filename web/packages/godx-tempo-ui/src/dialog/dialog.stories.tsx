import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "../dialog";
import { Button } from "../button";
import { Input } from "../input";
import { Label } from "../label";

const meta: Meta<typeof Dialog> = {
  title: "UI/Dialog",
  component: Dialog,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Modal dialog for forms, content viewers, and non-destructive confirmations. Click-outside and Escape both dismiss. For destructive actions use `<AlertDialog>` instead — see the contrastive doc on each overlay primitive.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Dialog>;

export const Default: Story = {
  render: () => (
    <Dialog>
      <DialogTrigger asChild>
        <Button>Open dialog</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit profile</DialogTitle>
          <DialogDescription>
            Make changes to your profile here. Click save when you&apos;re done.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 py-2">
          <div className="grid gap-1.5">
            <Label htmlFor="name">Name</Label>
            <Input id="name" defaultValue="Pham Thai Duong" />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" defaultValue="duong@example.com" />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline">Cancel</Button>
          <Button>Save changes</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
};

export const Controlled: Story = {
  parameters: {
    docs: {
      description: {
        story: "Controlled mode via `open` + `onOpenChange` props for programmatic open/close.",
      },
    },
  },
  render: () => {
    const [open, setOpen] = useState(false);
    return (
      <>
        <Button onClick={() => setOpen(true)}>Open programmatically</Button>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Controlled dialog</DialogTitle>
              <DialogDescription>State lives in the parent.</DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button onClick={() => setOpen(false)}>Close</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </>
    );
  },
};

export const InfoDialog: Story = {
  parameters: {
    docs: {
      description: {
        story: "Plain content viewer — no form. Footer with single dismiss action.",
      },
    },
  },
  render: () => (
    <Dialog defaultOpen>
      <DialogTrigger asChild>
        <Button variant="outline">View details</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>About TempoFast</DialogTitle>
          <DialogDescription>
            TempoFast is the next-generation restaurant management platform.
            Built for Japanese enterprise users with kintone-grade density
            and SmartHR-grade design tokens.
          </DialogDescription>
        </DialogHeader>
        <p className="text-sm text-muted-foreground">
          The design system inside this dialog reflects the foundation work in{" "}
          <code className="text-xs">plans/design-foundations-japanese.md</code>.
        </p>
        <DialogFooter>
          <Button>Got it</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
};
