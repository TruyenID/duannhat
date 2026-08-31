import type { Meta, StoryObj } from "@storybook/react-vite";

/**
 * Visual catalog for the spacing scale defined in `globals.css`.
 *
 * Source: SmartHR primitive ladder + JMDC convergent survey + Digital Agency
 * 8 px base unit. See `plans/design-foundations-japanese.md` §"Foundation
 * token set". Default body 14 px / line-height 1.7 / 4 px-grid spacing.
 *
 * Yohaku (余白) rule from GIG blog: 「関連する情報同士の余白は狭く、関連しない情報
 * 同士の余白は広めにとる」 — related items get tight spacing, unrelated get
 * generous. Concrete ratio in our system: **inside-card 16 px : between-cards
 * 24 px : between-sections 48 px** (1 : 1.5 : 3).
 */
const meta: Meta = {
  title: "Foundations/Spacing",
  parameters: {
    docs: {
      description: {
        component:
          "4 px-base spacing scale, SmartHR + JMDC convergent. Use the named tokens (`gap-card`, `gap-section`, `p-page`) in component code — never raw `p-7` / `gap-[13px]` magic numbers.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

const SCALE: { token: string; px: number; semantic?: string }[] = [
  { token: "spacing-0_5", px: 2, semantic: "hairline only" },
  { token: "spacing-1", px: 4, semantic: "X3S — icon gap" },
  { token: "spacing-1_5", px: 6 },
  { token: "spacing-2", px: 8, semantic: "XXS — Digital Agency base unit" },
  { token: "spacing-3", px: 12 },
  { token: "spacing-4", px: 16, semantic: "XS — INSIDE-card padding (yohaku tight)" },
  { token: "spacing-5", px: 20 },
  { token: "spacing-6", px: 24, semantic: "S — BETWEEN-card gap (yohaku loose)" },
  { token: "spacing-8", px: 32, semantic: "M" },
  { token: "spacing-10", px: 40, semantic: "L" },
  { token: "spacing-12", px: 48, semantic: "XL — BETWEEN-section gap (yohaku very loose)" },
  { token: "spacing-16", px: 64, semantic: "XXL" },
  { token: "spacing-20", px: 80, semantic: "page gutter" },
  { token: "spacing-24", px: 96, semantic: "page section break" },
];

export const Scale: Story = {
  render: () => (
    <div className="flex flex-col gap-3 p-4">
      <h2 className="text-xl font-medium">Spacing scale</h2>
      <p className="text-sm text-muted-foreground">
        4 px base. SmartHR + JMDC convergent.
      </p>
      <table className="w-full text-sm">
        <thead className="text-xs text-muted-foreground">
          <tr>
            <th className="py-2 text-left">Token</th>
            <th className="py-2 text-left">px</th>
            <th className="py-2 text-left">Semantic role</th>
            <th className="py-2 text-left">Visual</th>
          </tr>
        </thead>
        <tbody>
          {SCALE.map(({ token, px, semantic }) => (
            <tr key={token} className="border-t border-border">
              <td className="py-1.5 font-mono text-xs">--{token}</td>
              <td className="py-1.5 font-mono text-xs">{px}</td>
              <td className="py-1.5 text-xs text-muted-foreground">{semantic ?? ""}</td>
              <td className="py-1.5">
                <div
                  className="bg-primary"
                  style={{ width: `${px}px`, height: "8px" }}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  ),
};

export const YohakuRatio: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "余白 (yohaku) rule: related items tight, unrelated loose. Our system uses **1 : 1.5 : 3** ratio — 16 px inside, 24 px between siblings, 48 px between sections.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-12 p-8 bg-muted/30 max-w-2xl">
      {/* Section A */}
      <section className="flex flex-col gap-6">
        <h3 className="text-base font-medium">Section A — between-section gap = 48 px (spacing-12)</h3>
        <div className="flex flex-col gap-6">
          {/* Card 1 — between-card gap = 24 px (spacing-6) */}
          <div className="bg-card border rounded-lg p-4 flex flex-col gap-4">
            <h4 className="text-sm font-medium">Card with inside padding 16 px (spacing-4)</h4>
            <p className="text-xs text-muted-foreground">
              Items inside the card use spacing-4 = 16 px gap between them. This
              is the &quot;tight&quot; half of the yohaku rule — related items.
            </p>
            <p className="text-xs">Another related item, 16 px below.</p>
          </div>
          {/* Card 2 — same spacing */}
          <div className="bg-card border rounded-lg p-4 flex flex-col gap-4">
            <h4 className="text-sm font-medium">Card 2 — 24 px below the previous card</h4>
            <p className="text-xs text-muted-foreground">
              The gap between sibling cards is spacing-6 = 24 px. Loose enough
              to read as separate, tight enough to read as related.
            </p>
          </div>
        </div>
      </section>

      {/* Section B */}
      <section className="flex flex-col gap-6">
        <h3 className="text-base font-medium">Section B — 48 px below Section A</h3>
        <p className="text-sm text-muted-foreground">
          Sections (top-level layout chunks) are separated by spacing-12 = 48 px.
          This is the &quot;loose&quot; extreme of the yohaku rule — unrelated content.
        </p>
      </section>
    </div>
  ),
};
