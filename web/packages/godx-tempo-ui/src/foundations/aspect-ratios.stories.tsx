import type { Meta, StoryObj } from "@storybook/react-vite";

import { Card, CardContent, CardMedia } from "@godxjp/ui";

/**
 * Visual catalog for decorative aspect ratios — including the **golden
 * ratio verdict**.
 *
 * Source: design-foundations-japanese.md §"Aspect ratios + the golden
 * ratio verdict". Verdict: REJECT 1.618 as a primary scale ratio for
 * type/spacing/radius (no serious design system uses it; pixel rounding
 * defeats the math). ACCEPTABLE only for decorative aspect ratios.
 *
 * For "culturally Japanese feel" decorative imagery, prefer 白銀比
 * (silver ratio, 1.4142) — rooted in Horyuji temple, A4 paper, manga
 * panels, Toyota / Mitsubishi logos.
 */
const meta: Meta = {
  title: "Foundations/Aspect Ratios",
  parameters: {
    docs: {
      description: {
        component:
          "Aspect ratios are decorative, not structural. Use the named tokens below for media containers (hero images, gallery tiles, marketing cards). NEVER use 1.618 / 1.4142 for type, spacing, or radius scales — those follow the 4 px integer grid.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

const PLACEHOLDER =
  "data:image/svg+xml;utf8," +
  encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800">
      <defs>
        <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#4c6cb3"/>
          <stop offset="100%" stop-color="#165e83"/>
        </linearGradient>
      </defs>
      <rect width="800" height="800" fill="url(#g)"/>
    </svg>`,
  );

const RATIOS: { name: string; ratio: string; decimal: number; useCase: string; cultural?: string }[] = [
  { name: "square", ratio: "1 / 1", decimal: 1.0, useCase: "Gallery tile (Pinterest, Instagram)" },
  { name: "photo", ratio: "4 / 3", decimal: 1.333, useCase: "Photo card, classic monitor" },
  { name: "photo-3-2", ratio: "3 / 2", decimal: 1.5, useCase: "DSLR photo aspect" },
  { name: "video", ratio: "16 / 9", decimal: 1.778, useCase: "Hero banner, video thumbnail" },
  { name: "cinematic", ratio: "21 / 9", decimal: 2.333, useCase: "Cinematic banner" },
  {
    name: "silver",
    ratio: "1.4142",
    decimal: 1.4142,
    useCase: "Hero / marketing card",
    cultural:
      "白銀比 (hakuginhi) — A4 paper, manga panels, Horyuji temple, Toyota / Mitsubishi logos. Culturally Japanese.",
  },
  {
    name: "golden",
    ratio: "1.618",
    decimal: 1.618,
    useCase: "Decorative only — Western art convention",
    cultural:
      "黄金比 — REJECTED as a type/spacing/radius scale. Acceptable only for decorative aspect ratios when explicitly requested.",
  },
];

export const Catalog: Story = {
  render: () => (
    <div className="flex flex-col gap-6 p-6 max-w-4xl">
      <h2 className="text-xl font-medium">Aspect ratio catalog</h2>
      <p className="text-sm text-muted-foreground">
        Decorative ratios for media containers. Spacing, type, and radius
        scales use the integer 4 px grid — never these ratios.
      </p>
      <div className="grid grid-cols-2 gap-4">
        {RATIOS.map((r) => (
          <div key={r.name} className="border rounded-lg overflow-hidden">
            <div
              className="bg-muted"
              style={{ aspectRatio: r.ratio }}
            >
              <img src={PLACEHOLDER} alt="" className="size-full object-cover" />
            </div>
            <div className="p-3 space-y-1">
              <div className="flex items-baseline justify-between">
                <span className="text-sm font-medium">{r.name}</span>
                <span className="text-xs font-mono text-muted-foreground">
                  {r.ratio} ({r.decimal.toFixed(3)})
                </span>
              </div>
              <p className="text-xs text-muted-foreground">{r.useCase}</p>
              {r.cultural && (
                <p className="text-[11px] italic text-muted-foreground">{r.cultural}</p>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  ),
};

export const SilverVsGolden: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Side-by-side: 白銀比 (silver, 1.4142) vs 黄金比 (golden, 1.618) for a hero card. The silver ratio is the actually-Japanese choice — A4 paper proportions are baked into Japanese visual literacy.",
      },
    },
  },
  render: () => (
    <div className="grid grid-cols-2 gap-6 p-6 max-w-4xl">
      <Card className="overflow-hidden">
        <CardMedia aspectRatio="1.4142">
          <img src={PLACEHOLDER} alt="" className="size-full object-cover" />
        </CardMedia>
        <CardContent className="p-4">
          <div className="text-sm font-medium">白銀比 (silver, 1.4142)</div>
          <p className="text-xs text-muted-foreground mt-1">
            Culturally Japanese. A4, manga, Horyuji temple proportions.
          </p>
        </CardContent>
      </Card>
      <Card className="overflow-hidden">
        <CardMedia aspectRatio="1.618">
          <img src={PLACEHOLDER} alt="" className="size-full object-cover" />
        </CardMedia>
        <CardContent className="p-4">
          <div className="text-sm font-medium">黄金比 (golden, 1.618)</div>
          <p className="text-xs text-muted-foreground mt-1">
            Western art convention. Acceptable for decorative use only.
          </p>
        </CardContent>
      </Card>
    </div>
  ),
};

export const ScaleRejection: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Why 1.618 fails as a type/spacing scale: pixel rounding defeats the math within 2 steps, and the result lands off the 4 px grid that every other token in the system depends on.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-4 p-6 max-w-2xl">
      <h2 className="text-xl font-medium">Why golden ratio fails as a scale</h2>
      <pre className="text-xs font-mono bg-muted/30 p-4 rounded-md overflow-x-auto">
{`Start at 16 px body, multiply by 1.618 (golden ratio):

  16 × 1.618 = 25.888  → rounds to 26
  26 × 1.618 = 42.068  → rounds to 42
  42 × 1.618 = 67.956  → rounds to 68
  68 × 1.618 = 110.024 → rounds to 110

Sequence: 16, 26, 42, 68, 110

Problems:
  1. Within 2 steps the ratio is gone (26→42 is 1.615×, 42→68 is 1.619×)
  2. Every value is OFF the 4 px grid (26, 42, 68, 110 — none divisible by 4)
  3. Falls out of alignment with spacing-* tokens (4/8/12/16/24/32...)
  4. 26 px body breaks Japanese line-height calculations

Compare with 1.25 (major third) used by Tailwind v4:

  16 × 1.25 = 20   ✓ on grid
  20 × 1.25 = 25   ✗ off grid → round to 24 ✓
  24 × 1.25 = 30   ✗ off grid → round to 32 ✓
  32 × 1.25 = 40   ✓ on grid

  Sequence: 16, 20, 24, 32, 40 — every value divisible by 4. Aligns
  perfectly with the spacing scale.`}
      </pre>
      <p className="text-xs text-muted-foreground">
        Cited: NN/g &quot;The Golden Ratio in UI Design&quot; — &quot;others believe that
        the golden ratio is no more valid than any other method used to
        derive sizes and proportions&quot;. No major design system (Material,
        Carbon, Primer, Atlassian, Tailwind, SmartHR, Digital Agency) uses
        1.618 as its primary scale ratio.
      </p>
    </div>
  ),
};
