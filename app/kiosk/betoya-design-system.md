# betoya.jp — Design System Analysis
> Website: https://betoya.jp/ | Brand: ベト屋 (Viet Origin) — Vietnamese restaurant in Japan
> Nguồn: Screenshot + HTML/CSS source thực tế — April 2026

---

## QUAN TRỌNG — Lỗi phân tích trước đó

Lần phân tích đầu tiên **sai** vì nhầm WordPress Block Editor preset colors với brand palette thực tế.

| Nguồn | Thực tế |
|-------|---------|
| `--wp--preset--color--vivid-red: #cf2e2e` | Màu mặc định WordPress editor — **KHÔNG phải brand betoya** |
| `--wp--preset--color--pale-pink: #f78da7` | Màu mặc định WordPress editor — **KHÔNG phải brand betoya** |
| `background-color: #32373c` (button) | Style mặc định WordPress Classic Theme cho file/download buttons — **KHÔNG phải CTA chính** |

**Sự thật**: betoya dùng **Elementor** page builder + custom child theme `betoya-home`. Màu brand được define trong **Elementor Global Colors** (Kit ID: 719) — không xuất hiện trong WordPress preset CSS variables.

---

## 1. Bảng Màu Thực Tế (từ screenshot + HTML)

### Brand Colors (quan sát trực tiếp từ screenshot)

| Role | Màu | Hex (approx) | Dùng ở đâu |
|------|-----|--------------|------------|
| **Brand Green** | Xanh lá rừng/thiên nhiên | `~#3E7B4A` | Headline "なつかしい ハノイ・フォー", nút CTA "ネット注文", logo accent |
| **Warm Cream** | Kem vàng ấm | `~#F5EFD0` | Nav bar, hero section background — seamless, không phân biệt |
| **Near Black** | Gần đen | `~#1A1A1A` | Body text, nav links, secondary button text |
| **White** | Trắng | `#FFFFFF` | Secondary button "ケータリング" bg, card surfaces |

```
Brand:      ████ ~#3E7B4A  (Forest Green — primary brand)
Background: ████ ~#F5EFD0  (Warm Cream — NOT white)
Text:       ████ ~#1A1A1A  (Near Black)
Surface:    ████  #FFFFFF  (White — secondary elements)
```

> Hex values có dấu `~` = ước tính từ visual inspection.  
> **Lấy chính xác**: DevTools → chọn element → Computed Styles → lấy `color` / `background-color`.

### Tại sao nền KHÔNG phải trắng (quan trọng)
Betoya dùng **warm cream background** cho cả nav bar lẫn hero section — tạo cảm giác liền mạch, ấm áp. Đây là lựa chọn có chủ ý phản ánh:
- Thực phẩm tự nhiên, hữu cơ
- Khác với "tech white" của các app thông thường
- Ảnh food đặt trên cream → tự nhiên như thật, không bị tách rời

---

## 2. Technical Stack (từ HTML source)

```html
<!-- Xác nhận từ body class -->
class="wp-theme-vwc-prime wp-child-theme-betoya-home elementor-default elementor-page-16768"
```

| Layer | Technology |
|-------|------------|
| CMS | WordPress |
| Theme | `vwc-prime` (parent) + `betoya-home` (child) |
| Page Builder | **Elementor** (kit ID: 719) |
| E-commerce | WooCommerce |
| i18n | WPML (Japanese default) |
| Analytics | Google Tag Manager (GTM-TP26WGD) + GA4 |
| CDN | BunnyCDN (`betoya-jp.b-cdn.net`) |
| Font | Adobe Typekit (`hdj1pvt`) |

**Hệ quả**: Màu brand được lưu trong **Elementor Global Colors** (không phải WordPress CSS variables). Để lấy giá trị chính xác cần xem Elementor → Global Settings → Colors, hoặc dùng browser DevTools.

---

## 3. CSS Classes Thực Tế (từ HTML source)

### Layout Structure
```html
<div class="navbar-overlay">       <!-- Overlay khi mở side menu -->
<nav class="sidenav">              <!-- Side navigation (hamburger menu) -->
<div class="mobile-float-bar">     <!-- Bottom tab bar (mobile) -->
<header class="site-header">
  <div class="main-header">
    <div class="container site-header-content">
      <div class="menus-float">    <!-- Desktop nav -->
```

### Component Classes
```css
.primary-button      /* CTA chính — forest green background, white text */
.menu-item           /* Nav item (side + bottom bar) */
.menu-item-large     /* Larger nav item (top-level items in sidenav) */
.language-switcher   /* WPML language toggle */
.simple-svg-icon     /* SVG icon wrapper */
.close-button        /* Close icon trong sidenav */
.contact-button      /* Contact CTA trong sidenav bottom */
.social-medias       /* Social icons section trong sidenav */
.float-menus-list    /* Mobile bottom bar items list */
```

---

## 4. Navigation Architecture

### Desktop Header
```
[☰ Hamburger]  メニュー  こだわり  ケータリング  店舗一覧
                         [Logo: VIET ORIGIN ベト屋]
                                               [🌐 Language] [ネット注文 ← green btn]
```

### Side Navigation (hamburger menu)
```
[✕ Close]
[Language: 日本語 | English]

Large items (menu-item-large):
  🏠 ホーム
  👤 マイページ
  🛒 ネット注文
  (empty item)

Regular items:
  🛍 受取り予約
  🚗 デリバリー
  📢 お知らせ
  🍲 こだわり
  📖 ブランド
  📝 ブログ

Social: LINE | TikTok | X | Instagram
[お問い合わせ ← .primary-button]

More links: 採用情報 | 会社案内
```

### Mobile Bottom Tab Bar (`.mobile-float-bar`)
```
[🍜 メニュー]  [📍 店舗一覧]  [🛒 ネット注文]  [📢 お知らせ]
```

---

## 5. Typography

### Font Families (Adobe Typekit — xác nhận từ HTML)
```html
<link rel="stylesheet" href="https://use.typekit.net/hdj1pvt.css">
<!-- Loaded fonts (từ body class): -->
<!-- wf-zen-maru-gothic-n7-active        → Zen Maru Gothic Bold (700)  -->
<!-- wf-hiragino-kaku-gothic-pron-n6-active → Hiragino Kaku Gothic Pro N 600 -->
<!-- wf-hiragino-kaku-gothic-pron-n3-active → Hiragino Kaku Gothic Pro N 300 -->
```

| Font | Weight | Dùng cho |
|------|--------|----------|
| **Zen Maru Gothic** | 700 Bold | Headlines — rounded strokes, ấm áp, thân thiện |
| **Hiragino Kaku Gothic Pro N** | 600 SemiBold | Sub-headings, emphasis |
| **Hiragino Kaku Gothic Pro N** | 300 Light | Body text, captions |

### Tại sao Zen Maru Gothic?
- Font có **nét bo tròn** tại các đầu nét chữ — khác với Hiragino thẳng sắc
- Tạo cảm giác **friendly, approachable** — phù hợp restaurant
- Visible rõ trong screenshot: headline "なつかしい ハノイ・フォー" có rounded terminals

### Font Size Scale (từ WordPress preset)
| Label | Size | Dùng |
|-------|------|------|
| Small | 13px | Captions, labels |
| Medium | 20px | Body text |
| Large | 36px | Section headings |
| X-Large | 42px | Major headings |
| Hero | ~48-56px | Estimated từ screenshot |

---

## 6. Components (từ screenshot + HTML)

### `.primary-button` — CTA chính
```
Background: Forest Green ~#3E7B4A
Color:      White #FFFFFF
Padding:    ~12px 28px
Border-radius: ~8px (từ screenshot — KHÔNG phải pill)
Font-size:  ~16px, semibold
```
Dùng ở: "ネット注文" (nav), hero CTA, "お問い合わせ" (sidenav)

### Secondary Button — "ケータリング"
```
Background: White #FFFFFF
Color:      Near black ~#1A1A1A
Border:     1-2px solid ~#C8C0A0 (warm gray)
Border-radius: ~8px (tương đương primary)
```

### Nav Link style
```
Color:      Near black ~#1A1A1A
Decoration: None (hover có thể có underline)
```

### "ブランド物語" Link
```
Color:      Near black
Text-decoration: underline
```

---

## 7. Layout & Spacing

### Hero Section Layout (từ screenshot)
```
┌─────────────────────────────────────────────────────────────┐
│  WARM CREAM BACKGROUND (~#F5EFD0) — liền với nav bar        │
│                                                             │
│  ┌─────────────────────────┐   ┌───────────────────────┐   │
│  │ (text — ~40% width)     │   │  Food photography     │   │
│  │                         │   │  (phở bowl floating   │   │
│  │  なつかしい             │   │   on cream bg,        │   │
│  │  ハノイ・フォー         │   │   no frame/shadow)    │   │
│  │  [Green, bold, large]   │   │                       │   │
│  │                         │   │   ~60% width          │   │
│  │  Body text (small, dark)│   │                       │   │
│  │                         │   │                       │   │
│  │  [ブランド物語 ←link]   │   │                       │   │
│  │                         │   │                       │   │
│  │  [ケータリング] [ネット注文]│   │                       │   │
│  │   White btn  Green btn  │   │                       │   │
│  └─────────────────────────┘   └───────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### Spacing System (CSS variables — exact)
| CSS Var | Value | ~px |
|---------|-------|-----|
| `--wp--preset--spacing--20` | `0.44rem` | 7px |
| `--wp--preset--spacing--30` | `0.67rem` | 11px |
| `--wp--preset--spacing--40` | `1rem` | 16px |
| `--wp--preset--spacing--50` | `1.5rem` | 24px |
| `--wp--preset--spacing--60` | `2.25rem` | 36px |
| `--wp--preset--spacing--70` | `3.38rem` | 54px |
| `--wp--preset--spacing--80` | `5.06rem` | 81px |

---

## 8. Design Principles (từ observation)

### 1. Warmth over Sterility
Nền cream, không phải trắng. Màu xanh lá rừng, không phải xanh tech. Tất cả nói lên: "chúng tôi là thực phẩm tự nhiên, không phải công nghệ."

### 2. Consistency = Trust
Màu xanh `~#3E7B4A` xuất hiện ở **tất cả** CTA buttons + headline + logo accent. Không có màu primary thứ 2. Consistency = nhận diện thương hiệu mạnh.

### 3. Food First
Ảnh phở bowl chiếm ~60% viewport, text chỉ ~40%. Hierarchy: Cảm xúc (ảnh) trước, thông tin (text) sau.

### 4. Photography technique
Ảnh food đặt trên cream background — không có studio white background, không có shadow box. Hiệu ứng "thực phẩm tự nhiên đặt trên bàn kem" — authentic, không staged.

### 5. Button pair hierarchy
`[ケータリング]` White outline + `[ネット注文]` Green fill — hai hành động rõ ràng, hierarchy rõ ràng (primary vs secondary).

---

## 9. Brand Assets

| Asset | Format | Path |
|-------|--------|------|
| Logo Japanese text | SVG | `/wp-content/uploads/2023/12/LogoText-Japanese.svg` (1219×436px) |
| Hero food photo | JPEG | `/wp-content/uploads/2023/02/8.jpeg` (828×1241px, 2:3 portrait) |
| CDN | — | `https://betoya-jp.b-cdn.net` |

### Social Presence
- Facebook: `facebook.com/betoyajp`
- X (Twitter): `x.com/betoyajp`
- Instagram: `instagram.com/betoyajp`
- TikTok: `tiktok.com/@vietorigin`
- LINE: `lin.ee/b3PllG0`

---

## 10. Design Token Summary (chỉ brand colors thực tế)

```css
/* === BETOYA BRAND TOKENS (verified từ screenshot) === */

/* Backgrounds */
--betoya-bg:          ~#F5EFD0;   /* Warm cream — page, nav, hero */
--betoya-surface:      #FFFFFF;   /* White — buttons, cards on cream */

/* Brand identity */
--betoya-green:       ~#3E7B4A;   /* Forest green — primary brand color */

/* Text */
--betoya-text:        ~#1A1A1A;   /* Near black — body, nav, labels */

/* Buttons */
--betoya-btn-primary-bg:      ~#3E7B4A;   /* = betoya-green */
--betoya-btn-primary-text:     #FFFFFF;
--betoya-btn-secondary-bg:     #FFFFFF;
--betoya-btn-secondary-text:  ~#1A1A1A;
--betoya-btn-secondary-border:~#C8C0A0;   /* Warm gray border */

/* Typography */
--betoya-font-headline: 'zen-maru-gothic', sans-serif;   /* Bold 700 */
--betoya-font-body:     'hiragino-kaku-gothic-pron', sans-serif;  /* 300/600 */

/* === KHÔNG PHẢI BRAND BETOYA (WordPress editor presets — bỏ qua) === */
/* #cf2e2e, #f78da7, #ff6900, #abb8c3, #32373c, etc. */
/* → Những màu này là default WordPress Block Editor, không dùng trong design */
```

---

*Phân tích từ: screenshot thực tế + HTML source body/nav — April 2026*  
*Hex values có dấu `~` cần verify bằng browser DevTools trên betoya.jp*
