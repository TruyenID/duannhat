import type { Meta, StoryObj } from "@storybook/react-vite";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@godxjp/ui";
import { Badge } from "@godxjp/ui";

/**
 * Tenant theme overrides — demo of the `[data-tenant="<slug>"]` mechanism
 * defined in `globals.css`.
 *
 * Framework defaults stay neutral (SmartHR MAIN blue) so the canonical
 * Omnify UI library is brand-agnostic. Tenants opt in by wrapping a subtree
 * (or `<body>`) with `data-tenant="<slug>"` and the semantic role tokens
 * shift to that tenant's brand colors.
 *
 * Currently shipped tenants:
 * - **default** (no attribute) — SmartHR MAIN blue, JP enterprise neutral
 * - **betoya** — Vietnam green + golden yellow (Vietnamese restaurant brand
 *   sourced from https://betoya.jp Elementor globals)
 *
 * To add a new tenant: open `src/app/globals.css`, copy the
 * `[data-tenant="betoya"]` block, replace slug + OKLCH values. Every brand
 * primary must satisfy 渋み (chroma ≤ 0.18) per the design foundation.
 */
const meta: Meta = {
  title: "Foundations/Tenant Themes",
  parameters: {
    docs: {
      description: {
        component:
          "Per-tenant brand overrides via `data-tenant` attribute. Same component code, different brand colors based on which tenant subtree the component renders inside.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

function TenantPanel({ tenant, label, brand }: { tenant?: string; label: string; brand: string }) {
  return (
    <div data-tenant={tenant} className="space-y-3 rounded-lg border p-4">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium">{label}</span>
        <code className="text-[10px] font-mono text-muted-foreground">
          {tenant ? `data-tenant="${tenant}"` : "(default — no attr)"}
        </code>
      </div>
      <p className="text-xs text-muted-foreground">{brand}</p>
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm">Primary</Button>
        <Button variant="outline" size="sm">Outline</Button>
        <Button variant="ghost" size="sm">Ghost</Button>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor={`name-${tenant ?? "default"}`}>Name</Label>
        <Input id={`name-${tenant ?? "default"}`} placeholder="Enter…" />
      </div>
      <Badge>Active</Badge>
    </div>
  );
}

export const SideBySide: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Default vs Betoya tenant side by side. Same components — Button, Input, Badge — adopt the active tenant's brand colors automatically via the `[data-tenant]` selector.",
      },
    },
  },
  render: () => (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 p-6">
      <TenantPanel
        label="Default tenant"
        brand="SmartHR MAIN — JP enterprise neutral blue (oklch 56% 0.15 240)"
      />
      <TenantPanel
        tenant="betoya"
        label="Betoya / ベト屋"
        brand="Vietnamese restaurant — Vietnam green (oklch 58% 0.159 150) + golden yellow accent"
      />
    </div>
  ),
};

export const BetoyaShowcase: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Full Betoya tenant page sample. Wrap any subtree (or `<body>`) with `data-tenant=\"betoya\"` to switch the entire tree to the Vietnamese restaurant brand identity.",
      },
    },
  },
  render: () => (
    <div data-tenant="betoya" className="p-6 space-y-4 max-w-2xl">
      <div className="flex items-center gap-3">
        <div
          className="size-12 rounded-md flex items-center justify-center text-white font-bold text-xl"
          style={{ backgroundColor: "var(--color-brand-betoya-primary)" }}
        >
          B
        </div>
        <div>
          <h1 className="text-2xl font-medium">ベト屋 / Betoya</h1>
          <p className="text-sm text-muted-foreground">Vietnamese pho · Jimbocho</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>今日のおすすめ — Today&apos;s recommendation</CardTitle>
          <CardDescription>春巻きとフォーのセット — Spring rolls + pho set</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <p className="text-sm">
            Pho with hand-pulled rice noodles, slow-simmered beef bone broth, and
            seasonal Vietnamese herbs. Served with fresh spring rolls and house
            chili sauce.
          </p>
          <div className="flex items-center justify-between">
            <span className="text-2xl font-medium">¥1,480</span>
            <Button>Order now</Button>
          </div>
        </CardContent>
      </Card>

      <div className="flex flex-wrap gap-2">
        <Badge variant="soft" color="success">
          Available
        </Badge>
        <Badge>New menu</Badge>
        <Badge variant="outline">Spicy</Badge>
      </div>

      <div className="rounded-md border p-3 text-xs text-muted-foreground">
        <strong className="text-foreground">Brand tokens used:</strong>
        <ul className="mt-1 space-y-0.5 font-mono">
          <li>--color-brand-betoya-primary = #009444 (Vietnam green)</li>
          <li>--color-brand-betoya-secondary = #FFC20E (golden yellow)</li>
          <li>--color-brand-betoya-accent = #00B856 (brighter green)</li>
          <li>--color-brand-betoya-attention = #F95C39 (orange-red)</li>
        </ul>
      </div>
    </div>
  ),
};

export const BrandPalette: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Direct swatches of the Betoya brand tokens. Use the `--color-brand-betoya-*` vars when you want a brand color regardless of the active tenant (e.g. a Betoya logo embedded in a multi-tenant admin page).",
      },
    },
  },
  render: () => {
    const swatches = [
      { name: "primary", hex: "#009444", desc: "Vietnam green — primary brand" },
      { name: "secondary", hex: "#FFC20E", desc: "Golden yellow — secondary" },
      { name: "accent", hex: "#00B856", desc: "Brighter green — accent" },
      { name: "attention", hex: "#F95C39", desc: "Orange-red — sparingly used highlight" },
      { name: "text", hex: "#171614", desc: "Warm off-black — text primary" },
    ];
    return (
      <div className="p-6 max-w-2xl">
        <h2 className="text-xl font-medium mb-2">Betoya brand tokens</h2>
        <p className="text-sm text-muted-foreground mb-4">
          Sourced from https://betoya.jp Elementor global colors. Vietnam-green
          primary chroma 0.159 sits comfortably under the 渋み (shibumi) limit
          of 0.18 — culturally appropriate for both the Vietnamese identity
          and the Japanese enterprise foundation.
        </p>
        <div className="space-y-2">
          {swatches.map((s) => (
            <div key={s.name} className="flex items-center gap-3 border-b border-border py-2">
              <div
                className="size-12 rounded-md border border-border shrink-0"
                style={{ backgroundColor: s.hex }}
              />
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium">--color-brand-betoya-{s.name}</div>
                <div className="text-xs font-mono text-muted-foreground">{s.hex}</div>
              </div>
              <div className="text-xs text-muted-foreground max-w-xs">{s.desc}</div>
            </div>
          ))}
        </div>
      </div>
    );
  },
};
