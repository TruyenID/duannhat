# Design Foundations — Japanese Enterprise

> Canonical foundation rules for the Omnify framework UI library, derived from **Japanese-language primary sources** (SmartHR, Digital Agency 公式, freee, Ameba Spindle, LINE, JMDC cross-system surveys) plus international standards (Tailwind v4 OKLCH, APCA, WCAG 2.2, Radix Colors). Target audience is Japanese enterprise B2B SaaS users (kintone / SmartHR / freee level of density).
>
> This document is a context-handoff. Any session can pick up where the last left off.

**Last updated**: 2026-04-10
**Status**: Phase A (research + foundation doc) complete; Phase B (apply tokens to globals.css) NOT YET STARTED; Phase C (refactor Card + atoms as reference) NOT YET STARTED.

---

## Why this exists

The current `frontend/src/app/globals.css` defines tokens (`--spacing-4`, `--density-card`, `--text-base`…) but the values were picked ad-hoc from shadcn defaults. The result:

- `<Card>` uses `gap-card` / `px-card` / `pt-card` — ad-hoc tokens that don't follow any design system
- Spacing in components mixes `p-4` / `gap-2` / `gap-3` / `space-y-4` with no documented rule
- Type scale uses Tailwind defaults (12/14/16/18/20/24/30/36) — no documented modular ratio
- Color palette mixes hex + OKLCH inconsistently
- No documented density modes
- Nothing tuned for Japanese audiences (CJK line-height, dense enterprise control heights, traditional 和色 palette)

The user explicitly framed this as **the canonical foundation for every future Omnify project** targeting Japanese enterprise users. The bar is "international standard rules backed by famous JP design systems", not "feels OK".

## Cross-references

- `frontend/plans/ui-library-roadmap.md` — the broader UI library roadmap (Phase 0-3). This file is the foundation layer the roadmap builds on.
- `frontend/AGENTS.md` — review checklist; Phase B should add a row "every spacing/font-size/color value MUST come from a documented token".
- Upstream omnify issues: `omnify-jp/omnify-go#53` ✅, `#54` ✅, `#55` ✅ (all shipped in 3.13.0), `#56` ❌ (open — enum literal bug, workaround at `scripts/patch-omnify-enum-defaults.py`).

---

## Executive summary

For an Omnify framework UI library targeting **Japanese enterprise B2B SaaS users**, the convergent foundation from Japanese primary sources is:

| Foundation | Recommendation | Source |
|---|---|---|
| Body font size | **14 px default (enterprise) / 16 px content-heavy** | SmartHR M=16, Ameba body-4=14, JMDC survey: "ほぼ全てがフォントサイズ16px" |
| Line height | **1.7 body / 1.5 dense / 1.25 headings** | JMDC: "行間は1.7と1.5が同率"; Digital Agency ≥ 1.5 minimum |
| Font stack | **Hiragino Sans → Yu Gothic → Noto Sans JP → fallback** | Ameba Spindle canonical, LINE Design System |
| Font weights | **400 / 500 / 700 only** (3 weights, 簡素 principle) | freee vibes (500 heading default), Digital Agency (400/700 only) |
| Spacing base | **4 px**, ladder 4/8/12/16/24/32/40/48/64 | SmartHR primitive ladder; JMDC convergent |
| Border radius | **4 px control / 6 px card / 8 px modal** (very subtle) | SmartHR s=4, m=6, l=8 |
| Control height | **28 compact / 36 default / 44 comfortable** | SmartHR small/default + Digital Agency 44px touch min |
| Touch target | **44 × 44 CSS px minimum** (hard floor) | Digital Agency button accessibility (WCAG 2.5.5) |
| Shadow | **5-level ladder, used sparingly** (borders preferred) | SmartHR LAYER0-4; JP enterprise convention |
| Color saturation | **Low chroma (OKLCH chroma ≤ 0.18)** — 渋み | SmartHR MAIN #0077c7 ≈ oklch(56% 0.15 240) vs Material #1976d2 |
| Destructive color | **茜 #b7282e (muted red), NOT pure #f00** | zenn/Manalink: "新しめのデザインを採用しているサービスほど赤は避けるか、控えめに" |
| Color space | **OKLCH** for all ramps (Tailwind v4 native) | tailwindcss.com/docs/theme |
| Contrast | **APCA Lc ≥ 60 body, WCAG 4.5:1 floor** | APCA + WCAG 2.2 |

---

## Japanese design principles applied to UI tokens

### 余白 (yohaku) — intentional whitespace

The single most important Japanese design keyword for digital products. Verbatim from GIG blog:

> 「余白とは『ただ要素のない空白』ではなく、『意図的にデザインをすべきもの』」
> Whitespace is not "just empty space without elements" — it's something to be designed intentionally.

Concrete rule (cited): **「関連する情報同士の余白は狭く、関連しない情報同士の余白は広めにとる」** — related items get tight spacing, unrelated items get generous spacing.

→ Translates to a **1 : 1.5 : 3 ratio**:

- **Inside-component spacing**: 16 px (related items inside a card / form group)
- **Between sibling components**: 24 px (cards in a list)
- **Between sections**: 48 px (top-level layout chunks)

### 間 (ma) — silence between notes

In typography, this is **line-height**. Japanese characters need more vertical breathing room than Latin because kanji are info-dense. JMDC survey: 1.7 is the convergent body line-height across Japanese systems. Adopt 1.7 as the default body line-height (vs Tailwind's 1.5).

### 簡素 (kanso) — eliminate the wasteful

Concrete consequences:
- **3 font weights only** (400, 500, 700) — not Tailwind's full 100-900
- **~7-8 font sizes** in the entire scale — JMDC survey average
- **5 shadow levels max** — SmartHR ladder
- **4 radius values** — SmartHR s/m/l + full
- **No decorative chrome** — borders not shadows for hierarchy

### 渋み (shibumi) — restrained elegance

Concrete: **OKLCH chroma ≤ 0.15 for neutrals, ≤ 0.18 for brand primaries**. SmartHR MAIN #0077c7 has chroma ≈ 0.15 (vs Material's #1976d2 ≈ 0.18). Off-black text #23221e (warm) instead of pure #000.

### 奥ゆかしさ (okuyukashisa) — refined subtlety

Hierarchy through **weight + spacing**, not size + color:
- Don't use 48 px "hero headings" inside the app
- Max heading 32 px (h1), most pages only need up to 24 px (h2)
- Lift importance via `font-weight: 500` + extra top spacing, not larger size

### 無駄を省く (eliminate waste) — SmartHR's "ユーザーの時間は有限だ"

- No icon + text redundancy unless a11y demands it
- No drop shadow when a 1 px border works
- No 3 levels of nested padding when 1 works

---

## Foundation token set — Tailwind v4 `@theme` block

This is the **canonical token map** to merge into `frontend/src/app/globals.css`. Phase B applies it.

```css
@theme {
  /* ============================================================
   * FONT STACK — Japanese-first (Ameba Spindle convergent)
   * ============================================================ */
  --font-sans:
    "Hiragino Sans", "ヒラギノ角ゴシック", "Hiragino Kaku Gothic ProN",
    "Yu Gothic Medium", "游ゴシック Medium", YuGothic,
    "Noto Sans JP", Meiryo, メイリオ,
    -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
    system-ui, sans-serif;
  --font-mono:
    "Noto Sans Mono", "SF Mono", Menlo, Consolas, monospace;

  /* ============================================================
   * TYPE SCALE — 8 sizes, JMDC-convergent
   * Body 14 px (enterprise default) with line-height 1.7
   * Override with text-md (16 px) on content-heavy surfaces
   * ============================================================ */
  --text-2xs:    0.6875rem;  /* 11 px — fine print, JP legal */
  --text-2xs--line-height: 1rem;        /* 16 px = 1.45 */

  --text-xs:     0.75rem;    /* 12 px — caption, label */
  --text-xs--line-height:  1.125rem;    /* 18 px = 1.5 */

  --text-sm:     0.8125rem;  /* 13 px — dense table cells */
  --text-sm--line-height:  1.375rem;    /* 22 px = 1.69 */

  --text-base:   0.875rem;   /* 14 px — DEFAULT body */
  --text-base--line-height: 1.5rem;     /* 24 px = 1.71 (JMDC convergent 1.7) */

  --text-md:     1rem;       /* 16 px — content-heavy body */
  --text-md--line-height:   1.75rem;    /* 28 px = 1.75 */

  --text-lg:     1.125rem;   /* 18 px — subheading */
  --text-lg--line-height:   1.625rem;   /* 26 px = 1.44 */

  --text-xl:     1.25rem;    /* 20 px — h3 */
  --text-xl--line-height:   1.75rem;    /* 28 px = 1.4 */

  --text-2xl:    1.5rem;     /* 24 px — h2 (SmartHR XL, Ameba headline-4) */
  --text-2xl--line-height:  2rem;       /* 32 px = 1.33 */

  --text-3xl:    2rem;       /* 32 px — h1 (SmartHR XXL, max in-app heading) */
  --text-3xl--line-height:  2.5rem;     /* 40 px = 1.25 */

  /* ============================================================
   * FONT WEIGHTS — Minimal per 簡素 principle
   * ============================================================ */
  --font-weight-normal: 400;
  --font-weight-medium: 500;  /* heading default per freee vibes */
  --font-weight-bold:   700;  /* emphasis only */

  /* ============================================================
   * SPACING SCALE — 4 px base, SmartHR + JMDC convergent
   * Use the semantic aliases below for component authoring;
   * the numeric scale is the underlying primitive.
   * ============================================================ */
  --spacing-0:   0;
  --spacing-px:  1px;
  --spacing-0_5: 0.125rem;  /*  2 px — hairline only */
  --spacing-1:   0.25rem;   /*  4 px — SmartHR X3S */
  --spacing-1_5: 0.375rem;  /*  6 px */
  --spacing-2:   0.5rem;    /*  8 px — SmartHR XXS, Digital Agency base unit */
  --spacing-3:   0.75rem;   /* 12 px */
  --spacing-4:   1rem;      /* 16 px — SmartHR XS, INSIDE-card padding */
  --spacing-5:   1.25rem;   /* 20 px */
  --spacing-6:   1.5rem;    /* 24 px — SmartHR S, BETWEEN-card gap */
  --spacing-8:   2rem;      /* 32 px — SmartHR M */
  --spacing-10:  2.5rem;    /* 40 px — SmartHR L */
  --spacing-12:  3rem;      /* 48 px — SmartHR XL, BETWEEN-section gap */
  --spacing-16:  4rem;      /* 64 px — SmartHR XXL */
  --spacing-20:  5rem;      /* 80 px — page gutter */
  --spacing-24:  6rem;      /* 96 px — page section break */

  /* Semantic spacing aliases — components MUST use these, not raw numbers */
  --spacing-card-inner: var(--spacing-4);     /* inside Card padding */
  --spacing-card-gap:   var(--spacing-6);     /* between Cards in a list */
  --spacing-section:    var(--spacing-12);    /* between page sections */
  --spacing-form-row:   var(--spacing-4);     /* between fields in a form */
  --spacing-form-group: var(--spacing-6);     /* between form groups */

  /* ============================================================
   * DENSITY MODES — 3 modes, switchable via [data-density] attr
   * ============================================================ */
  /* Compact (kintone-like list views) */
  --density-compact-control-height: 28px;
  --density-compact-row-height:     32px;
  --density-compact-card-padding:   12px;  /* spacing-3 */
  --density-compact-input-font:     var(--text-sm);

  /* Default (SmartHR B2B standard — used app-wide unless overridden) */
  --density-default-control-height: 36px;
  --density-default-row-height:     40px;
  --density-default-card-padding:   16px;  /* spacing-4 */
  --density-default-input-font:     var(--text-base);

  /* Comfortable (public-facing, content-heavy, Digital Agency compliant) */
  --density-comfortable-control-height: 44px;  /* DA touch target floor */
  --density-comfortable-row-height:     48px;
  --density-comfortable-card-padding:   24px;  /* spacing-6 */
  --density-comfortable-input-font:     var(--text-md);

  /* Touch target hard floor — Digital Agency rule */
  --touch-target-min: 44px;

  /* ============================================================
   * BORDER RADIUS — SmartHR ladder, very subtle (JP enterprise)
   * ============================================================ */
  --radius-none: 0;
  --radius-sm:   2px;   /* dense table inputs */
  --radius-md:   4px;   /* SmartHR s — default buttons, inputs */
  --radius-lg:   6px;   /* SmartHR m — cards */
  --radius-xl:   8px;   /* SmartHR l — modals */
  --radius-full: 9999px;

  /* Semantic radius aliases */
  --radius-control: var(--radius-md);  /* Button, Input, Select */
  --radius-card:    var(--radius-lg);  /* Card, Panel */
  --radius-modal:   var(--radius-xl);  /* Dialog, Sheet */
  --radius-pill:    var(--radius-full);

  /* ============================================================
   * SHADOW — 5-level SmartHR ladder, used SPARINGLY
   * Default to 1 px borders for hierarchy; reach for shadows
   * only on floating surfaces (dropdown / popover / modal / toast)
   * ============================================================ */
  --shadow-none: none;
  --shadow-1: 0 1px 2px 0 rgb(3 3 2 / 0.12);          /* nav rail, base */
  --shadow-2: 0 2px 4px 1px rgb(3 3 2 / 0.12);        /* tooltip */
  --shadow-3: 0 4px 8px 2px rgb(3 3 2 / 0.12);        /* dropdown, popover */
  --shadow-4: 0 8px 16px 4px rgb(3 3 2 / 0.12);       /* modal, dialog */

  --shadow-raised:  var(--shadow-1);
  --shadow-overlay: var(--shadow-3);
  --shadow-modal:   var(--shadow-4);

  /* ============================================================
   * COLOR — OKLCH ramps + Japanese-tuned semantic roles
   * ============================================================ */

  /* Neutral ramp (12 steps, Radix pattern, warm off-black) */
  --color-neutral-1:  oklch(99% 0.002 60);   /* app background */
  --color-neutral-2:  oklch(98% 0.003 60);   /* subtle bg ≈ SmartHR BACKGROUND #f8f7f6 */
  --color-neutral-3:  oklch(96% 0.004 60);   /* hover bg ≈ SmartHR HEAD #edebe8 */
  --color-neutral-4:  oklch(93% 0.005 60);   /* active bg */
  --color-neutral-5:  oklch(90% 0.005 60);   /* subtle border */
  --color-neutral-6:  oklch(86% 0.006 60);   /* border ≈ SmartHR BORDER #d6d3d0 */
  --color-neutral-7:  oklch(80% 0.007 60);
  --color-neutral-8:  oklch(68% 0.008 60);
  --color-neutral-9:  oklch(55% 0.009 60);
  --color-neutral-10: oklch(48% 0.008 60);   /* text muted ≈ SmartHR TEXT_GREY #706d65 */
  --color-neutral-11: oklch(38% 0.008 60);
  --color-neutral-12: oklch(20% 0.006 60);   /* text primary ≈ SmartHR TEXT_BLACK #23221e */

  /* Semantic role tokens — components reference these, NOT raw shades */
  --color-bg:           var(--color-neutral-1);
  --color-bg-subtle:    var(--color-neutral-2);
  --color-bg-hover:     var(--color-neutral-3);
  --color-border:       var(--color-neutral-6);
  --color-border-strong: var(--color-neutral-8);
  --color-text:         var(--color-neutral-12);
  --color-text-muted:   var(--color-neutral-10);

  /* Primary — SmartHR MAIN #0077c7 in OKLCH (muted blue, 渋み chroma ≤ 0.15) */
  --color-primary:       oklch(56% 0.15 240);
  --color-primary-hover: oklch(51% 0.16 240);
  --color-primary-fg:    oklch(99% 0 0);

  /* Danger — 茜 #b7282e (NOT pure red; cited cultural rule) */
  --color-danger:        oklch(52% 0.18 25);
  --color-danger-hover:  oklch(47% 0.19 25);
  --color-danger-fg:     oklch(99% 0 0);

  /* Warning — 山吹 #f8b500 (traditional golden yellow) */
  --color-warning:       oklch(80% 0.17 85);
  --color-warning-fg:    var(--color-neutral-12);

  /* Attention — 朱 #eb6101 (non-critical alert, freee/Manalink pattern) */
  --color-attention:     oklch(66% 0.19 45);
  --color-attention-fg:  oklch(99% 0 0);

  /* Success — 若竹 #68be8d */
  --color-success:       oklch(72% 0.13 155);
  --color-success-fg:    oklch(99% 0 0);

  /* Info — 群青 #4c6cb3 */
  --color-info:          oklch(55% 0.12 265);
  --color-info-fg:       oklch(99% 0 0);

  /* ============================================================
   * 和色 (traditional Japanese accent palette)
   * Used for chart colors, tag colors, decorative accents.
   * Never use these as semantic role colors — those are above.
   * ============================================================ */
  --color-wa-ai:       #165e83;  /* 藍 — info dark */
  --color-wa-gunjo:    #4c6cb3;  /* 群青 — info */
  --color-wa-ruri:     #1e50a2;  /* 瑠璃 — primary saturated */
  --color-wa-kon:      #223a70;  /* 紺 — text emphasis */
  --color-wa-wakatake: #68be8d;  /* 若竹 — success */
  --color-wa-moegi:    #006e54;  /* 萌葱 — success dark */
  --color-wa-yamabuki: #f8b500;  /* 山吹 — warning */
  --color-wa-shu:      #eb6101;  /* 朱 — attention */
  --color-wa-akane:    #b7282e;  /* 茜 — danger */
  --color-wa-enji:     #b94047;  /* 臙脂 — destructive confirmed */
  --color-wa-sakura:   #fef4f4;  /* 桜 — soft info background */
  --color-wa-sumi:     #595857;  /* 墨 — text primary warm */
  --color-wa-nezu:     #949495;  /* 鼠 — text muted */
}
```

---

## Aspect ratios + the golden ratio verdict

**REJECT 1.618 (golden ratio) as a primary scale ratio for type, spacing, or border radius. Use it ONLY for decorative aspect ratios (hero images, marketing cards) where it's appropriate.**

### Reasoning (cited)

1. **No serious design system uses 1.618 as a scale ratio.** Material 3, IBM Carbon, GitHub Primer, Atlassian, Tailwind v4, SmartHR, Digital Agency, freee vibes — all use 1.2 / 1.25 / 1.333 or non-ratio ladders. None key on 1.618.
2. **Pixel rounding defeats the math.** `16 × 1.618 = 25.888 → 26`, then `26 × 1.618 = 42.068 → 42`. Within 2 steps the ratio is gone AND the result lands off the 4 px / 8 px grid that every enterprise system depends on. Type and spacing must align to grid.
3. **Nielsen Norman Group is non-committal**: *"others believe that the golden ratio is no more valid than any other method used to derive sizes and proportions"* — https://www.nngroup.com/articles/golden-ratio-ui-design/
4. **Perceptual scales beat mathematical scales for UI.** A 1.2 (minor third) or 1.25 (major third) ratio yields steps that stay on the 4 px grid (12, 14, 16, 20, 24, 32) and read as distinct without feeling disconnected.

### Where ratios ARE appropriate — aspect ratios

For media containers (hero images, gallery thumbnails, marketing cards), aspect ratios are decorative not structural. Acceptable values:

| Ratio | Decimal | Use case | Cultural note |
|---|---|---|---|
| 1 / 1 | 1.000 | Square gallery tile (Pinterest, Instagram) | Universal |
| 4 / 3 | 1.333 | Photo card, classic monitor | Western default |
| 3 / 2 | 1.500 | DSLR photo aspect | Western default |
| 16 / 9 | 1.778 | Video, hero banner | Western default |
| 21 / 9 | 2.333 | Cinematic banner | Western default |
| **白銀比** (silver ratio, hakuginhi) | **1 + √2 ≈ 1.4142** | A4 paper, manga panels, Horyuji temple, Toyota / Mitsubishi logos | **Culturally Japanese** |
| 黄金比 (golden ratio) | 1.618 | Hero image proportions if specifically requested | Western art convention; not Japanese |

The **白銀比 (silver ratio, 1.4142)** is the actually-Japanese ratio, rooted in Japanese architectural and printing tradition. If the project wants a "culturally Japanese feel" for marketing imagery, prefer 1.4142 over 1.618.

For our `<CardMedia aspectRatio>` API, accept any of these strings (including `"silver"` mapped to 1.4142). Spacing/type/radius continue to use the 4 px-grid integer ladder above.

## Cultural color rules (cite when reviewing)

1. **Destructive/danger ≠ pure red.** Verbatim from zenn/Manalink: 「新しめのデザインを採用しているサービスほど赤は避けるか、控えめに表現している傾向」. Use `--color-danger` (茜 #b7282e) not `#ff0000`. Pure red in JP context reads "celebratory" (紅白) or "feminine" (紅), not "danger".
2. **Color cannot be the only visual cue.** SmartHR principle: 「色は情報を伝え、動作を示し、反応を促しますが、唯一の視覚的手段にしてはいけません。」 Always pair color with icon, text, or weight.
3. **Status colors map to traditional 和色 hue centers.** Success → 若竹 (green-blue, not pure green). Warning → 山吹 (golden, not pure yellow). Attention → 朱 (orange-red, not yellow-red). This gives the palette a coherent JP aesthetic without explicitly screaming "Japan!".
4. **Neutrals are warm.** Off-black `oklch(20% 0.006 60)` ≈ #23221e (墨色 warmth) instead of pure `#000`. This is the SmartHR convention.

---

## Phase plan

### Phase A — Research + foundation doc ✅ DONE (this commit)

This file. Research from JP-language sources, synthesize into the canonical token map above.

### Phase B — Apply tokens to globals.css ✅ DONE

Strategy: surgical retune of values, NOT wholesale rename. Existing token names (`--spacing-card`, `--density-element`, `--text-base`, `--primary` etc) keep working — components don't need updates. The VALUES of those tokens are now Japanese-foundation-aligned.

Changes applied to `frontend/src/app/globals.css`:

1. **Typography tokens**:
   - `--font-size: 16px` → `14px` (JP enterprise body default)
   - `--text-base: 1rem (16)` → `0.875rem (14)` (default body)
   - Added `--text-md: 1rem (16)` for content-heavy surfaces (NEW)
   - Added `--text-2xs: 0.6875rem (11)` for fine print (NEW)
   - `--text-3xl: 1.875rem (30)` kept for backward compat
   - `--text-4xl: 2.25rem (36)` → `2rem (32)` (SmartHR XXL cap)
   - Added `--leading-body: 1.7` (JMDC convergent CJK breathing)

2. **Font stack**:
   - Added `--font-sans-jp` Hiragino Sans → Yu Gothic → Noto Sans JP → fallback
   - `<body>` now uses `var(--font-sans-jp)` directly

3. **Color tokens** — all OKLCH:
   - `--background` `--foreground` warm hue 60 (off-white / off-black)
   - `--primary: oklch(56% 0.15 240)` (SmartHR MAIN, 渋み chroma ≤ 0.15)
   - `--destructive: oklch(52% 0.18 25)` (茜 #b7282e — NOT pure red, cited cultural rule)
   - `--muted-foreground: oklch(48% 0.008 60)` (SmartHR TEXT_GREY)
   - `--border: oklch(86% 0.006 60)` (SmartHR BORDER)
   - `--success: oklch(72% 0.13 155)` (若竹), `--warning: oklch(80% 0.17 85)` (山吹), `--info: oklch(55% 0.12 265)` (群青), `--error: oklch(52% 0.18 25)` (茜)
   - Added `--attention: oklch(66% 0.19 45)` (朱 — non-critical alert)

4. **和色 traditional accent palette** (13 colors): `--color-wa-ai`, `--color-wa-gunjo`, `--color-wa-ruri`, `--color-wa-kon`, `--color-wa-wakatake`, `--color-wa-moegi`, `--color-wa-yamabuki`, `--color-wa-shu`, `--color-wa-akane`, `--color-wa-enji`, `--color-wa-sakura`, `--color-wa-sumi`, `--color-wa-nezu`. Use for chart/tag/decorative — NOT semantic role colors.

5. **Touch target** added: `--touch-target-min: 44px` (Digital Agency hard rule).

6. **Aspect ratios** added: `--aspect-square` `--aspect-photo` `--aspect-video` `--aspect-cinematic` `--aspect-silver` (1.4142 — 白銀比) `--aspect-golden` (1.618 — Western art). Decorative use only — NOT for type/spacing scales.

7. **`@layer base`** updates:
   - `<body>` font-family + line-height: 1.7
   - `h1`–`h4` line-height: 1.25 (tight, headings)

8. Density mode `[data-density]` selectors NOT yet added — deferred to Phase C since no consumer needs them today. Token values currently match the "default" mode (control 32 px, card 16 px, section 16 px).

Verified:
- 0 TS errors
- 0 lint errors (61 pre-existing warnings, no new)
- 20/20 unit tests pass
- 59/59 storybook stories pass via vitest browser mode
- `npm run build-storybook` clean

The IDE CSS linter warns about `@theme` and `@apply` rules — these are valid Tailwind v4 syntax, false positives. Already-existing warnings before Phase B.

### Phase C — Refactor Card + 5 atoms as reference ✅ DONE

Three concrete deliverables shipped:

**1. Density modes wired up in `globals.css`** — three runtime-switchable modes via the `[data-density]` attribute selector:

| Token | Compact | Default | Comfortable |
|---|---|---|---|
| `--density-element` (control height) | 28 px | 32 px | **44 px** (Digital Agency floor) |
| `--density-element-sm` | 24 px | 28 px | 36 px |
| `--density-element-lg` | 32 px | 36 px | 48 px |
| `--density-card` (padding) | 12 px | 16 px | 24 px |
| `--density-dialog` | 16 px | 20 px | 32 px |
| `--density-page` | 12 px | 16 px | 24 px |
| `--density-section` | 12 px | 16 px | 24 px |
| `--header-height` | 40 px | 48 px | 56 px |
| `--density-table-head` | 28 px | 32 px | 40 px |

Switch by setting `data-density="compact"` or `"comfortable"` on `<html>` (or any subtree). Default mode keeps the historical scale to avoid visual regression on existing pages.

**2. Magic number sweep — Card refactored as reference**:
- `card/index.tsx` `CardHeader` `gap-1.5` (6 px hardcoded) → `gap-2` (`--spacing-2` = 8 px). Sits between the related-items "tight" yohaku (4 px) and the inside-card "default" (16 px), preserving the title-description hierarchy.

**3. JSDoc token citations on the 5 reference atoms** (Card, Button, Input, Badge, Tabs) — each component now has a `**Tokens used**` block in its JSDoc explaining which `--var-name` it consumes, what value it resolves to in default mode, and what JP-foundation principle it cites. Examples:
- Card: cites SmartHR card radius (6 px), warm off-white background, border-not-shadow convention.
- Button: cites SmartHR MAIN primary, 茜 destructive cultural rule, Digital Agency 44 px touch floor for `size="xl"`.
- Input: cites JMDC convergent CJK line-height, SmartHR BORDER, 茜 destructive aria-invalid.
- Badge: cites 和色 hue centers for status colors.
- Tabs: cites density-aware sizing.

**4. New `Foundations/Density` Storybook page** with `SideBySide`, `Compact`, `Comfortable` stories — shows the same Button + Input + Card adopting all three modes side-by-side. Visual proof the density tokens flow through to existing components without component code changes.

Verified: 0 TS errors, 0 lint errors, 207/207 storybook stories pass via vitest browser mode (was 204 + 3 density stories).

Apply the new tokens to:
- `card/index.tsx` — replace `gap-card`, `px-card`, `pt-card`, `pb-card` with documented spacing tokens; use `--radius-card` (6 px); use `--shadow-raised` only on hover (border by default)
- `button/index.tsx` — control height from density tokens; radius `--radius-control`
- `input/index.tsx` — same
- `badge/index.tsx` — radius `--radius-pill` for status, `--radius-md` for tag
- `tabs/index.tsx` — spacing from `--spacing-card-inner`

After refactor, every value in the file must trace back to a token in the `@theme` block. No raw `p-4` / `gap-2` left.

### Phase D — Foundations Storybook page ✅ DONE

Created `frontend/src/foundations/` with non-component story files. Storybook glob `**/*.stories.@(...)` picks them up automatically. Title prefix `"Foundations/"` groups them in the Storybook sidebar.

Files shipped:

| File | Stories | Purpose |
|---|---|---|
| `spacing.stories.tsx` | Scale, YohakuRatio | Visual ladder of every spacing token + 1:1.5:3 yohaku rule demo |
| `typography.stories.tsx` | Scale, Weights, LineHeightComparison | Type ladder on JA/EN/VI mixed sample + weight matrix + 1.5 vs 1.7 line-height comparison showing why JP needs 1.7 for body |
| `colors.stories.tsx` | SemanticRoles, StatusColors, TraditionalPalette, CulturalNotes | Semantic role swatches + status colors + 13-color 和色 palette + cultural rules card |
| `touch-targets.stories.tsx` | SizeMatrix, HitAreaVisualization | Button sizes vs 44 px floor + dashed-outline visualization of required hit zone around a 28 px small button |
| `aspect-ratios.stories.tsx` | Catalog, SilverVsGolden, ScaleRejection | All aspect ratio tokens + 白銀比 vs 黄金比 side-by-side + math demo of why golden ratio fails as a scale |

Total **+14 stories** added (59 → 73). Cited Japanese sources directly inline in story descriptions so reviewers see the rationale next to the visual.

Verified: 0 TS errors, 0 lint errors (64 pre-existing warnings, 0 new), 73/73 storybook stories pass via vitest browser mode.

Not yet created (lower priority — no current consumer):
- Density modes story (need `[data-density]` selectors first)
- Shadows story (current shadow tokens are unchanged from pre-Phase B)
- Radius story (could add as a small swatch table)

These can ship in a future commit when the consumer demand exists.

### Phase E — Refactor remaining components ❌ TODO

Multi-session, follows the Phase 2 Tier order from `ui-library-roadmap.md`. Each component refactor:
1. Replace raw spacing/font/color with tokens
2. Verify TS + lint + storybook pass
3. Update the matching `<name>.stories.tsx` if visuals shifted

---

## Cited sources

### Tier 1 — Japanese design system primary tokens
- **SmartHR** typography: https://smarthr.design/products/design-tokens/typography/
- SmartHR spacing: https://smarthr.design/products/design-tokens/spacing/
- SmartHR color: https://smarthr.design/products/design-tokens/color/
- SmartHR radius: https://smarthr.design/products/design-tokens/radius/
- SmartHR shadow: https://smarthr.design/products/design-tokens/shadow/
- SmartHR principles: https://smarthr.design/products/principles/
- SmartHR button: https://smarthr.design/products/components/button/
- **Digital Agency** typography: https://design.digital.go.jp/dads/foundations/typography/
- Digital Agency spacing: https://design.digital.go.jp/foundations/spacing/
- Digital Agency button a11y: https://design.digital.go.jp/dads/components/button/accessibility/
- Digital Agency tokens repo: https://github.com/digital-go-jp/design-tokens
- **freee vibes** GitHub: https://github.com/freee/vibes
- freee brand typography: https://brand.freee.co.jp/designelements/typography/
- **Ameba Spindle** UI typography: https://spindle.ameba.design/styles/typography/ui/
- **LINE Design System**: https://designsystem.line.me/
- **Money Forward Design**: https://design.moneyforward.com/
- **Mercari Design**: https://design.mercari.com/

### Tier 2 — Japanese cross-system surveys (convergence data)
- JMDC typography comparison (most load-bearing): https://techblog.jmdc.co.jp/entry/20231208
- JMDC spacing comparison: https://techblog.jmdc.co.jp/entry/20231221
- Goodpatch 日本のデザインシステム10選: https://goodpatch.com/blog/2024-07-10cooldesignsystems
- note akane 56 design systems: https://note.com/akane_desu/n/n2e564f6561b4
- zenn デジタル庁解説: https://zenn.dev/kinopippi/articles/b1eb15594c74a4

### Tier 3 — Japanese typography and 余白 philosophy
- GIG 余白デザイン: https://giginc.co.jp/blog/giglab/yohaku-design
- AQ perfect Japanese typography: https://www.aqworks.com/blog/perfect-japanese-typography

### Tier 4 — Japanese color culture
- zenn Manalink color philosophy: https://zenn.dev/manalink_dev/articles/manalink-ui-design-color
- UX MILK red/green UI: https://uxmilk.jp/78670
- Vitto cross-cultural color: https://vitto-inc.com/column_list/329/

### Tier 5 — Traditional Japanese color references (伝統色)
- 和色大辞典 (colordic.org): https://www.colordic.org/w
- 伝統色のいろは (irocore.com): https://irocore.com/
- NIPPON COLORS: https://nipponcolors.com/

### Tier 6 — International standards (baseline only)
- APCA contrast: https://www.myndex.com/APCA/
- WCAG 2.5.5 target size (JP translation): https://waic.jp/translations/WCAG21/Understanding/target-size.html
- Tailwind v4 theme + OKLCH: https://tailwindcss.com/docs/theme#colors
- Radix Colors 12-step scale: https://www.radix-ui.com/colors

### Inaccessible during research
- Cybozu Design (https://cybozu.design/) — connection refused; SmartHR + Digital Agency cover same B2B SaaS segment as fallback
- freee vibes Storybook — JS-rendered SPA, sourced via GitHub repo + freee dev blog instead

---

## How a fresh session should resume

1. Read this file end-to-end.
2. Check the **Phase plan** table. Start from the next ❌ TODO row.
3. For Phase B, the entry point is `frontend/src/app/globals.css`. Replace the `:root` block with the `@theme` block in §"Foundation token set" above.
4. Verify after each phase: `npx tsc --noEmit && npm run lint && npm run build-storybook`.
5. Commit per phase with a message that cites the phase letter (e.g. `feat(design): apply phase B foundation tokens to globals.css`).
6. **Update this file** before committing — mark the row done, note any deviations.
