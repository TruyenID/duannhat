import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import type { DateRange } from "react-day-picker";

import { DatePicker, DateRangePicker } from "../date-picker";
import { Label } from "../label";

const meta: Meta<typeof DatePicker> = {
  title: "UI/DatePicker",
  component: DatePicker,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Popover-anchored date picker — the form-friendly variant of `<Calendar>`. Locale-aware via `date-fns` (auto-localized to Japanese when the app's UIProvider is set to `ja`).",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof DatePicker>;

export const Single: Story = {
  render: () => {
    const [date, setDate] = useState<Date | undefined>();
    return (
      <div className="flex flex-col gap-1.5 w-72">
        <Label>Order date</Label>
        <DatePicker value={date} onChange={setDate} />
      </div>
    );
  },
};

export const Preselected: Story = {
  render: () => {
    const [date, setDate] = useState<Date | undefined>(new Date(2026, 3, 10));
    return (
      <div className="flex flex-col gap-1.5 w-72">
        <Label>Delivery date</Label>
        <DatePicker value={date} onChange={setDate} />
      </div>
    );
  },
};

export const Range: Story = {
  parameters: {
    docs: {
      description: {
        story: "Date range picker for filters / report periods.",
      },
    },
  },
  render: () => {
    const [range, setRange] = useState<DateRange | undefined>();
    return (
      <div className="flex flex-col gap-1.5 w-80">
        <Label>Reporting period</Label>
        <DateRangePicker value={range} onChange={setRange} />
      </div>
    );
  },
};

export const Disabled: Story = {
  render: () => (
    <div className="flex flex-col gap-1.5 w-72">
      <Label>Locked date</Label>
      <DatePicker disabled placeholder="Cannot edit" />
    </div>
  ),
};
