import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";

import { TagInput } from "../tag-input";
import { Label } from "../label";

const meta: Meta<typeof TagInput> = {
  title: "Shared/TagInput",
  component: TagInput,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Composite tag input — type then Enter to add a tag, Backspace removes the last tag, paste comma-separated for bulk.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof TagInput>;

export const Default: Story = {
  render: () => {
    const [tags, setTags] = useState<string[]>(["react", "typescript"]);
    return (
      <div className="flex flex-col gap-1.5 w-96">
        <Label>Tags</Label>
        <TagInput value={tags} onChange={setTags} />
      </div>
    );
  },
};

export const MaxTags: Story = {
  render: () => {
    const [tags, setTags] = useState<string[]>([]);
    return (
      <div className="flex flex-col gap-1.5 w-96">
        <Label>Categories (max 3)</Label>
        <TagInput value={tags} onChange={setTags} maxTags={3} />
      </div>
    );
  },
};

export const WithError: Story = {
  render: () => {
    const [tags, setTags] = useState<string[]>(["a"]);
    return (
      <div className="flex flex-col gap-1.5 w-96">
        <Label>Tags</Label>
        <TagInput value={tags} onChange={setTags} error="Add at least 2 tags" />
      </div>
    );
  },
};

export const Disabled: Story = {
  render: () => <TagInput value={["locked", "tag"]} disabled className="w-96" />,
};
