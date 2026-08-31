import type { Meta, StoryObj } from "@storybook/react-vite";

/**
 * Visual catalog for the color system.
 *
 * Source: SmartHR semantic role tokens + 和色 (traditional Japanese)
 * accent palette + OKLCH color space (Tailwind v4 native, perceptually
 * uniform). All primaries chroma ≤ 0.18 per 渋み (shibumi) — restrained
 * elegance.
 *
 * Cultural rule (cited): destructive ≠ pure red. Use 茜 #b7282e instead.
 * Verbatim from zenn/Manalink: 「新しめのデザインを採用しているサービスほど赤は
 * 避けるか、控えめに表現している傾向」.
 */
const meta: Meta = {
  title: "Foundations/Colors",
  parameters: {
    docs: {
      description: {
        component:
          "OKLCH color system with semantic role tokens + Japanese 和色 traditional accent palette. Components reference semantic roles (`bg-primary`, `text-foreground`); 和色 tokens are for charts, tags, and decorative accents only.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

interface Swatch {
  name: string;
  cssVar: string;
  use: string;
}

const SEMANTIC: Swatch[] = [
  { name: "background", cssVar: "--background", use: "App background (warm off-white)" },
  { name: "foreground", cssVar: "--foreground", use: "Text primary (warm off-black ≈ SmartHR TEXT_BLACK)" },
  { name: "primary", cssVar: "--primary", use: "Primary actions (SmartHR MAIN — chroma 0.15)" },
  { name: "primary-foreground", cssVar: "--primary-foreground", use: "On-primary text" },
  { name: "secondary", cssVar: "--secondary", use: "Subtle bg" },
  { name: "muted", cssVar: "--muted", use: "Muted bg / disabled" },
  { name: "muted-foreground", cssVar: "--muted-foreground", use: "Muted text (≈ SmartHR TEXT_GREY)" },
  { name: "accent", cssVar: "--accent", use: "Hover bg" },
  { name: "border", cssVar: "--border", use: "Default border (≈ SmartHR BORDER)" },
];

const STATUS: Swatch[] = [
  { name: "destructive", cssVar: "--destructive", use: "Destructive — 茜 #b7282e (NOT pure red)" },
  { name: "success", cssVar: "--success", use: "Success — 若竹 #68be8d" },
  { name: "warning", cssVar: "--warning", use: "Warning — 山吹 #f8b500" },
  { name: "info", cssVar: "--info", use: "Info — 群青 #4c6cb3" },
  { name: "attention", cssVar: "--attention", use: "Attention — 朱 #eb6101 (non-critical alert)" },
  { name: "error", cssVar: "--error", use: "Error — same hue as destructive" },
];

const WA: { name: string; kanji: string; cssVar: string; hex: string; role: string }[] = [
  { name: "ai", kanji: "藍", cssVar: "--color-wa-ai", hex: "#165e83", role: "info dark" },
  { name: "gunjo", kanji: "群青", cssVar: "--color-wa-gunjo", hex: "#4c6cb3", role: "info" },
  { name: "ruri", kanji: "瑠璃", cssVar: "--color-wa-ruri", hex: "#1e50a2", role: "primary saturated" },
  { name: "kon", kanji: "紺", cssVar: "--color-wa-kon", hex: "#223a70", role: "text emphasis" },
  { name: "wakatake", kanji: "若竹", cssVar: "--color-wa-wakatake", hex: "#68be8d", role: "success" },
  { name: "moegi", kanji: "萌葱", cssVar: "--color-wa-moegi", hex: "#006e54", role: "success dark" },
  { name: "yamabuki", kanji: "山吹", cssVar: "--color-wa-yamabuki", hex: "#f8b500", role: "warning" },
  { name: "shu", kanji: "朱", cssVar: "--color-wa-shu", hex: "#eb6101", role: "attention" },
  { name: "akane", kanji: "茜", cssVar: "--color-wa-akane", hex: "#b7282e", role: "danger" },
  { name: "enji", kanji: "臙脂", cssVar: "--color-wa-enji", hex: "#b94047", role: "destructive confirmed" },
  { name: "sakura", kanji: "桜", cssVar: "--color-wa-sakura", hex: "#fef4f4", role: "soft info bg" },
  { name: "sumi", kanji: "墨", cssVar: "--color-wa-sumi", hex: "#595857", role: "text primary warm" },
  { name: "nezu", kanji: "鼠", cssVar: "--color-wa-nezu", hex: "#949495", role: "text muted" },
];

function SwatchRow({ swatch }: { swatch: Swatch }) {
  return (
    <div className="flex items-center gap-3 py-2 border-b border-border">
      <div
        className="h-12 w-20 rounded-md border border-border shrink-0"
        style={{ backgroundColor: `var(${swatch.cssVar})` }}
      />
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium">{swatch.name}</div>
        <div className="text-xs font-mono text-muted-foreground">{swatch.cssVar}</div>
      </div>
      <div className="text-xs text-muted-foreground max-w-md">{swatch.use}</div>
    </div>
  );
}

export const SemanticRoles: Story = {
  render: () => (
    <div className="flex flex-col p-6 max-w-3xl">
      <h2 className="text-xl font-medium mb-2">Semantic role tokens</h2>
      <p className="text-sm text-muted-foreground mb-4">
        Components MUST reference these tokens (via Tailwind classes
        <code className="text-xs font-mono mx-1">bg-primary</code>
        <code className="text-xs font-mono mx-1">text-foreground</code>
        etc) — never raw hex / OKLCH values.
      </p>
      {SEMANTIC.map((s) => (
        <SwatchRow key={s.name} swatch={s} />
      ))}
    </div>
  ),
};

export const StatusColors: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Status semantic colors mapped to traditional 和色 hue centers — gives the palette a coherent JP aesthetic. Destructive uses 茜 instead of pure red per cited cultural rule.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col p-6 max-w-3xl">
      <h2 className="text-xl font-medium mb-2">Status colors (和色 hues)</h2>
      <p className="text-sm text-muted-foreground mb-4">
        SmartHR + Japanese cultural convention. The non-pure-red destructive
        is the load-bearing decision: 「赤は控えめに」.
      </p>
      {STATUS.map((s) => (
        <SwatchRow key={s.name} swatch={s} />
      ))}
    </div>
  ),
};

export const TraditionalPalette: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "和色 (traditional Japanese colors) for charts, tags, and decorative accents. NEVER use these as semantic role colors — those live in `--primary` / `--destructive` etc above.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col p-6">
      <h2 className="text-xl font-medium mb-2">和色 — Traditional Japanese accent palette</h2>
      <p className="text-sm text-muted-foreground mb-4">
        13 traditional colors from colordic.org / irocore.com. Use for chart
        series, tag colors, and decorative accents — not semantic UI roles.
      </p>
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        {WA.map((c) => (
          <div key={c.name} className="border rounded-md overflow-hidden">
            <div className="h-16" style={{ backgroundColor: c.hex }} />
            <div className="p-3">
              <div className="flex items-baseline gap-2">
                <span className="text-base font-medium">{c.kanji}</span>
                <span className="text-xs text-muted-foreground">{c.name}</span>
              </div>
              <div className="text-xs font-mono text-muted-foreground">{c.hex}</div>
              <div className="text-xs text-muted-foreground mt-1">{c.role}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  ),
};

export const CulturalNotes: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Cultural rules every reviewer must enforce. Cited from zenn/Manalink, UX MILK, and Vitto cross-cultural color articles.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-4 p-6 max-w-3xl text-sm">
      <h2 className="text-xl font-medium">Cultural color rules</h2>
      <div className="border rounded-lg p-4 bg-card">
        <h3 className="font-medium mb-2">1. Destructive ≠ pure red</h3>
        <p className="text-muted-foreground">
          Cited (zenn/Manalink): 「新しめのデザインを採用しているサービスほど赤は避けるか、控えめに表現している傾向」.
          Pure red <span className="font-mono text-xs">#ff0000</span> reads
          &quot;celebratory&quot; (紅白) or &quot;feminine&quot; (紅) in JP context, not &quot;danger&quot;.
          Use <span className="font-mono text-xs">--destructive</span> (茜
          oklch 52% 0.18 25, ≈ #b7282e) instead.
        </p>
      </div>
      <div className="border rounded-lg p-4 bg-card">
        <h3 className="font-medium mb-2">2. Color cannot be the only visual cue</h3>
        <p className="text-muted-foreground">
          SmartHR principle: 「色は情報を伝え、動作を示し、反応を促しますが、唯一の視覚的手段にしてはいけません。」
          Always pair status color with an icon, text label, or font weight.
        </p>
      </div>
      <div className="border rounded-lg p-4 bg-card">
        <h3 className="font-medium mb-2">3. Neutrals are warm, not pure</h3>
        <p className="text-muted-foreground">
          Off-black <span className="font-mono text-xs">oklch(20% 0.006 60)</span>
          {" "}≈ #23221e (墨色 warmth) instead of pure #000. SmartHR convention.
          Pure black is too harsh against the warm Japanese paper-white background.
        </p>
      </div>
      <div className="border rounded-lg p-4 bg-card">
        <h3 className="font-medium mb-2">4. Status colors map to 和色 hues</h3>
        <p className="text-muted-foreground">
          Success → 若竹 (green-blue), Warning → 山吹 (golden), Attention → 朱
          (orange-red). This gives the palette a coherent JP aesthetic without
          shouting &quot;Japan!&quot;.
        </p>
      </div>
    </div>
  ),
};
