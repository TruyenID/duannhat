import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { Calculator, Calendar, Settings, Smile, User } from "lucide-react";

import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  CommandShortcut,
} from "../command";

const meta: Meta<typeof Command> = {
  title: "UI/Command",
  component: Command,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "cmdk-powered command palette / fuzzy search input. Used internally by `<Combobox>` and `<MultiCombobox>`. Use directly for app-wide ⌘K command palettes (jump-to-page, run-action shortcuts).",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Command>;

export const Default: Story = {
  render: () => (
    <Command className="w-80 border rounded-lg">
      <CommandInput placeholder="Type a command or search..." />
      <CommandList>
        <CommandEmpty>No results found.</CommandEmpty>
        <CommandGroup heading="Suggestions">
          <CommandItem>
            <Calendar />
            Calendar
          </CommandItem>
          <CommandItem>
            <Smile />
            Search emoji
          </CommandItem>
          <CommandItem>
            <Calculator />
            Calculator
          </CommandItem>
        </CommandGroup>
        <CommandSeparator />
        <CommandGroup heading="Settings">
          <CommandItem>
            <User />
            Profile
            <CommandShortcut>⌘P</CommandShortcut>
          </CommandItem>
          <CommandItem>
            <Settings />
            Settings
            <CommandShortcut>⌘,</CommandShortcut>
          </CommandItem>
        </CommandGroup>
      </CommandList>
    </Command>
  ),
};
