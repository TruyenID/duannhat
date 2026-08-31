import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { useState } from "react";
import { expect, fn, userEvent, within } from "storybook/test";

import { Switch } from "../switch";
import { Label } from "../label";

const meta: Meta<typeof Switch> = {
  title: "UI/Switch",
  component: Switch,
  tags: ["autodocs"],
  args: {
    onCheckedChange: fn(),
  },
  parameters: {
    docs: {
      description: {
        component:
          "Toggle switch for boolean on/off settings, styled as a sliding pill. Use this when the action takes effect immediately (notifications on/off, dark mode toggle). For form fields where the change only commits on submit, prefer `<Checkbox>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Switch>;

export const Default: Story = {};

export const On: Story = { args: { defaultChecked: true } };

export const Disabled: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-2">
        <Switch disabled />
        <span className="text-sm">Off + disabled</span>
      </div>
      <div className="flex items-center gap-2">
        <Switch disabled defaultChecked />
        <span className="text-sm">On + disabled</span>
      </div>
    </div>
  ),
};

export const WithLabel: Story = {
  parameters: { controls: { disable: true } },
  render: () => {
    const [enabled, setEnabled] = useState(false);
    return (
      <div className="flex items-center gap-2">
        <Switch id="notifications" checked={enabled} onCheckedChange={setEnabled} />
        <Label htmlFor="notifications">
          Notifications {enabled ? "(on)" : "(off)"}
        </Label>
      </div>
    );
  },
};

export const SettingsList: Story = {
  parameters: { controls: { disable: true } },
  render: () => {
    const [state, setState] = useState({
      email: true,
      push: false,
      sms: false,
      marketing: false,
    });
    return (
      <div className="flex flex-col gap-3 w-80">
        {(Object.keys(state) as (keyof typeof state)[]).map((key) => (
          <div key={key} className="flex items-center justify-between">
            <Label htmlFor={key} className="capitalize">{key}</Label>
            <Switch
              id={key}
              checked={state[key]}
              onCheckedChange={(c) => setState({ ...state, [key]: c === true })}
            />
          </div>
        ))}
      </div>
    );
  },
};

// ─── Interaction test ──────────────────────────────────────────────────────

export const ClickToggles: Story = {
  parameters: { controls: { disable: true } },
  play: async ({ canvasElement, args }) => {
    const canvas = within(canvasElement);
    const sw = canvas.getByRole("switch");
    await userEvent.click(sw);
    expect(args.onCheckedChange).toHaveBeenCalledTimes(1);
    expect(args.onCheckedChange).toHaveBeenCalledWith(true);
    await userEvent.click(sw);
    expect(args.onCheckedChange).toHaveBeenCalledTimes(2);
    expect(args.onCheckedChange).toHaveBeenLastCalledWith(false);
  },
};
