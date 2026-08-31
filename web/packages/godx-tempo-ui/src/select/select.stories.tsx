import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from "../select";
import { Label } from "../label";

const meta: Meta<typeof Select> = {
  title: "UI/Select",
  component: Select,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Compound select dropdown built on Radix Select. For long lists where users would benefit from typing to filter, use `<Combobox>` instead. Note: error prop on `<SelectTrigger>` is deferred — see Phase 0 Wave 3 in plans/ui-library-roadmap.md.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Select>;

export const Default: Story = {
  render: () => (
    <div className="flex flex-col gap-1.5 w-72">
      <Label>Status</Label>
      <Select>
        <SelectTrigger>
          <SelectValue placeholder="Choose a status..." />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="draft">Draft</SelectItem>
          <SelectItem value="active">Active</SelectItem>
          <SelectItem value="inactive">Inactive</SelectItem>
        </SelectContent>
      </Select>
    </div>
  ),
};

export const WithGroups: Story = {
  render: () => (
    <Select defaultValue="apple">
      <SelectTrigger className="w-72">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          <SelectLabel>Fruits</SelectLabel>
          <SelectItem value="apple">Apple</SelectItem>
          <SelectItem value="banana">Banana</SelectItem>
          <SelectItem value="cherry">Cherry</SelectItem>
        </SelectGroup>
        <SelectSeparator />
        <SelectGroup>
          <SelectLabel>Vegetables</SelectLabel>
          <SelectItem value="carrot">Carrot</SelectItem>
          <SelectItem value="potato">Potato</SelectItem>
        </SelectGroup>
      </SelectContent>
    </Select>
  ),
};

export const Controlled: Story = {
  render: () => {
    const [value, setValue] = useState("");
    return (
      <div className="flex flex-col gap-1.5 w-72">
        <Label>Locale</Label>
        <Select value={value} onValueChange={setValue}>
          <SelectTrigger>
            <SelectValue placeholder="Pick a locale" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="ja">日本語</SelectItem>
            <SelectItem value="en">English</SelectItem>
            <SelectItem value="vi">Tiếng Việt</SelectItem>
          </SelectContent>
        </Select>
        <span className="text-xs text-muted-foreground">Selected: {value || "(none)"}</span>
      </div>
    );
  },
};

export const Sizes: Story = {
  render: () => (
    <div className="flex flex-col gap-2">
      <Select>
        <SelectTrigger size="sm" className="w-72"><SelectValue placeholder="Small" /></SelectTrigger>
        <SelectContent>
          <SelectItem value="a">Option A</SelectItem>
        </SelectContent>
      </Select>
      <Select>
        <SelectTrigger className="w-72"><SelectValue placeholder="Default" /></SelectTrigger>
        <SelectContent>
          <SelectItem value="a">Option A</SelectItem>
        </SelectContent>
      </Select>
    </div>
  ),
};

export const Disabled: Story = {
  render: () => (
    <Select disabled>
      <SelectTrigger className="w-72">
        <SelectValue placeholder="Locked" />
      </SelectTrigger>
      <SelectContent />
    </Select>
  ),
};
