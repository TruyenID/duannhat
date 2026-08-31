# Design Pattern — godx-kiosk (TMS App)
> Kết hợp: betoya.jp visual aesthetic + NativeWind/Tailwind token system + CLAUDE.md rules
> Stack: Expo 54 + React Native 0.81 + NativeWind + TanStack Query v5

---

## 1. Triết lý thiết kế (Design Philosophy)

Lấy cảm hứng từ **betoya.jp** — Vietnamese restaurant tại Nhật:

> **Warm Natural Minimalism** — Ấm áp, hữu cơ, không lạnh lùng. Màu nền cream thay vì trắng sterile. Xanh lá thiên nhiên thay vì xanh công nghệ.

### Đặc trưng của betoya (từ screenshot thực tế)

| Đặc trưng | betoya.jp | Áp dụng trong app |
|-----------|-----------|-------------------|
| **Nền kem vàng ấm** — không phải trắng | `~#F5EFD0` nav + hero | `bg-gray-50` (#F9FAFB) cho page bg — gần nhất có thể với NativeWind |
| **Brand green** — xanh lá rừng, nhất quán cho headline + CTA | `~#3E7B4A` | `bg-primary` (#030213) hiện tại = đen. Cân nhắc thêm token `brand-green` |
| **Xanh/Trắng button pair** — Green = primary, White = secondary | Rõ ràng hierarchy | `variant="default"` vs `variant="outline"` |
| **Rounded corners thân thiện** — không phải pill, không phải square | ~8px | `rounded-xl` cho cards, `rounded-2xl` cho table cards |
| **Ảnh/icon là focal point** — text ngắn, hình nói nhiều | Food photography float tự nhiên | SVG icons, table state colors nổi bật |
| **Font tròn** — Zen Maru Gothic = ấm, không formal | Bold + rounded strokes | System Japanese fonts có sẵn (Hiragino Sans) |
| **Spacing rộng rãi** | Nhiều whitespace | `px-5`, `gap-3` đến `gap-6`, `py-20` empty states |

---

## 2. Color Tokens

> **Rule 12**: KHÔNG hardcode hex. Dùng semantic token từ `tailwind.config.js`.

### 2.0 betoya Brand Palette (nguồn cảm hứng — verified từ screenshot)

```
Role              Hex (approx)   Dùng ở betoya.jp
────────────────  ─────────────  ─────────────────────────────────────────
Brand Green       ~#3E7B4A       Headline chính, nút CTA, logo accent
Warm Cream        ~#F5EFD0       Nav bar + hero background (KHÔNG phải trắng)
Near Black        ~#1A1A1A       Body text, nav links
White             #FFFFFF        Secondary button bg, card surfaces
Warm Gray border  ~#C8C0A0       Border của secondary button
```

> Hex có dấu `~` = ước tính từ visual inspection. Verify chính xác bằng browser DevTools → Computed Styles trên betoya.jp.

**Mapping vào project tokens (đã áp dụng trong `tailwind.config.js`):**

| betoya | Token | Giá trị |
|--------|-------|---------|
| Forest Green `~#3E7B4A` | `bg-primary` | `#3E7B4A` — button, tab active, focus ring |
| Warm Cream `~#F5EFD0` | `bg-background` | `#F5EFD0` — page background |
| Near Black `~#1A1A1A` | `text-foreground` | `#1A1A1A` — body text, nav |
| White `#FFFFFF` | `bg-card` / `bg-secondary` | `#FFFFFF` — cards, secondary button |
| Warm Gray `~#C8C0A0` | `border` | `#C8C0A0` — borders, secondary button outline |

### 2.1 Semantic Colors (tailwind.config.js — nguồn duy nhất)

```
bg-background          #F5EFD0    Nền page chính — betoya warm cream
text-foreground        #1A1A1A    Text chính — betoya near black
bg-card                #FFFFFF    Nền card/panel — white float trên cream
text-card-foreground   #1A1A1A    Text trong card

bg-primary             #3E7B4A    Button mặc định, tab active — betoya forest green
text-primary-foreground #FFFFFF   Text trên nền primary

bg-secondary           #FFFFFF    Button phụ — white bg (betoya secondary button)
text-secondary-foreground #1A1A1A

bg-muted               #EDE7CC    Skeleton, divider, input disabled — cream-tinted
text-muted-foreground  #717182    Subtext, placeholder, label phụ

bg-accent              #E8E2CC    Hover state, ghost button active — cream-tinted
text-accent-foreground #1A1A1A

bg-destructive         #D4183D    Lỗi, xoá, nguy hiểm
text-destructive       (dùng text-destructive trực tiếp)

bg-success             #10B981    Thành công, xác nhận
bg-warning             #F59E0B    Cảnh báo
bg-info                #3B82F6    Thông tin

border                 #C8C0A0    Borders — betoya warm gray
border-input           #D4CDB0    Border của input fields
ring                   #3E7B4A    Focus ring = brand green
```

### 2.2 Table Status Colors (semantic — PHẢI dùng thay vì raw Tailwind)

Hiện tại code dùng `bg-emerald-500`, `bg-red-500` — **vi phạm Rule 12**.
Đây là mapping chuẩn:

```
Trạng thái      Token hiện tại (SAI)    Token đúng (PHẢI dùng)
────────────    ────────────────────    ──────────────────────
free            bg-white                bg-background
occupied        bg-emerald-500          bg-success
call_staff      bg-red-500              bg-destructive
recently_paid   bg-sky-200              bg-info/20 (hoặc thêm token)
```

**Cần thêm vào `tailwind.config.js`:**

```js
// Table display state colors (thêm vào extend.colors)
"table-free":          "#FFFFFF",
"table-occupied":      "#10B981",   // = success
"table-call-staff":    "#D4183D",   // = destructive
"table-recently-paid": "#DBEAFE",   // = blue-100
"table-free-text":         "#0A0A0A",
"table-occupied-text":     "#FFFFFF",
"table-call-staff-text":   "#FFFFFF",
"table-recently-paid-text":"#1E3A8A",
"table-free-sub":          "#717182",
"table-occupied-sub":      "#D1FAE5",
"table-call-staff-sub":    "#FEE2E2",
"table-recently-paid-sub": "#93C5FD",
```

### 2.3 Gray Scale (dùng cho structural elements)

```
bg-gray-50     #F9FAFB    Page background (SafeAreaView)
bg-gray-100    #F3F4F6    Input background, tab inactive, icon bg
border-gray-100 #F3F4F6   Header border, divider nhạt
text-gray-400  #9CA3AF    Label section (uppercase tracking)
text-gray-500  #6B7280    Legend text, placeholder
text-gray-600  #4B5563    Tab text inactive
text-gray-700  #374151    Input label
```

---

## 3. Typography

> **Rule 11**: Dùng `className` NativeWind. **Rule 13**: Mọi text qua `t()`.
> Dùng `<Text>` component từ `@/components/ui/text` (có `variant` prop).

### 3.1 Text Variants (từ `src/components/ui/text.tsx`)

```tsx
// H1 — Hero/page title: text-4xl font-extrabold tracking-tight text-center
<Text variant="h1">{t("page.title")}</Text>

// H2 — Section heading: text-3xl font-semibold tracking-tight + border-b
<Text variant="h2">{t("section.title")}</Text>

// H3 — Card title: text-2xl font-semibold tracking-tight
<Text variant="h3">{t("card.title")}</Text>

// H4 — Sub-section: text-xl font-semibold tracking-tight
<Text variant="h4">{t("sub.title")}</Text>

// Lead — Intro text: text-xl text-muted-foreground
<Text variant="lead">{t("intro.text")}</Text>

// Large — Emphasized body: text-lg font-semibold
<Text variant="large">{t("body.large")}</Text>

// Default — Body: text-base text-foreground
<Text>{t("body.default")}</Text>

// Small — Label: text-sm font-medium leading-none
<Text variant="small">{t("label")}</Text>

// Muted — Hint/secondary: text-sm text-muted-foreground
<Text variant="muted">{t("hint")}</Text>
```

### 3.2 Typography trong context thực tế

```tsx
// Header screen title
<Text className="text-xl font-bold">{t("tms.title")}</Text>

// Sub-info dưới title
<Text className="text-xs text-muted-foreground">{device?.branch?.name}</Text>

// Section label (uppercase tracking — betoya pattern)
<Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
  {t("section.label")}
</Text>

// Table name trong card
<Text className="text-lg font-bold text-table-free-text">{table.name}</Text>

// Table status badge text
<Text className="text-[11px] font-semibold">{t(STATE_I18N[ds])}</Text>

// Metadata nhỏ (code, seats)
<Text className="text-xs text-table-free-sub">{table.code}</Text>
```

### 3.3 Font Family

```js
// tailwind.config.js — fontFamily.sans (theo thứ tự ưu tiên)
fontFamily: {
  sans: ["Hiragino Sans", "Yu Gothic", "Noto Sans JP", "System"],
}
```

Phản chiếu betoya.jp: Hiragino Kaku Gothic (system Japanese font) — clear, không quá formal.

---

## 4. Spacing & Layout

### 4.1 Spacing Scale (ánh xạ từ betoya CSS vars)

| Tailwind | Value | Betoya equiv | Dùng khi |
|----------|-------|--------------|----------|
| `p-1` / `gap-1` | 4px | — | Icon padding |
| `gap-1.5` | 6px | spacing-20 (~7px) | Legend dot + text |
| `p-2` / `gap-2` | 8px | — | Badge padding |
| `gap-2.5` | 10px | — | Table card grid gap |
| `gap-3` | 12px | spacing-30 (~11px) | Form fields, card sections |
| `p-3` | 12px | — | Table card padding |
| `p-4` | 16px | spacing-40 (16px) | Settings card padding |
| `px-4 py-3` | — | — | Header bar |
| `px-5` | 20px | — | Page horizontal padding |
| `gap-4` | 16px | — | Card internal sections |
| `gap-6` | 24px | spacing-50 (24px) | Card default gap (Card component) |
| `py-6` | 24px | — | Card vertical padding |
| `gap-3 mt-2` | — | — | Skeleton section |
| `py-20` | 80px | spacing-80 (~81px) | Empty state vertical center |

### 4.2 Layout Patterns

```tsx
// ── Page Container ──────────────────────────────────────────────
<SafeAreaView className="flex-1 bg-gray-50">
  ...
</SafeAreaView>

// ── Header Bar ──────────────────────────────────────────────────
<View className="px-5 pt-4 pb-3 flex-row items-center justify-between bg-white border-b border-gray-100">
  <View className="flex-1">
    <Text className="text-xl font-bold">{title}</Text>
    <Text className="text-xs text-muted-foreground">{subtitle}</Text>
  </View>
  {/* Actions */}
</View>

// ── Sub-header / Tab Bar ────────────────────────────────────────
<View className="bg-white border-b border-gray-100">
  <ScrollView horizontal showsHorizontalScrollIndicator={false}
    contentContainerClassName="px-5 gap-2 py-3">
    {/* Pills */}
  </ScrollView>
</View>

// ── Scrollable Content Area ─────────────────────────────────────
<ScrollView className="flex-1" contentContainerClassName="px-4 pb-8">
  {/* Content */}
</ScrollView>

// ── Section với label ───────────────────────────────────────────
<View className="gap-3">
  <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
    {t("section.label")}
  </Text>
  {/* Section content */}
</View>

// ── Card container ──────────────────────────────────────────────
<View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">
  {/* Card content */}
</View>

// ── Table grid ──────────────────────────────────────────────────
<View className="flex-row flex-wrap gap-2.5">
  {tables.map(table => <TableCard key={table.id} table={table} />)}
</View>

// ── Divider ─────────────────────────────────────────────────────
<View className="h-px bg-gray-100" />
```

---

## 5. Components

> **Rule 11**: Tất cả dùng `className`. Không `StyleSheet.create()`.
> Dùng CVA variants từ `src/components/ui/`.

### 5.1 Button

```tsx
import { Button } from "@/components/ui/button";
import { Text } from "@/components/ui/text";

// Primary — Action chính (lưu, xác nhận)
<Button variant="default" onPress={handleSave}>
  <Text>{t("common.save")}</Text>
</Button>

// Outline — Action phụ (retry, test connection)
<Button variant="outline" onPress={handleRetry}>
  <Text>{t("tms.retry")}</Text>
</Button>

// Ghost — Icon button (back, settings gear)
<Button variant="ghost" size="icon" onPress={() => router.back()}>
  <ChevronLeftIcon />
</Button>

// Destructive — Xoá, logout
<Button variant="destructive" onPress={handleLogout}>
  <Text>{t("common.logout")}</Text>
</Button>

// Disabled state (opacity-50 tự apply)
<Button disabled={!inputIp.trim()} onPress={handleSave}>
  <Text>{t("common.save")}</Text>
</Button>

// Full width
<Button className="w-full" onPress={handleSave}>
  <Text>{t("common.save")}</Text>
</Button>
```

**Button sizes:**
| Size | Height | Padding | Dùng khi |
|------|--------|---------|----------|
| `sm` | h-8/h-9 | px-3 | Legend, tabs nội bộ |
| `default` | h-9/h-10 | px-4 | Form buttons |
| `lg` | h-10/h-11 | px-6 | Hero CTA |
| `icon` | h-9w-9/h-10w-10 | — | Nav icon buttons |

### 5.2 Card

```tsx
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from "@/components/ui/card";

// Card đầy đủ
<Card>
  <CardHeader>
    <CardTitle>{t("card.title")}</CardTitle>
    <CardDescription>{t("card.description")}</CardDescription>
  </CardHeader>
  <CardContent>
    {/* body */}
  </CardContent>
  <CardFooter>
    <Button className="w-full"><Text>{t("common.save")}</Text></Button>
  </CardFooter>
</Card>

// Card đơn giản (custom — như settings.tsx)
<View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">
  {/* content */}
</View>
```

### 5.3 Badge / Status Pill

```tsx
import { Badge } from "@/components/ui/badge";
import { Text } from "@/components/ui/text";

// Semantic variants
<Badge variant="default"><Text>{t("status.active")}</Text></Badge>
<Badge variant="secondary"><Text>{t("status.inactive")}</Text></Badge>
<Badge variant="destructive"><Text>{t("status.error")}</Text></Badge>
<Badge variant="outline"><Text>{t("status.pending")}</Text></Badge>

// Custom inline pill (khi cần màu đặc thù — status printer)
<View className="px-2 py-1 rounded-full bg-success/10">
  <Text className="text-xs font-medium text-success">{t("settings.printer_saved")}</Text>
</View>

// Custom inline pill — unconfigured state
<View className="px-2 py-1 rounded-full bg-gray-100">
  <Text className="text-xs font-medium text-gray-400">{t("settings.printer_not_configured")}</Text>
</View>
```

### 5.4 Tab Pills (Zone selector)

```tsx
// Pattern: horizontal ScrollView + Pressable pills
<ScrollView horizontal showsHorizontalScrollIndicator={false}
  contentContainerClassName="px-5 gap-2 py-3">

  {/* "All" tab */}
  <Pressable
    onPress={() => setActiveZoneId(null)}
    className={`px-4 py-2 rounded-full ${
      activeZoneId === null ? "bg-primary" : "bg-gray-100"
    }`}
  >
    <Text className={`text-sm font-medium ${
      activeZoneId === null ? "text-primary-foreground" : "text-gray-600"
    }`}>
      {t("tms.all_zones")}
    </Text>
  </Pressable>

  {/* Zone tabs */}
  {zones.map(zone => (
    <Pressable
      key={zone.id}
      onPress={() => setActiveZoneId(zone.id)}
      className={`px-4 py-2 rounded-full ${
        activeZoneId === zone.id ? "bg-primary" : "bg-gray-100"
      }`}
    >
      <Text className={`text-sm font-medium ${
        activeZoneId === zone.id ? "text-primary-foreground" : "text-gray-600"
      }`}>
        {zone.name}
      </Text>
    </Pressable>
  ))}
</ScrollView>
```

### 5.5 Table Card

Đây là component cốt lõi của app. State-driven appearance.

```tsx
// ── Type definitions (src/types/tms.ts) ─────────────────────────
type TableDisplayState = "free" | "occupied" | "call_staff" | "recently_paid";

// ── Color maps — PHẢI dùng token, KHÔNG raw Tailwind colors ─────
// (Sau khi thêm tokens vào tailwind.config.js)
const CARD_BG: Record<TableDisplayState, string> = {
  free:          "bg-table-free",
  occupied:      "bg-table-occupied",
  call_staff:    "bg-table-call-staff",
  recently_paid: "bg-table-recently-paid",
};

const CARD_TEXT: Record<TableDisplayState, string> = {
  free:          "text-table-free-text",
  occupied:      "text-table-occupied-text",
  call_staff:    "text-table-call-staff-text",
  recently_paid: "text-table-recently-paid-text",
};

const CARD_SUB_TEXT: Record<TableDisplayState, string> = {
  free:          "text-table-free-sub",
  occupied:      "text-table-occupied-sub",
  call_staff:    "text-table-call-staff-sub",
  recently_paid: "text-table-recently-paid-sub",
};

// ── Anatomy ─────────────────────────────────────────────────────
<Animated.View
  style={ds === "call_staff" ? animatedStyle : undefined}
  className={`w-[30%] min-w-[100px] rounded-2xl p-3 gap-1.5 ${CARD_BG[ds]}`}
>
  {/* Row 1: Name + icon */}
  <View className="flex-row items-center justify-between">
    <Text className={`text-lg font-bold ${CARD_TEXT[ds]}`}>{table.name}</Text>
    {ds === "call_staff" && <BellIcon />}
  </View>

  {/* Row 2: Code (micro text) */}
  <Text className={`text-[10px] ${CARD_SUB_TEXT[ds]}`}>{table.code}</Text>

  {/* Row 3: Seat count */}
  <Text className={`text-xs ${CARD_SUB_TEXT[ds]}`}>
    {t("tms.seats")}: {table.seat_count}
  </Text>

  {/* Row 4: Status label */}
  <Text className={`text-[11px] font-semibold ${CARD_TEXT[ds]} mt-0.5`}>
    {t(STATE_I18N[ds])}
  </Text>

  {/* CTA: chỉ hiện khi call_staff */}
  {ds === "call_staff" && (
    <Pressable
      onPress={handleClearCall}
      className="mt-1 bg-white/20 rounded-lg py-1 px-2 items-center"
    >
      <Text className="text-white text-[11px] font-semibold">
        ✓ {t("action.call_resolved")}
      </Text>
    </Pressable>
  )}
</Animated.View>
```

**Table Card display state priority (từ CLAUDE.md):**
```
call_staff > recently_paid > occupied > free
```

### 5.6 Legend Bar

```tsx
// Pattern: horizontal strip với dot + label + count
const LEGEND: { state: TableDisplayState; dot: string }[] = [
  { state: "free",          dot: "bg-table-free border border-gray-300" },
  { state: "occupied",      dot: "bg-table-occupied" },
  { state: "call_staff",    dot: "bg-table-call-staff" },
  { state: "recently_paid", dot: "bg-table-recently-paid" },
];

<View className="px-5 py-2.5 flex-row flex-wrap gap-x-5 gap-y-1">
  {LEGEND.map(({ state, dot }) => (
    <View key={state} className="flex-row items-center gap-1.5">
      <View className={`w-3 h-3 rounded-sm ${dot}`} />
      <Text className="text-xs text-gray-500">
        {t(STATE_I18N[state])} {stateCounts[state] ?? 0}
      </Text>
    </View>
  ))}
</View>
```

### 5.7 Skeleton Loading

```tsx
import { Skeleton } from "@/components/ui/skeleton";

// Table grid skeleton
function LoadingSkeleton() {
  return (
    <View className="gap-3 mt-2">
      <Skeleton className="h-3 w-20 mb-1" />
      <View className="flex-row flex-wrap gap-2.5">
        {Array.from({ length: 6 }).map((_, i) => (
          <View key={i} className="w-[30%] min-w-[100px]">
            <Skeleton className="h-28 rounded-2xl" />
          </View>
        ))}
      </View>
    </View>
  );
}

// List skeleton
function ListSkeleton({ count = 4 }: { count?: number }) {
  return (
    <View className="gap-3 px-4">
      {Array.from({ length: count }).map((_, i) => (
        <Skeleton key={i} className="h-16 w-full rounded-2xl" />
      ))}
    </View>
  );
}

// Text skeleton inline
<Skeleton className="h-4 w-32 rounded-md" />
```

### 5.8 Empty State

```tsx
// Dùng cho: no tables, no zones, no data
<View className="flex-1 items-center justify-center py-20 gap-2">
  <Text variant="muted">{t("tms.table_list")}</Text>
  <Text className="text-xs text-muted-foreground">
    {t("tms.no_tables")}
  </Text>
</View>
```

### 5.9 Error State

```tsx
<View className="flex-1 items-center justify-center py-20 gap-4">
  <Text className="text-destructive text-center">{error}</Text>
  <Button variant="outline" size="sm" onPress={refresh}>
    <Text>{t("tms.retry")}</Text>
  </Button>
</View>
```

### 5.10 Feedback States (Success / Error inline)

```tsx
// Success feedback panel
<View className="flex-row items-center gap-2 bg-success/10 rounded-xl px-3 py-2.5">
  <Text className="text-success font-medium text-sm">
    ✓ {t("settings.test_success")}
  </Text>
</View>

// Error feedback panel
<View className="bg-destructive/10 rounded-xl px-3 py-2.5">
  <Text className="text-destructive font-medium text-sm">
    ✗ {t("settings.test_failed")}
  </Text>
  {errorDetail && (
    <Text className="text-destructive text-xs mt-0.5">{errorDetail}</Text>
  )}
</View>

// Inline field error
<Text className="text-xs text-destructive">{saveError}</Text>
```

### 5.11 Icon Row (Settings item header)

```tsx
// Pattern: Icon trong ô vuông + label + badge
<View className="flex-row items-center gap-3">
  <View className="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center">
    <PrinterIcon />
  </View>
  <View className="flex-1">
    <Text className="font-semibold">{t("settings.printer_label")}</Text>
    <Text className="text-xs text-muted-foreground">{t("settings.printer_model")}</Text>
  </View>
  {/* Status badge */}
  <View className="px-2 py-1 rounded-full bg-success/10">
    <Text className="text-xs font-medium text-success">{t("settings.printer_saved")}</Text>
  </View>
</View>
```

### 5.12 Input Field

```tsx
import { Input } from "@/components/ui/input";

// Standard input với label
<View className="gap-1.5">
  <Text className="text-sm font-medium text-gray-700">
    {t("settings.printer_ip")}
  </Text>
  <Input
    value={value}
    onChangeText={setValue}
    placeholder={t("settings.printer_ip_placeholder")}
    keyboardType="default"
    autoCorrect={false}
    autoCapitalize="none"
  />
  {error && <Text className="text-xs text-destructive">{error}</Text>}
</View>

// Monospace input (IP, code)
<Input className="font-mono" value={value} onChangeText={setValue} />
```

---

## 6. Animation Patterns

> Dùng `react-native-reanimated` cho animation. Không dùng `Animated` API cũ (trừ Skeleton đã có).

### 6.1 Blinking Card (call_staff state)

```tsx
import Animated, {
  cancelAnimation, useAnimatedStyle, useSharedValue,
  withRepeat, withSequence, withTiming,
} from "react-native-reanimated";

// Setup
const opacity = useSharedValue(1);

useEffect(() => {
  if (ds === "call_staff") {
    opacity.value = withRepeat(
      withSequence(
        withTiming(0.3, { duration: 500 }),
        withTiming(1,   { duration: 500 }),
      ),
      -1, // infinite
    );
  } else {
    cancelAnimation(opacity);
    opacity.value = withTiming(1);
  }
}, [ds, opacity]);

const animatedStyle = useAnimatedStyle(() => ({ opacity: opacity.value }));

// Usage — chỉ apply khi call_staff
<Animated.View
  style={ds === "call_staff" ? animatedStyle : undefined}
  className={`rounded-2xl p-3 ${CARD_BG[ds]}`}
>
  ...
</Animated.View>
```

### 6.2 Skeleton Pulse (đã có trong Skeleton component)

```tsx
// Dùng Animated.Value (legacy API — đã có trong skeleton.tsx, không thay đổi)
// Dùng `<Skeleton>` component trực tiếp, không implement lại.
```

---

## 7. Screen Patterns

### 7.1 Screen Layout chuẩn

```tsx
export default function SomeScreen() {
  return (
    <SafeAreaView className="flex-1 bg-gray-50">

      {/* Header cố định */}
      <View className="px-5 pt-4 pb-3 flex-row items-center justify-between bg-white border-b border-gray-100">
        <Text className="text-xl font-bold">{t("screen.title")}</Text>
        {/* Optional: back button, action button */}
      </View>

      {/* Scrollable body */}
      <ScrollView className="flex-1" contentContainerClassName="px-4 py-6 gap-6">
        {/* Sections */}
      </ScrollView>

    </SafeAreaView>
  );
}
```

### 7.2 Screen có keyboard (Settings)

```tsx
import { KeyboardAvoidingView, Platform } from "react-native";

<SafeAreaView className="flex-1 bg-gray-50">
  <KeyboardAvoidingView
    className="flex-1"
    behavior={Platform.OS === "ios" ? "padding" : "height"}
  >
    {/* Header */}
    {/* ScrollView body */}
  </KeyboardAvoidingView>
</SafeAreaView>
```

### 7.3 Screen có back navigation

```tsx
// Header với back button
<View className="px-4 py-3 flex-row items-center gap-3 bg-white border-b border-gray-100">
  <Button variant="ghost" size="icon" onPress={() => router.back()}>
    <ChevronLeftIcon />
  </Button>
  <Text className="text-lg font-bold">{t("settings.title")}</Text>
</View>
```

---

## 8. Navigation Patterns

```tsx
import { router } from "expo-router";

// Chuyển màn hình
router.push("/settings");

// Replace (không thêm vào history)
router.replace("/login");

// Quay lại
router.back();
```

**Route structure:**
```
/           → index.tsx (Auth guard)
/login      → login.tsx
/home       → home.tsx (Table dashboard)
/settings   → settings.tsx (Peripheral config)
```

---

## 9. Data Fetching Patterns

> **Rule 2**: Chỉ dùng TanStack Query. **Rule 3**: Query keys từ `hooks/query-keys.ts`.

### 9.1 useQuery (read data)

```tsx
// hooks/use-something.ts
import { useQuery } from "@tanstack/react-query";
import { queryKeys } from "./query-keys";
import { apiFetch } from "@/lib/api";
import type { SomeType } from "@/types/tms";

export function useSomething() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: queryKeys.something(),
    queryFn: () => apiFetch<SomeType>("/api/v1/tms/something"),
    refetchInterval: 30_000,   // polling 30s
    staleTime: 10_000,
  });

  return {
    data: data ?? [],
    isLoading,
    error: error?.message ?? null,
    refresh: refetch,
  };
}
```

### 9.2 useMutation (write data)

```tsx
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { queryKeys } from "./query-keys";
import { apiFetch } from "@/lib/api";

export function useClearCall() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (tableId: string) =>
      apiFetch(`/api/v1/tms/tables/${tableId}/clear-call`, { method: "POST" }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.tables() });
    },
  });
}
```

### 9.3 Derived data (useMemo bắt buộc — Rule 17)

```tsx
// ✅ ĐÚNG
const displayZones = useMemo(
  () => activeZoneId ? zones.filter(z => z.id === activeZoneId) : zones,
  [activeZoneId, zones],
);

const allTables = useMemo(
  () => displayZones.flatMap(z => z.tables),
  [displayZones],
);

// ❌ SAI — vi phạm Rule 17
const allTables = displayZones.flatMap(z => z.tables); // inline compute
```

### 9.4 Event handlers (useCallback bắt buộc — Rule 18)

```tsx
// ✅ ĐÚNG
const handleClearCall = useCallback(() => {
  clearCall(table.id);
}, [clearCall, table.id]);

// ❌ SAI — tạo closure trong map() render
{tables.map(table => (
  <Pressable onPress={() => clearCall(table.id)} /> // vi phạm Rule 18
))}
```

---

## 10. i18n Patterns

> **Rule 13**: Mọi text qua `t()`. **Rule 14**: Viết `ja.json` trước.

### 10.1 Sử dụng translation

```tsx
// Trong screen/app files
import { useLocale } from "../src/providers/app-provider";
const { t } = useLocale();

// Trong src/ files
import { useTranslation } from "@/providers/app-provider";
const { t } = useTranslation();

// Usage
<Text>{t("tms.title")}</Text>
<Text>{t("common.save")}</Text>
```

### 10.2 Translation key conventions

```
common.save           → 保存 / Save / Lưu
common.logout         → ログアウト / Logout / Đăng xuất
common.cancel         → キャンセル / Cancel / Hủy

tms.title             → テーブル管理 / Table Management / Quản lý bàn
tms.all_zones         → 全エリア / All Areas / Tất cả khu vực
tms.retry             → 再試行 / Retry / Thử lại
tms.seats             → 席数 / Seats / Số ghế

table_status.available   → 空き / Available / Trống
table_status.occupied    → 使用中 / Occupied / Đang dùng
table_status.call_staff  → スタッフ呼出 / Call Staff / Gọi nhân viên
table_status.recently_paid → 最近会計済 / Recently Paid / Vừa thanh toán

settings.title           → 設定 / Settings / Cài đặt
settings.peripherals     → 周辺機器 / Peripherals / Thiết bị ngoại vi
settings.printer_label   → プリンター / Printer / Máy in

action.call_resolved     → 対応済み / Resolved / Đã xử lý
```

---

## 11. TypeScript Patterns

> **Rule 9**: strict mode, no `any`. **Rule 10**: Types trong `src/types/`.

```tsx
// ✅ ĐÚNG — Type từ src/types/tms.ts
import type { Table, TableDisplayState } from "@/types/tms";

// ✅ ĐÚNG — Explicit return type
function getDisplayState(table: Table): TableDisplayState { ... }

// ✅ ĐÚNG — Record type cho style maps
const CARD_BG: Record<TableDisplayState, string> = { ... };

// ❌ SAI — any
const data: any = response;

// ❌ SAI — double cast
const table = data as unknown as Table;

// ❌ SAI — inline interface
const Component = ({ data }: { id: string; name: string }) => ...
// Nên extract: type Props = { id: string; name: string }
```

---

## 12. SVG Icon Pattern

```tsx
import Svg, { Path } from "react-native-svg";

// Icon component pattern (isolated, không nhận props màu ngoài)
function BellIcon() {
  return (
    <Svg width={20} height={20} viewBox="0 0 24 24" fill="white">
      <Path d="..." />
    </Svg>
  );
}

// Icon với stroke (outline style)
function GearIcon() {
  return (
    <Svg width={20} height={20} viewBox="0 0 24 24" fill="none"
      stroke="#6b7280" strokeWidth={1.8}>
      <Path strokeLinecap="round" strokeLinejoin="round" d="..." />
    </Svg>
  );
}

// Đặt trong icon container (settings item header)
<View className="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center">
  <PrinterIcon />
</View>
```

---

## 13. Checklist trước khi code component mới

```
□ Dùng semantic token từ tailwind.config.js (không hardcode hex)
□ Dùng className (không StyleSheet.create, không inline style={{}})
□ Text qua t() (không hardcode string)
□ Translation key thêm vào cả 3 file: ja.json, en.json, vi.json (ja trước)
□ Type định nghĩa trong src/types/ (không inline)
□ Derived data trong useMemo
□ Event handlers trong useCallback
□ API calls qua apiFetch() + useQuery/useMutation
□ Query keys từ query-keys.ts
□ Viết unit test nếu là hook mới
□ Chạy npx tsc --noEmit trước commit
```

---

## 14. Nên thêm vào tailwind.config.js

Những token còn thiếu để đảm bảo Rule 12 (không hardcode trong component):

```js
// Thêm vào theme.extend.colors:

// ── Table display state colors ────────────────────────────────────────────────
"table-free":               "#FFFFFF",
"table-occupied":           "#10B981",
"table-call-staff":         "#D4183D",
"table-recently-paid":      "#DBEAFE",
"table-free-text":          "#0A0A0A",
"table-occupied-text":      "#FFFFFF",
"table-call-staff-text":    "#FFFFFF",
"table-recently-paid-text": "#1E3A8A",
"table-free-sub":           "#717182",
"table-occupied-sub":       "#D1FAE5",
"table-call-staff-sub":     "#FEE2E2",
"table-recently-paid-sub":  "#BFDBFE",
```

---

## 15. Rules (từ CLAUDE.md — bắt buộc tuân theo)

> Các rules dưới đây đã được chốt. **Không thay đổi approach nếu không có explicit approval.**

### Architecture Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 1 | **Provider order cố định** | `SafeAreaProvider → ErrorBoundary → AppProvider → QueryProvider → AuthProvider → Stack`. KHÔNG đổi thứ tự. AuthProvider phải nằm trong QueryProvider vì logout gọi `queryClient.clear()`. |
| 2 | **Data fetching chỉ dùng TanStack React Query** | KHÔNG dùng `useState + useEffect + setInterval` cho API calls. Mọi API fetch phải đi qua `useQuery` / `useMutation`. Polling dùng `refetchInterval`, foreground refetch dùng `focusManager`. |
| 3 | **Query keys khai báo trong `hooks/query-keys.ts`** | KHÔNG hardcode query key string trong hooks. Mọi key dùng factory function từ file này. |
| 4 | **File-based routing chỉ trong `app/`** | Screen components nằm trong `app/`. Business logic, hooks, utils nằm trong `src/`. KHÔNG đặt business logic trong `app/`. |

### API Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 5 | **Mọi API call phải qua `apiFetch()`** | KHÔNG gọi `fetch()` trực tiếp (trừ `pairDevice()` vì cần gửi trước khi có token). `apiFetch` tự inject: Bearer token, `Accept-Language`, timeout. |
| 6 | **`Accept-Language` header bắt buộc** | Mọi request phải gửi locale từ AsyncStorage. Backend dùng header này để trả response đúng ngôn ngữ. Đã implement trong `apiFetch()` — KHÔNG bypass. |
| 7 | **Timeout 15s** | Mọi request có AbortController timeout. KHÔNG tắt timeout. Nếu cần timeout khác, truyền `{ timeout: ms }` vào `apiFetch()`. |
| 8 | **Error classification** | Dùng `ApiError.isAuthError`, `.isValidationError`, `.isServerError`. KHÔNG check `status === 401` trực tiếp — dùng property. |

### Type Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 9 | **TypeScript strict mode** | `tsconfig.json` có `"strict": true`. KHÔNG tắt. KHÔNG dùng `any`. KHÔNG dùng `as unknown as` double cast — nếu type không khớp, fix type hoặc validate data. |
| 10 | **Types trong `src/types/`** | KHÔNG define interface inline trong component. Types chia sẻ phải export từ `src/types/`. |

### Styling Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 11 | **NativeWind (Tailwind) only** | KHÔNG dùng `StyleSheet.create()` hoặc inline `style={{}}` object. Dùng `className` với Tailwind classes. Exception duy nhất: `ErrorBoundary` (render ngoài NativeWind context). |
| 12 | **Color tokens từ `tailwind.config.js`** | KHÔNG hardcode hex colors trong components. Dùng semantic tokens: `bg-primary`, `text-muted-foreground`, `bg-table-available`, etc. |

### i18n Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 13 | **Mọi user-facing text phải qua `t()`** | KHÔNG hardcode text tiếng Nhật/Anh/Việt trong components. Thêm key vào cả 3 file: `ja.json`, `en.json`, `vi.json`. |
| 14 | **Default locale là `ja`** | Khi thêm translation key mới, **viết `ja` trước**, sau đó `en` và `vi`. |

### Security Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 15 | **Token chỉ lưu trong SecureStore** | KHÔNG lưu device token vào AsyncStorage, localStorage, hoặc React state persist. Chỉ `expo-secure-store`. |
| 16 | **`.env` KHÔNG commit** | File `.env` đã trong `.gitignore`. Chỉ commit `.env.example` (template không có giá trị thật). |

### Performance Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 17 | **`useMemo` cho derived data** | Computed values từ API response (filter, flatMap, reduce) phải wrap trong `useMemo`. KHÔNG compute inline trong render. |
| 18 | **KHÔNG tạo closure trong `map()` render** | Dùng `useCallback` cho event handlers hoặc extract thành component riêng. |

### Testing Rules

| # | Rule | Chi tiết |
|---|------|----------|
| 19 | **Mọi hook mới phải có unit test** | Test file đặt cùng folder hoặc trong `src/__tests__/`. Dùng vitest. |
| 20 | **TypeScript check trước commit** | Chạy `npx tsc --noEmit` trước mọi commit. KHÔNG commit code có TS error. |

---

### Quick Reference — Vi phạm thường gặp

```
❌ SAI                                    ✅ ĐÚNG
──────────────────────────────────────    ──────────────────────────────────────
style={{ backgroundColor: "#10B981" }}   className="bg-success"
bg-emerald-500                            bg-table-occupied (sau khi thêm token)
bg-gray-50 (page bg)                      bg-background (#F5EFD0 cream)
fetch("/api/v1/tms/zones")               apiFetch("/api/v1/tms/zones")
queryKey: ["zones"]                       queryKey: queryKeys.zones()
<Text>テーブル管理</Text>                  <Text>{t("tms.title")}</Text>
const data: any = response               const data: Zone[] = response
useState + useEffect + setInterval       useQuery({ refetchInterval: 30_000 })
displayZones.flatMap(z => z.tables)      useMemo(() => displayZones.flatMap(...))
onPress={() => clearCall(table.id)}      useCallback(() => clearCall(table.id), [...])
AsyncStorage.setItem("token", token)     SecureStore.setItemAsync("token", token)
```

---

*Tài liệu này là tham chiếu thiết kế và code cho godx-kiosk TMS App.*
*Cập nhật khi có thêm screen hoặc component mới.*
