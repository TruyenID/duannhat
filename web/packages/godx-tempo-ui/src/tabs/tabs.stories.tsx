import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { expect, userEvent, within } from "storybook/test";

import { Tabs, TabsContent, TabsList, TabsTrigger } from "../tabs";
import { Card, CardContent, CardHeader, CardTitle } from "../card";

const meta: Meta<typeof Tabs> = {
  title: "UI/Tabs",
  component: Tabs,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Tabbed interface for switching between sibling content panels. Built on Radix UI Tabs — keyboard nav (←/→/Home/End) and focus management out of the box. Use as the top-level structure for entity detail pages with multiple sections (overview, related items, history).",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Tabs>;

export const Default: Story = {
  render: () => (
    <Tabs defaultValue="overview" className="w-96">
      <TabsList>
        <TabsTrigger value="overview">Overview</TabsTrigger>
        <TabsTrigger value="analytics">Analytics</TabsTrigger>
        <TabsTrigger value="settings">Settings</TabsTrigger>
      </TabsList>
      <TabsContent value="overview">
        <p className="text-sm">Project overview content goes here.</p>
      </TabsContent>
      <TabsContent value="analytics">
        <p className="text-sm">Charts and metrics live here.</p>
      </TabsContent>
      <TabsContent value="settings">
        <p className="text-sm">Configuration options.</p>
      </TabsContent>
    </Tabs>
  ),
};

export const WithCardLayout: Story = {
  render: () => (
    <Tabs defaultValue="general" className="w-[480px]">
      <TabsList>
        <TabsTrigger value="general">General</TabsTrigger>
        <TabsTrigger value="security">Security</TabsTrigger>
        <TabsTrigger value="billing">Billing</TabsTrigger>
      </TabsList>
      <TabsContent value="general">
        <Card>
          <CardHeader><CardTitle>General settings</CardTitle></CardHeader>
          <CardContent className="text-sm">
            Workspace name, default locale, timezone…
          </CardContent>
        </Card>
      </TabsContent>
      <TabsContent value="security">
        <Card>
          <CardHeader><CardTitle>Security</CardTitle></CardHeader>
          <CardContent className="text-sm">
            Password, two-factor auth, session management…
          </CardContent>
        </Card>
      </TabsContent>
      <TabsContent value="billing">
        <Card>
          <CardHeader><CardTitle>Billing</CardTitle></CardHeader>
          <CardContent className="text-sm">
            Plan, payment method, invoices…
          </CardContent>
        </Card>
      </TabsContent>
    </Tabs>
  ),
};

export const ManyTabs: Story = {
  render: () => (
    <Tabs defaultValue="t1" className="w-[600px]">
      <TabsList>
        {Array.from({ length: 6 }, (_, i) => (
          <TabsTrigger key={i} value={`t${i + 1}`}>Tab {i + 1}</TabsTrigger>
        ))}
      </TabsList>
      {Array.from({ length: 6 }, (_, i) => (
        <TabsContent key={i} value={`t${i + 1}`}>
          <p className="text-sm">Panel {i + 1} content.</p>
        </TabsContent>
      ))}
    </Tabs>
  ),
};

// ─── Interaction test ──────────────────────────────────────────────────────

export const KeyboardNavigation: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Verifies that arrow-key navigation activates sibling tabs (Radix default behaviour). Click first tab, then ArrowRight twice → focus and active state should land on the third tab.",
      },
    },
  },
  render: () => (
    <Tabs defaultValue="a">
      <TabsList>
        <TabsTrigger value="a">A</TabsTrigger>
        <TabsTrigger value="b">B</TabsTrigger>
        <TabsTrigger value="c">C</TabsTrigger>
      </TabsList>
      <TabsContent value="a">A panel</TabsContent>
      <TabsContent value="b">B panel</TabsContent>
      <TabsContent value="c">C panel</TabsContent>
    </Tabs>
  ),
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    const tabA = canvas.getByRole("tab", { name: "A" });
    await userEvent.click(tabA);
    await userEvent.keyboard("{ArrowRight}{ArrowRight}");
    const tabC = canvas.getByRole("tab", { name: "C" });
    expect(tabC).toHaveAttribute("data-state", "active");
  },
};
