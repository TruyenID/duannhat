import type { Meta, StoryObj } from "@storybook/react-vite";

/**
 * Visual catalog for the type scale.
 *
 * Source: SmartHR + Ameba Spindle + JMDC convergent. Default body 14 px
 * (enterprise dense), line-height 1.7 (JMDC convergent CJK breathing —
 * 間 / ma). Override to text-md (16 px) on content-heavy surfaces.
 *
 * Font stack starts with Hiragino Sans → Yu Gothic → Noto Sans JP. The
 * mixed JA/EN/VI sample sentence below verifies CJK glyphs render with
 * adequate vertical breathing room.
 */
const meta: Meta = {
  title: "Foundations/Typography",
  parameters: {
    docs: {
      description: {
        component:
          "Type scale with absolute line-heights so CJK glyphs (kanji, kana, Vietnamese diacritics) never clip. Body default 14 px / line-height 1.7. Headings use line-height 1.25 (tight). 3 weights only: 400 / 500 / 700.",
      },
    },
  },
};
export default meta;

type Story = StoryObj;

const SAMPLE = "TempoFast デザインシステム — Hệ thống thiết kế — Design System 設計";

export const Scale: Story = {
  render: () => (
    <div className="flex flex-col gap-6 p-6 max-w-3xl">
      <div className="space-y-1">
        <p className="text-2xs font-mono">text-2xs · 11 px / 16</p>
        <p className="text-2xs">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-xs font-mono">text-xs · 12 px / 18</p>
        <p className="text-xs">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-sm font-mono">text-sm · 13 px / 22 — dense table cells</p>
        <p className="text-sm">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-base font-mono">text-base · 14 px / 24 — DEFAULT body (1.71 line-height ≈ JMDC 1.7)</p>
        <p className="text-base">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-md font-mono">text-md · 16 px / 28 — content-heavy</p>
        <p className="text-md">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-lg font-mono">text-lg · 18 px / 26 — subheading</p>
        <p className="text-lg font-medium">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-xl font-mono">text-xl · 20 px / 28 — h3</p>
        <p className="text-xl font-medium">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-2xl font-mono">text-2xl · 24 px / 32 — h2 (Ameba headline-4)</p>
        <p className="text-2xl font-medium">{SAMPLE}</p>
      </div>
      <div className="space-y-1">
        <p className="text-4xl font-mono">text-4xl · 32 px / 40 — h1 cap (SmartHR XXL)</p>
        <p className="text-4xl font-medium">{SAMPLE}</p>
      </div>
    </div>
  ),
};

export const Weights: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Per the 簡素 (kanso) principle: only 3 weights — normal (400), medium (500, heading default per freee vibes), bold (700, emphasis only). Avoid Tailwind's full 100-900 range.",
      },
    },
  },
  render: () => (
    <div className="flex flex-col gap-3 p-6 text-base">
      <p className="font-normal">font-normal · 400 · {SAMPLE}</p>
      <p className="font-medium">font-medium · 500 · {SAMPLE}</p>
      <p className="font-bold">font-bold · 700 · {SAMPLE}</p>
    </div>
  ),
};

export const LineHeightComparison: Story = {
  parameters: {
    docs: {
      description: {
        story:
          "Why Japanese systems converge on 1.7 for body: kanji and Vietnamese diacritics need vertical breathing. Compare 1.5 (Latin default) vs 1.7 (JP body) on a mixed-script paragraph.",
      },
    },
  },
  render: () => {
    const text = `日本語の本文では、漢字（kanji）と仮名（kana）が縦方向に詰まりやすいため、十分な行間が必要です。Tiếng Việt cũng có dấu phụ đậm nét đòi hỏi không gian dọc rộng rãi. The English text remains comfortable at either line-height because Latin glyphs have less vertical density.`;
    return (
      <div className="grid grid-cols-2 gap-6 p-6 max-w-4xl">
        <div className="space-y-2">
          <p className="text-xs font-mono text-muted-foreground">leading-normal · 1.5 (Latin default)</p>
          <p className="text-base leading-normal border-l-2 border-border pl-3">{text}</p>
        </div>
        <div className="space-y-2">
          <p className="text-xs font-mono text-muted-foreground">leading-body · 1.7 (JP convergent)</p>
          <p className="text-base leading-[1.7] border-l-2 border-primary pl-3">{text}</p>
        </div>
      </div>
    );
  },
};
