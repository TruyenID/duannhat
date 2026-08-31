import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuTrigger,
} from "../context-menu";

const meta: Meta<typeof ContextMenu> = {
  title: "UI/ContextMenu",
  component: ContextMenu,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Right-click context menu. Use ONLY when the user expects right-click affordance (file managers, canvas editors). For button-triggered action menus use `<DropdownMenu>` instead — touch users can't right-click.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof ContextMenu>;

export const Default: Story = {
  render: () => (
    <ContextMenu>
      <ContextMenuTrigger className="flex h-40 w-80 items-center justify-center rounded-md border border-dashed">
        Right click here
      </ContextMenuTrigger>
      <ContextMenuContent>
        <ContextMenuItem>Copy <ContextMenuShortcut>⌘C</ContextMenuShortcut></ContextMenuItem>
        <ContextMenuItem>Paste <ContextMenuShortcut>⌘V</ContextMenuShortcut></ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem variant="destructive">Delete</ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>
  ),
};
