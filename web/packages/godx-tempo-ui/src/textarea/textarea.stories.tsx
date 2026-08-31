import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { Textarea } from "../textarea";
import type { TranslatableValue } from "../translatable-field";
import { Label } from "../label";

const meta: Meta<typeof Textarea> = {
  title: "UI/Textarea",
  component: Textarea,
  tags: ["autodocs"],
  argTypes: {
    placeholder: { control: "text" },
    disabled: { control: "boolean" },
  },
  parameters: {
    docs: {
      description: {
        component:
          "Multi-line text input with auto-growing height via CSS `field-sizing: content`. Supports the design system's `translatable` mode (per-locale tab bar) the same way `<Input>` does. For rich text (formatting, lists, embeds) use `<RichTextEditor>` from `@/components/shared/rich-text-editor` instead.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Textarea>;

// ─── Standard mode ──────────────────────────────────────────────────────────

export const Default: Story = {
  args: { placeholder: "Enter a description…" },
};

export const WithLabel: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-1.5 w-96">
      <Label htmlFor="desc">Description</Label>
      <Textarea id="desc" placeholder="Tell us about your product" />
    </div>
  ),
};

export const Filled: Story = {
  args: {
    defaultValue:
      "The auto-sizing means this textarea grows to fit its content without needing a manual rows prop or scrollbars. Type to see it grow.",
  },
};

export const Disabled: Story = {
  args: {
    disabled: true,
    defaultValue: "This textarea is read-only because the field is locked.",
  },
};

export const Invalid: Story = {
  args: {
    "aria-invalid": true,
    placeholder: "This field has an error",
  },
};

// ─── Translatable mode ──────────────────────────────────────────────────────

export const Translatable: Story = {
  parameters: {
    controls: { disable: true },
    docs: {
      description: {
        story:
          "Per-locale Textarea — the value is `Record<LocaleCode, string>` and the wrapper renders a locale tab bar. State updates per active locale; on submit, convert to the backend payload via `buildI18nPayload` from `@/i18n/translatable`.",
      },
    },
  },
  render: () => {
    const [value, setValue] = useState<TranslatableValue>({ ja: "", en: "", vi: "" });
    return (
      <div className="w-96 flex flex-col gap-1.5">
        <Label>Description</Label>
        <Textarea
          translatable
          value={value}
          onChange={setValue}
          placeholder="Mô tả sản phẩm"
        />
      </div>
    );
  },
};

export const TranslatableWithErrors: Story = {
  parameters: { controls: { disable: true } },
  render: () => {
    const [value, setValue] = useState<TranslatableValue>({
      ja: "正しい説明",
      en: "",
      vi: "x",
    });
    return (
      <div className="w-96">
        <Textarea
          translatable
          value={value}
          onChange={setValue}
          placeholder="Description"
          errors={{
            en: "English description is required",
            vi: "Too short — minimum 10 characters",
          }}
        />
      </div>
    );
  },
};
