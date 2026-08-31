import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { Combobox, MultiCombobox } from "../combobox";
import { Label } from "../label";

const meta: Meta<typeof Combobox> = {
  title: "UI/Combobox",
  component: Combobox,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Searchable single-select built on cmdk + Popover. Use when the option list is too long for a `<Select>` (~10+ items) and the user benefits from typing to filter. For multi-select, use `<MultiCombobox>` from the same module.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Combobox>;

const FRAMEWORKS = [
  { value: "react", label: "React" },
  { value: "vue", label: "Vue" },
  { value: "svelte", label: "Svelte" },
  { value: "solid", label: "Solid" },
  { value: "qwik", label: "Qwik" },
  { value: "angular", label: "Angular" },
  { value: "preact", label: "Preact" },
];

export const Default: Story = {
  render: () => {
    const [value, setValue] = useState("");
    return (
      <div className="flex flex-col gap-1.5 w-72">
        <Label>Framework</Label>
        <Combobox
          options={FRAMEWORKS}
          value={value}
          onChange={setValue}
          placeholder="Select framework..."
        />
      </div>
    );
  },
};

export const Clearable: Story = {
  render: () => {
    const [value, setValue] = useState("react");
    return (
      <div className="flex flex-col gap-1.5 w-72">
        <Label>Framework</Label>
        <Combobox options={FRAMEWORKS} value={value} onChange={setValue} clearable />
      </div>
    );
  },
};

export const Multi: Story = {
  parameters: {
    docs: {
      description: {
        story: "MultiCombobox — same options shape, value is `string[]`. The trigger shows count when more than one item is selected.",
      },
    },
  },
  render: () => {
    const [values, setValues] = useState<string[]>(["react", "svelte"]);
    return (
      <div className="flex flex-col gap-1.5 w-72">
        <Label>Frameworks</Label>
        <MultiCombobox
          options={FRAMEWORKS}
          value={values}
          onChange={setValues}
          maxSelected={3}
        />
      </div>
    );
  },
};
