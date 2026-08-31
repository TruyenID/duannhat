import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  NavigationMenu,
  NavigationMenuContent,
  NavigationMenuItem,
  NavigationMenuLink,
  NavigationMenuList,
  NavigationMenuTrigger,
} from "../navigation-menu";

const meta: Meta<typeof NavigationMenu> = {
  title: "UI/NavigationMenu",
  component: NavigationMenu,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Site-wide navigation header with rich submenu support (mega menus). For action menus on individual UI elements use `<DropdownMenu>`. For desktop-app style File/Edit/View bars use `<Menubar>`.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof NavigationMenu>;

export const Default: Story = {
  render: () => (
    <NavigationMenu>
      <NavigationMenuList>
        <NavigationMenuItem>
          <NavigationMenuTrigger>Products</NavigationMenuTrigger>
          <NavigationMenuContent>
            <ul className="grid gap-2 p-4 w-[400px]">
              <li>
                <NavigationMenuLink href="/products">All products</NavigationMenuLink>
              </li>
              <li>
                <NavigationMenuLink href="/categories">Categories</NavigationMenuLink>
              </li>
              <li>
                <NavigationMenuLink href="/menus">Menus</NavigationMenuLink>
              </li>
            </ul>
          </NavigationMenuContent>
        </NavigationMenuItem>
        <NavigationMenuItem>
          <NavigationMenuLink href="/inventory">Inventory</NavigationMenuLink>
        </NavigationMenuItem>
        <NavigationMenuItem>
          <NavigationMenuLink href="/reports">Reports</NavigationMenuLink>
        </NavigationMenuItem>
      </NavigationMenuList>
    </NavigationMenu>
  ),
};
