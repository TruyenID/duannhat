import type { Meta, StoryObj } from "@storybook/nextjs-vite";

import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardMedia,
  CardTitle,
} from "../card";
import { Button } from "../button";
import { Badge } from "../badge";

// Tiny inline SVG used as a placeholder image so the stories don't depend on
// external assets. Encoded as a data URI to avoid network requests in tests.
const PLACEHOLDER_IMAGE =
  "data:image/svg+xml;utf8," +
  encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 450">
      <defs>
        <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#fb923c"/>
          <stop offset="100%" stop-color="#f43f5e"/>
        </linearGradient>
      </defs>
      <rect width="800" height="450" fill="url(#g)"/>
      <text x="50%" y="50%" text-anchor="middle" fill="white" font-family="sans-serif" font-size="48" font-weight="600" dy=".3em">Yakiniku Platter</text>
    </svg>`,
  );

const meta: Meta<typeof Card> = {
  title: "UI/Card",
  component: Card,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "Container primitive for surface content. Compose `<CardHeader>` (Title + Description + optional Action), `<CardContent>`, and `<CardFooter>` for the standard layout. Internal spacing uses density tokens (`px-card`, `pt-card`, `gap-card`) so the same Card adapts to compact / comfortable density modes set on the design system.",
      },
    },
  },
};
export default meta;

type Story = StoryObj<typeof Card>;

export const Default: Story = {
  render: () => (
    <Card className="w-80">
      <CardHeader>
        <CardTitle>Notifications</CardTitle>
        <CardDescription>You have 3 unread messages.</CardDescription>
      </CardHeader>
      <CardContent>
        <p className="text-sm">Your recent activity will appear here.</p>
      </CardContent>
    </Card>
  ),
};

export const WithAction: Story = {
  render: () => (
    <Card className="w-96">
      <CardHeader>
        <CardTitle>Project alpha</CardTitle>
        <CardDescription>Updated 2 minutes ago</CardDescription>
        <CardAction>
          <Button variant="outline" size="sm">Edit</Button>
        </CardAction>
      </CardHeader>
      <CardContent>
        <p className="text-sm">
          The action slot in CardHeader is positioned top-right via grid
          layout — no flex-row hacks needed.
        </p>
      </CardContent>
    </Card>
  ),
};

export const WithFooter: Story = {
  render: () => (
    <Card className="w-96">
      <CardHeader>
        <CardTitle>Confirm purchase</CardTitle>
        <CardDescription>Total: 12,000 ¥ (incl. tax)</CardDescription>
      </CardHeader>
      <CardContent className="text-sm">
        Once confirmed, the order is sent to fulfilment immediately and
        cannot be cancelled.
      </CardContent>
      <CardFooter className="gap-2 justify-end">
        <Button variant="ghost">Cancel</Button>
        <Button>Confirm</Button>
      </CardFooter>
    </Card>
  ),
};

export const WithBadgeAction: Story = {
  render: () => (
    <Card className="w-96">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          Production order
          <Badge variant="soft" color="success">Active</Badge>
        </CardTitle>
        <CardDescription>PO-2026-04-0042</CardDescription>
        <CardAction>
          <Button variant="ghost" size="icon" aria-label="More">⋯</Button>
        </CardAction>
      </CardHeader>
      <CardContent className="text-sm space-y-1">
        <p>Recipe: Yakiniku platter</p>
        <p>Yield: 24 units</p>
      </CardContent>
    </Card>
  ),
};

export const Plain: Story = {
  render: () => (
    <Card className="w-80 p-4">
      <p className="text-sm">
        Card without any header/footer — just the bare Card primitive as a
        styled container. Padding via <code>p-4</code> since the density
        tokens only apply to the named sub-components.
      </p>
    </Card>
  ),
};

// ─── Image / media variants ────────────────────────────────────────────────

export const WithImageTop: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Edge-to-edge media at the top of the card. `<CardMedia aspectRatio=\"16/9\">` constrains the image and clips to the Card's top corners. The media slot has zero padding so the image flushes against the Card border.",
      },
    },
  },
  render: () => (
    <Card className="w-72">
      <CardMedia aspectRatio="16/9">
        <img src={PLACEHOLDER_IMAGE} alt="Yakiniku platter" className="size-full object-cover" />
      </CardMedia>
      <CardHeader>
        <CardTitle>Yakiniku platter</CardTitle>
        <CardDescription>From the spring menu</CardDescription>
      </CardHeader>
      <CardContent className="text-sm text-muted-foreground">
        Premium short rib, harami, and tongue served with three dipping sauces.
      </CardContent>
      <CardFooter className="justify-between">
        <Badge variant="soft" color="success">In stock</Badge>
        <Button size="sm">Order</Button>
      </CardFooter>
    </Card>
  ),
};

export const WithImageSquare: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Square aspect ratio for product gallery / Pinterest-style layouts. `<CardMedia aspectRatio=\"square\">` uses Tailwind's `aspect-square` utility.",
      },
    },
  },
  render: () => (
    <div className="grid grid-cols-3 gap-4">
      {["Item A", "Item B", "Item C"].map((label) => (
        <Card key={label} className="w-48">
          <CardMedia aspectRatio="square">
            <img src={PLACEHOLDER_IMAGE} alt={label} className="size-full object-cover" />
          </CardMedia>
          <CardHeader>
            <CardTitle className="text-sm">{label}</CardTitle>
            <CardDescription className="text-xs">12,000 ¥</CardDescription>
          </CardHeader>
        </Card>
      ))}
    </div>
  ),
};

export const ImageOnly: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "When `<CardMedia>` is both the first and last child of `<Card>`, it rounds both top and bottom corners — useful for pure-image tiles in galleries.",
      },
    },
  },
  render: () => (
    <Card className="w-64">
      <CardMedia aspectRatio="4/3">
        <img src={PLACEHOLDER_IMAGE} alt="" className="size-full object-cover" />
      </CardMedia>
    </Card>
  ),
};

export const HorizontalImageLeft: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Horizontal layout with image on the left. The Card root is overridden to `flex-row` instead of the default `flex-col`. The media slot takes a fixed width and the content slot fills the remainder.",
      },
    },
  },
  render: () => (
    <Card className="w-[480px] flex-row gap-0 overflow-hidden">
      <div className="w-40 shrink-0 bg-muted">
        <img src={PLACEHOLDER_IMAGE} alt="" className="size-full object-cover" />
      </div>
      <div className="flex flex-col gap-2 p-4">
        <CardTitle className="text-base">Wagyu set course</CardTitle>
        <CardDescription className="text-xs">5-course tasting menu</CardDescription>
        <p className="text-sm">
          Today&apos;s chef selection of seasonal cuts paired with sake.
        </p>
        <div className="mt-auto flex items-center justify-between">
          <Badge variant="soft" color="info">Limited</Badge>
          <span className="text-sm font-semibold">28,000 ¥</span>
        </div>
      </div>
    </Card>
  ),
};

export const ImageMiddle: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Image between header and content — `<CardMedia>` in the middle does NOT round any corners (it's not the first or last child), keeping the image as a clean horizontal strip.",
      },
    },
  },
  render: () => (
    <Card className="w-80">
      <CardHeader>
        <CardTitle>Featured product</CardTitle>
        <CardDescription>Updated daily</CardDescription>
      </CardHeader>
      <CardMedia aspectRatio="16/9">
        <img src={PLACEHOLDER_IMAGE} alt="" className="size-full object-cover" />
      </CardMedia>
      <CardContent className="text-sm text-muted-foreground">
        Description text continues below the media strip.
      </CardContent>
      <CardFooter>
        <Button size="sm" variant="outline" className="w-full">View details</Button>
      </CardFooter>
    </Card>
  ),
};
