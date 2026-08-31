import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { ResizablePanelGroup, ResizablePanel, ResizableHandle } from "../resizable";

describe("Resizable", () => {
  it("renders panels", () => {
    const { container } = render(<ResizablePanelGroup direction="horizontal"><ResizablePanel>Left</ResizablePanel><ResizableHandle /><ResizablePanel>Right</ResizablePanel></ResizablePanelGroup>);
    expect(container.textContent).toContain("Left");
    expect(container.textContent).toContain("Right");
  });
});
