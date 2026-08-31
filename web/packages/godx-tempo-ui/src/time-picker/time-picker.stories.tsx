import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";

import { TimePicker, TimeInput } from "../time-picker";
import { Label } from "../label";

const meta: Meta<typeof TimePicker> = {
  title: "UI/TimePicker",
  component: TimePicker,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Popover-anchored time picker. Stores value as `'HH:mm'` string. Defaults to 24-hour format (Japanese convention); set `format24h={false}` for 12-hour AM/PM.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof TimePicker>;

export const Default: Story = {
  render: () => {
    const [time, setTime] = useState<string>();
    return (
      <div className="flex flex-col gap-1.5 w-48">
        <Label>Reservation time</Label>
        <TimePicker value={time} onChange={setTime} />
      </div>
    );
  },
};

export const Preselected: Story = {
  render: () => {
    const [time, setTime] = useState("18:30");
    return (
      <div className="flex flex-col gap-1.5 w-48">
        <Label>Open time</Label>
        <TimePicker value={time} onChange={setTime} />
      </div>
    );
  },
};

export const NativeInput: Story = {
  parameters: {
    docs: {
      description: {
        story: "`<TimeInput>` is the simpler `type=\"time\"` HTML input variant — use when you don't need a popover.",
      },
    },
  },
  render: () => {
    const [time, setTime] = useState("14:30");
    return (
      <div className="flex flex-col gap-1.5 w-48">
        <Label>Native time input</Label>
        <TimeInput value={time} onChange={setTime} />
      </div>
    );
  },
};
