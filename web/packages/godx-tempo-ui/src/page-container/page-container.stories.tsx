import type { Meta, StoryObj } from "@storybook/nextjs-vite";
import { Plus, Save, Filter, Download, MessageSquare, Settings } from "lucide-react";

import {
  FullWidthPageContainer,
  PageContainer,
  SplitPageContainer,
  StandardPageContainer,
} from "../page-container";
import { Button } from "../button";
import { Badge } from "../badge";
import { Card, CardContent, CardHeader, CardTitle } from "../card";
import { Input } from "../input";
import { Label } from "../label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../tabs";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "../table";

/**
 * High-leverage page-shell layout primitive shipped in `@/components/ui` so
 * every Omnify framework project consumes the same canonical page wrapper.
 *
 * Three variants:
 * - **standard** — header (title + subtitle + actions) + scrollable body +
 *   optional footer. Use for most CRUD list / detail pages.
 * - **split** — header + content split with a sidebar (left or right).
 *   Use for detail pages with related-content rails (comments, history,
 *   filters, navigation).
 * - **full** — no chrome, just `h-full flex flex-col`. Use for canvas / board
 *   layouts (kanban, gantt, calendar) that own their own header.
 *
 * The convenience aliases `<StandardPageContainer>` / `<SplitPageContainer>` /
 * `<FullWidthPageContainer>` lock the variant in the type for callers that
 * don't want to pass `variant=` every time.
 */
const meta: Meta<typeof PageContainer> = {
  title: "UI/PageContainer",
  component: PageContainer,
  tags: ["autodocs"],
  parameters: {
    layout: "fullscreen",
    docs: {
      description: {
        component:
          "Canonical page-shell wrapper. Use as the root of every HQ / shop page so every screen shares the same density tokens, header layout, and footer rules. Three variants — standard, split, full — cover the common page archetypes.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof PageContainer>;

// ─── Standard variant ──────────────────────────────────────────────────────

export const Standard: Story = {
  render: () => (
    <div className="h-[480px] border rounded-lg overflow-hidden">
      <StandardPageContainer
        title="Products"
        subtitle="Manage your product catalog"
        extra={
          <div className="flex gap-2">
            <Button variant="outline" size="sm"><Filter />Filter</Button>
            <Button size="sm"><Plus />New product</Button>
          </div>
        }
      >
        <div className="rounded-md border p-4 text-sm text-muted-foreground">
          Page body — replace with a Table, grid of Cards, etc.
        </div>
      </StandardPageContainer>
    </div>
  ),
};

export const StandardWithFooter: Story = {
  parameters: {
    docs: {
      description: {
        story: "Footer slot pins to the bottom — useful for save/cancel bars on long forms.",
      },
    },
  },
  render: () => (
    <div className="h-[480px] border rounded-lg overflow-hidden">
      <StandardPageContainer
        title="Edit product"
        subtitle="Yakiniku Platter — premium short rib course"
        extra={<Badge variant="soft" color="success">Saved 2 min ago</Badge>}
        footer={
          <div className="flex items-center justify-between">
            <span className="text-xs text-muted-foreground">All changes are auto-saved.</span>
            <div className="flex gap-2">
              <Button variant="ghost" size="sm">Discard</Button>
              <Button size="sm"><Save />Save changes</Button>
            </div>
          </div>
        }
      >
        <div className="space-y-4 max-w-xl">
          <div className="space-y-1.5">
            <Label htmlFor="name">Product name</Label>
            <Input id="name" defaultValue="Yakiniku Platter" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slug">Slug</Label>
            <Input id="slug" defaultValue="yakiniku-platter" />
          </div>
        </div>
      </StandardPageContainer>
    </div>
  ),
};

export const StandardListView: Story = {
  parameters: {
    docs: {
      description: {
        story: "Common pattern: title + filter actions + a Table of records.",
      },
    },
  },
  render: () => (
    <div className="h-[520px] border rounded-lg overflow-hidden">
      <StandardPageContainer
        title="Orders"
        subtitle="Last 30 days"
        extra={
          <div className="flex gap-2">
            <Input placeholder="Search…" className="w-48" />
            <Button variant="outline" size="sm"><Download />Export</Button>
          </div>
        }
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Order #</TableHead>
              <TableHead>Customer</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {[
              { id: "ORD-1042", customer: "Tanaka Hiroshi", total: 12000, status: "delivered" as const },
              { id: "ORD-1043", customer: "Yamada Saki", total: 8400, status: "in-transit" as const },
              { id: "ORD-1044", customer: "Suzuki Ken", total: 5200, status: "processing" as const },
              { id: "ORD-1045", customer: "Watanabe Aya", total: 18900, status: "delivered" as const },
            ].map((o) => (
              <TableRow key={o.id}>
                <TableCell className="font-mono text-xs">{o.id}</TableCell>
                <TableCell>{o.customer}</TableCell>
                <TableCell className="text-right">¥{o.total.toLocaleString("ja-JP")}</TableCell>
                <TableCell>
                  <Badge
                    variant="soft"
                    color={
                      o.status === "delivered"
                        ? "success"
                        : o.status === "in-transit"
                        ? "warning"
                        : "info"
                    }
                  >
                    {o.status}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </StandardPageContainer>
    </div>
  ),
};

export const StandardDashboard: Story = {
  parameters: {
    docs: {
      description: {
        story: "Dashboard pattern with metric cards in a responsive grid.",
      },
    },
  },
  render: () => (
    <div className="h-[520px] border rounded-lg overflow-hidden">
      <StandardPageContainer title="Dashboard" subtitle="Today's performance">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {[
            { label: "Revenue", value: "¥124,500", delta: "+8.2%" },
            { label: "Orders", value: "42", delta: "+12.5%" },
            { label: "Avg ticket", value: "¥2,964", delta: "−3.1%" },
          ].map((m) => (
            <Card key={m.label}>
              <CardHeader>
                <CardTitle className="text-xs text-muted-foreground font-normal">{m.label}</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-medium">{m.value}</div>
                <div className="text-xs text-muted-foreground mt-1">{m.delta} vs yesterday</div>
              </CardContent>
            </Card>
          ))}
        </div>
      </StandardPageContainer>
    </div>
  ),
};

// ─── Split variant ─────────────────────────────────────────────────────────

export const SplitRightSidebar: Story = {
  parameters: {
    docs: {
      description: {
        story: "Detail page with a related-content rail on the right (comments, activity log).",
      },
    },
  },
  render: () => (
    <div className="h-[520px] border rounded-lg overflow-hidden">
      <SplitPageContainer
        title="ORD-1042"
        subtitle="Yakiniku Platter × 2 + Sake set"
        extra={<Badge variant="soft" color="success">Delivered</Badge>}
        sidebar={
          <div className="p-4 space-y-3">
            <div className="flex items-center gap-2 text-sm font-medium">
              <MessageSquare className="size-4" />
              Comments (3)
            </div>
            {["Order received and confirmed", "Prep started", "Out for delivery"].map((m, i) => (
              <div key={i} className="text-xs p-2 rounded-md bg-muted/30">
                <div className="font-medium">satoshi01</div>
                <div className="text-muted-foreground">{m}</div>
              </div>
            ))}
          </div>
        }
      >
        <div className="space-y-4">
          <h3 className="text-base font-medium">Items</h3>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead className="text-right">Price</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow><TableCell>Yakiniku Platter</TableCell><TableCell className="text-right">2</TableCell><TableCell className="text-right">¥9,000</TableCell></TableRow>
              <TableRow><TableCell>Sake set</TableCell><TableCell className="text-right">1</TableCell><TableCell className="text-right">¥3,000</TableCell></TableRow>
            </TableBody>
          </Table>
        </div>
      </SplitPageContainer>
    </div>
  ),
};

export const SplitLeftSidebar: Story = {
  parameters: {
    docs: {
      description: {
        story: "Settings pattern with section navigation on the LEFT.",
      },
    },
  },
  render: () => (
    <div className="h-[520px] border rounded-lg overflow-hidden">
      <SplitPageContainer
        title="Settings"
        subtitle="Workspace configuration"
        sidebar={
          <nav className="p-3 space-y-1 text-sm">
            {[
              { label: "General", active: true },
              { label: "Security" },
              { label: "Billing" },
              { label: "Integrations" },
              { label: "API keys" },
            ].map((s) => (
              <a
                key={s.label}
                href="#"
                className={`block px-2 py-1.5 rounded ${
                  s.active ? "bg-accent font-medium" : "hover:bg-accent/50"
                }`}
              >
                {s.label}
              </a>
            ))}
          </nav>
        }
        sidebarPosition="left"
        sidebarWidth="w-48"
      >
        <div className="space-y-4 max-w-xl">
          <h3 className="text-base font-medium">General</h3>
          <div className="space-y-1.5">
            <Label htmlFor="ws">Workspace name</Label>
            <Input id="ws" defaultValue="TempoFast HQ" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="tz">Timezone</Label>
            <Input id="tz" defaultValue="Asia/Tokyo" />
          </div>
          <Button size="sm" className="mt-2">Save changes</Button>
        </div>
      </SplitPageContainer>
    </div>
  ),
};

export const SplitDetailWithTabs: Story = {
  parameters: {
    docs: {
      description: {
        story: "Tabs inside a Split body — common entity-detail pattern.",
      },
    },
  },
  render: () => (
    <div className="h-[560px] border rounded-lg overflow-hidden">
      <SplitPageContainer
        title="Customer · Tanaka Hiroshi"
        subtitle="Member since 2024-03-15 · Tokyo"
        extra={<Button size="sm" variant="outline"><Settings />Edit</Button>}
        sidebar={
          <div className="p-4 space-y-3 text-sm">
            <h4 className="font-medium">Stats</h4>
            <div className="space-y-2">
              <div className="flex justify-between"><span className="text-muted-foreground">Total spent</span><span className="font-mono">¥482,000</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Orders</span><span className="font-mono">28</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Avg ticket</span><span className="font-mono">¥17,214</span></div>
            </div>
          </div>
        }
      >
        <Tabs defaultValue="overview">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="orders">Orders</TabsTrigger>
            <TabsTrigger value="payments">Payments</TabsTrigger>
            <TabsTrigger value="notes">Notes</TabsTrigger>
          </TabsList>
          <TabsContent value="overview"><p className="text-sm text-muted-foreground">Top-level summary content.</p></TabsContent>
          <TabsContent value="orders"><p className="text-sm text-muted-foreground">Order history table.</p></TabsContent>
          <TabsContent value="payments"><p className="text-sm text-muted-foreground">Payment methods and invoices.</p></TabsContent>
          <TabsContent value="notes"><p className="text-sm text-muted-foreground">Internal notes from staff.</p></TabsContent>
        </Tabs>
      </SplitPageContainer>
    </div>
  ),
};

// ─── Full variant ──────────────────────────────────────────────────────────

export const FullWidth: Story = {
  parameters: {
    docs: {
      description: {
        story: "No chrome — use for canvas / board layouts that own their own header.",
      },
    },
  },
  render: () => (
    <div className="h-[480px] border rounded-lg overflow-hidden">
      <FullWidthPageContainer>
        <div className="border-b border-border px-4 py-3 flex items-center justify-between">
          <span className="text-sm font-medium">Kanban — Production board</span>
          <Button size="sm"><Plus />Add column</Button>
        </div>
        <div className="flex-1 p-4 grid grid-cols-4 gap-3 bg-muted/20">
          {["To do", "In progress", "Review", "Done"].map((col) => (
            <div key={col} className="rounded-md bg-background border p-3 space-y-2">
              <div className="text-xs font-medium text-muted-foreground">{col}</div>
              <Card className="p-2 text-xs">Task A</Card>
              <Card className="p-2 text-xs">Task B</Card>
            </div>
          ))}
        </div>
      </FullWidthPageContainer>
    </div>
  ),
};

export const FullWidthWithFooter: Story = {
  parameters: {
    docs: {
      description: {
        story: "Editor-style: canvas fills the viewport with a sticky bottom bar.",
      },
    },
  },
  render: () => (
    <div className="h-[480px] border rounded-lg overflow-hidden">
      <FullWidthPageContainer
        footer={
          <div className="px-4 py-2 flex items-center justify-between text-xs text-muted-foreground">
            <span>Saved · 2 minutes ago</span>
            <div className="flex gap-2">
              <Button size="xs" variant="ghost">Undo</Button>
              <Button size="xs">Publish</Button>
            </div>
          </div>
        }
      >
        <div className="flex-1 bg-muted/20 flex items-center justify-center text-sm text-muted-foreground">
          Canvas — drop your editor here
        </div>
      </FullWidthPageContainer>
    </div>
  ),
};

export const NoHeader: Story = {
  parameters: {
    docs: {
      description: {
        story: "Omitting `title` and `extra` collapses the header — body fills the entire container.",
      },
    },
  },
  render: () => (
    <div className="h-[400px] border rounded-lg overflow-hidden">
      <StandardPageContainer>
        <div className="rounded-md border p-4 text-sm text-muted-foreground">
          Body content fills the entire container — no header rendered.
        </div>
      </StandardPageContainer>
    </div>
  ),
};
