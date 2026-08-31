import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { NavigationMenu, NavigationMenuList, NavigationMenuItem, NavigationMenuLink } from "../navigation-menu";

describe("NavigationMenu", () => {
  it("renders nav items", () => {
    render(<NavigationMenu><NavigationMenuList><NavigationMenuItem><NavigationMenuLink href="/">Home</NavigationMenuLink></NavigationMenuItem></NavigationMenuList></NavigationMenu>);
    expect(screen.getByText("Home")).toBeInTheDocument();
  });
});
