import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { Input } from "../input";
import type { TranslatableValue } from "../translatable-field";

/**
 * Template story — covers the design system's two Input modes (standard +
 * translatable). Translatable mode requires the AppProvider locale config
 * which `.storybook/preview.tsx` already mounts globally.
 */
const meta: Meta<typeof Input> = {
  title: "UI/Input",
  component: Input,
  tags: ["autodocs"],
  argTypes: {
    size: {
      control: "select",
      options: ["xs", "sm", "default", "lg", "xl"],
    },
    placeholder: { control: "text" },
    disabled: { control: "boolean" },
  },
  parameters: {
    docs: {
      description: {
        component:
          "Text input primitive. Standard mode is a controlled string. Setting the `translatable` prop switches to per-locale mode — the value becomes `Record<LocaleCode, string>` and the input renders a locale tab bar. Backend rules for translatable fields live in `AGENTS.md`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Input>;

// ─── Standard mode ──────────────────────────────────────────────────────────

export const Default: Story = {
  args: { placeholder: "Enter text" },
};

export const Sizes: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-2 w-72">
      <Input size="xs" placeholder="xs (24px)" />
      <Input size="sm" placeholder="sm (28px)" />
      <Input placeholder="default (32px)" />
      <Input size="lg" placeholder="lg (36px)" />
      <Input size="xl" placeholder="xl (44px)" />
    </div>
  ),
};

export const Disabled: Story = {
  args: { disabled: true, value: "Read only", placeholder: "Disabled" },
};

export const Invalid: Story = {
  args: { "aria-invalid": true, placeholder: "Has an error" },
};

// ─── Translatable mode (locale tab bar) ────────────────────────────────────

export const Translatable: Story = {
  parameters: {
    controls: { disable: true },
    docs: {
      description: {
        story:
          "When `translatable` is set, the input value becomes `Record<LocaleCode, string>` and the design system renders a tab bar so the user can edit each locale independently. The locale list comes from `UIProvider` (mounted globally via `AppProvider` in `preview.tsx`). On submit, convert this map into the backend's nested locale-keyed payload via `buildI18nPayload()` from `@/i18n/translatable`.",
      },
    },
  },
  render: () => {
    const [value, setValue] = useState<TranslatableValue>({ ja: "", en: "", vi: "" });
    return (
      <div className="w-80">
        <Input
          translatable
          value={value}
          onChange={setValue}
          placeholder="Tên sản phẩm"
        />
        <pre className="mt-3 text-[10px] text-muted-foreground">
          {JSON.stringify(value, null, 2)}
        </pre>
      </div>
    );
  },
};

export const TranslatableWithErrors: Story = {
  parameters: {
    controls: { disable: true },
    docs: {
      description: {
        story:
          "Per-locale validation errors via the `errors` prop on translatable mode. Each locale tab gets a red dot indicator when its entry has an error.",
      },
    },
  },
  render: () => {
    const [value, setValue] = useState<TranslatableValue>({
      ja: "正しい",
      en: "",
      vi: "x",
    });
    return (
      <div className="w-80">
        <Input
          translatable
          value={value}
          onChange={setValue}
          placeholder="Product name"
          errors={{
            en: "English translation is required",
            vi: "Too short (minimum 2 characters)",
          }}
        />
      </div>
    );
  },
};
