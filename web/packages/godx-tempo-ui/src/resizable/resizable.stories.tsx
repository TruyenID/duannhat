import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from "../resizable";

const meta: Meta<typeof ResizablePanelGroup> = {
  title: "UI/Resizable",
  component: ResizablePanelGroup,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Drag-to-resize split panes built on `react-resizable-panels`. Use for IDE-style multi-pane layouts (file tree + editor + terminal). For static side-rails prefer `<Sidebar>` instead.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof ResizablePanelGroup>;

export const Horizontal: Story = {
  render: () => (
    <ResizablePanelGroup direction="horizontal" className="h-48 w-[480px] rounded-md border">
      <ResizablePanel defaultSize={30} className="flex items-center justify-center text-sm">
        Left
      </ResizablePanel>
      <ResizableHandle withHandle />
      <ResizablePanel defaultSize={70} className="flex items-center justify-center text-sm">
        Right
      </ResizablePanel>
    </ResizablePanelGroup>
  ),
};

export const ThreePane: Story = {
  render: () => (
    <ResizablePanelGroup direction="horizontal" className="h-48 w-[600px] rounded-md border">
      <ResizablePanel defaultSize={20} className="flex items-center justify-center text-sm">
        Sidebar
      </ResizablePanel>
      <ResizableHandle />
      <ResizablePanel defaultSize={55} className="flex items-center justify-center text-sm">
        Main
      </ResizablePanel>
      <ResizableHandle />
      <ResizablePanel defaultSize={25} className="flex items-center justify-center text-sm">
        Aside
      </ResizablePanel>
    </ResizablePanelGroup>
  ),
};

export const Vertical: Story = {
  render: () => (
    <ResizablePanelGroup direction="vertical" className="h-72 w-80 rounded-md border">
      <ResizablePanel defaultSize={60} className="flex items-center justify-center text-sm">
        Top
      </ResizablePanel>
      <ResizableHandle withHandle />
      <ResizablePanel defaultSize={40} className="flex items-center justify-center text-sm">
        Bottom (terminal)
      </ResizablePanel>
    </ResizablePanelGroup>
  ),
};
