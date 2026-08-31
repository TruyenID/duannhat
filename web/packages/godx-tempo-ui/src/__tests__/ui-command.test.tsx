import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Command, CommandInput, CommandList, CommandEmpty } from "../command";

describe("Command", () => {
  it("renders input", () => {
    render(<Command><CommandInput placeholder="Search..." /><CommandList><CommandEmpty>No results</CommandEmpty></CommandList></Command>);
    expect(screen.getByPlaceholderText("Search...")).toBeInTheDocument();
  });
});
