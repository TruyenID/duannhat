import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Menubar,
  MenubarContent,
  MenubarItem,
  MenubarMenu,
  MenubarSeparator,
  MenubarShortcut,
  MenubarTrigger,
} from "../menubar";

const meta: Meta<typeof Menubar> = {
  title: "UI/Menubar",
  component: Menubar,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "macOS-style application menu bar (File / Edit / View). Rare in modern web apps. For action menus on individual UI elements use `<DropdownMenu>`. For website navigation use `<NavigationMenu>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Menubar>;

export const Default: Story = {
  render: () => (
    <Menubar>
      <MenubarMenu>
        <MenubarTrigger>File</MenubarTrigger>
        <MenubarContent>
          <MenubarItem>New tab <MenubarShortcut>⌘T</MenubarShortcut></MenubarItem>
          <MenubarItem>New window <MenubarShortcut>⌘N</MenubarShortcut></MenubarItem>
          <MenubarSeparator />
          <MenubarItem>Print <MenubarShortcut>⌘P</MenubarShortcut></MenubarItem>
        </MenubarContent>
      </MenubarMenu>
      <MenubarMenu>
        <MenubarTrigger>Edit</MenubarTrigger>
        <MenubarContent>
          <MenubarItem>Undo <MenubarShortcut>⌘Z</MenubarShortcut></MenubarItem>
          <MenubarItem>Redo <MenubarShortcut>⇧⌘Z</MenubarShortcut></MenubarItem>
        </MenubarContent>
      </MenubarMenu>
      <MenubarMenu>
        <MenubarTrigger>View</MenubarTrigger>
        <MenubarContent>
          <MenubarItem>Reload <MenubarShortcut>⌘R</MenubarShortcut></MenubarItem>
        </MenubarContent>
      </MenubarMenu>
    </Menubar>
  ),
};
