import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";

import { RichTextEditor } from "../rich-text-editor";
import { Label } from "../label";

const meta: Meta<typeof RichTextEditor> = {
  title: "Shared/RichTextEditor",
  component: RichTextEditor,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Tiptap-based rich text editor (bold, italic, headings, lists, code, blockquote). Outputs HTML. For per-locale rich text use `<TranslatableRichText>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof RichTextEditor>;

export const Default: Story = {
  render: () => {
    const [value, setValue] = useState("<p>Edit me — type to test the toolbar.</p>");
    return (
      <div className="w-[480px] space-y-1.5">
        <Label>Description</Label>
        <RichTextEditor value={value} onChange={setValue} />
      </div>
    );
  },
};

export const ReadOnly: Story = {
  render: () => (
    <div className="w-[480px]">
      <RichTextEditor
        editable={false}
        value="<p>This editor is locked.</p><ul><li>read-only mode</li><li>still styled</li></ul>"
      />
    </div>
  ),
};
