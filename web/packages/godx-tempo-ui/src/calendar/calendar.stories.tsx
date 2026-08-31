import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import type { DateRange } from "react-day-picker";

import { Calendar } from "../calendar";

const meta: Meta<typeof Calendar> = {
  title: "UI/Calendar",
  component: Calendar,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Inline date-grid built on `react-day-picker`. Use directly when the calendar should always be visible (sidebar widget, scheduling page); use `<DatePicker>` for the popover variant inside forms.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Calendar>;

export const SingleDate: Story = {
  render: () => {
    const [date, setDate] = useState<Date | undefined>(new Date());
    return <Calendar mode="single" selected={date} onSelect={setDate} />;
  },
};

export const MultipleDates: Story = {
  render: () => {
    const [dates, setDates] = useState<Date[] | undefined>([]);
    return <Calendar mode="multiple" selected={dates} onSelect={setDates} />;
  },
};

export const Range: Story = {
  render: () => {
    const [range, setRange] = useState<DateRange | undefined>();
    return <Calendar mode="range" selected={range} onSelect={setRange} />;
  },
};
