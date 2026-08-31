import type { Meta, StoryObj } from "@storybook/react-vite";
import { useState } from "react";

import { TranslatableRichText } from "../translatable-rich-text";
import { Label } from "../label";
import type { TranslatableValue } from "../internal/ui-context";

const meta: Meta<typeof TranslatableRichText> = {
  title: "Shared/TranslatableRichText",
  component: TranslatableRichText,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Locale-tabbed rich text editor — composes `<TranslatableField>` with `<RichTextEditor>` as the per-locale render. State is `Record<LocaleCode, string>`. Used for `description`-style translatable fields where Tiptap formatting matters.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof TranslatableRichText>;

export const Default: Story = {
  render: () => {
    const [v, setV] = useState<TranslatableValue>({ ja: "", en: "", vi: "" });
    return (
      <div className="w-[480px] space-y-1.5">
        <Label>Product description</Label>
        <TranslatableRichText value={v} onChange={setV} />
      </div>
    );
  },
};
