import type { MenuItem } from "@/data/menu";

/**
 * #1715 — giữ giá của một dòng giỏ khớp với menu đang phát.
 *
 * Giỏ chụp giá lúc khách bấm card rồi giữ nguyên, còn backend **luôn định giá lại**
 * lúc tạo đơn. Khung giờ ưu đãi đóng lúc 20:00 mà món vào giỏ từ 19:59 thì giỏ vẫn
 * hiện ¥800 trong khi đơn ra ¥1,100 — khách thấy một giá, bị tính một giá khác.
 *
 * Module này **thuần**, không phụ thuộc React, nên chạy được bằng harness sẵn có
 * (`node --test 'lib/**'`). Đó cũng là lý do nó không import gì từ `components/`:
 * `applyPromotionPercent` nguyên bản sống trong `components/happy-hour.tsx`, một
 * client component kéo theo react/next-intl/lucide — `node --test` không có
 * loader alias lẫn JSX nên chỉ cần chạm vào là chết.
 *
 * ## Hai nguồn giảm giá, hai đường vào giá
 *
 * - **Khung giờ ưu đãi** (`active_floating_section`): backend đã hạ thẳng
 *   `product.price`, client không tính gì thêm.
 * - **Happy Hour** (`active_promotion`): backend gửi phần trăm, client áp lên giá.
 *
 * Bất cứ phép so giá nào cũng phải đi qua đúng cả hai, nếu không một trong hai
 * loại khuyến mãi sẽ vô hình.
 */

/** Cộng phần chênh của các option/variant đã chọn vào giá gốc của món. */
export function optionAdjustedPrice(
  product: Pick<MenuItem, "price" | "options">,
  selections: Record<string, string[]>,
): number {
  let extra = 0;
  for (const opt of product.options ?? []) {
    const selected = selections[opt.id];
    if (!selected) continue;
    for (const variant of opt.variants) {
      if (selected.includes(variant.id)) {
        extra += variant.price;
      }
    }
  }
  return product.price + extra;
}

/** Áp phần trăm Happy Hour lên một giá bất kỳ (đã tính option). */
export function applyPromotionPercent(
  unitPrice: number,
  promo: MenuItem["active_promotion"],
): number {
  if (!promo) return unitPrice;
  return Math.round((unitPrice * (100 - promo.discount_percent)) / 100);
}

/**
 * Giá MỘT ĐƠN VỊ của món (đã option, đã khuyến mãi), CHƯA cộng topping.
 *
 * Đây là "mốc" (basis) mà `computePriceSync` so hai đầu. Quan trọng: cả hai đầu
 * phải đi qua **cùng** hàm này, để quy ước làm tròn triệt tiêu lẫn nhau trong
 * phép trừ. (Hai đường thêm món hiện có làm tròn hơi khác nhau — product-modal
 * áp phần trăm rồi `Math.round`, quick-add dine-in lấy thẳng
 * `discounted_price` do backend làm tròn 2 chữ số. Chênh lệch đó nằm sẵn trong
 * `unitPrice` đã lưu; dùng một hàm cho cả hai đầu nghĩa là ta không làm nó tệ
 * thêm, cũng không lặng lẽ "sửa" nó thành một con số thứ ba.)
 */
export function unitPriceBasis(
  product: Pick<MenuItem, "price" | "options" | "active_promotion">,
  selections: Record<string, string[]>,
): number {
  return applyPromotionPercent(optionAdjustedPrice(product, selections), product.active_promotion);
}

/**
 * - `unchanged`    — giá y nguyên, đừng đụng vào dòng giỏ.
 * - `adjusted`     — cập nhật `unitPrice` (cả hai chiều) rồi báo cho khách.
 * - `unresolvable` — không so được, phải chặn đặt thay vì đoán. Xem lý do dưới.
 */
export type PriceSyncStatus = "unchanged" | "adjusted" | "unresolvable";

export interface PriceSyncResult {
  status: PriceSyncStatus;
  /** Chỉ có nghĩa khi `adjusted`. */
  unitPrice: number;
  /** Chênh lệch của phần base; `adjusted` ⇔ khác 0. */
  delta: number;
}

export interface PriceSyncInput {
  /** Bản chụp lúc thêm vào giỏ. */
  product: Pick<MenuItem, "price" | "options" | "active_promotion">;
  /** Bản đang phát trong menu, lấy từ `findMenuItem`. */
  fresh: Pick<MenuItem, "price" | "options" | "active_promotion">;
  selections: Record<string, string[]>;
  /** Giá dòng đang lưu — đã gồm topping ở giá đầy đủ. */
  unitPrice: number;
}

/**
 * So giá dòng giỏ với bản đang phát, trả về giá mới.
 *
 * **Cộng delta chứ không gán thẳng** để giữ nguyên phần topping đã chốt: reducer
 * không có bộ giải topping (nó cần dữ liệu chỉ có ở tầng component), nên tính lại
 * cả dòng là không làm được. Delta của phần base thì đủ và không đụng tới topping.
 *
 * **Chiều giảm cũng cập nhật.** Backend đã tự hạ giá khi một khuyến mãi bắt đầu
 * giữa lúc món nằm trong giỏ, nên không hạ ở giỏ nghĩa là để khách trả ít hơn mà
 * không biết mình được giảm.
 *
 * **Từ chối khi một variant đã chọn không còn trong bản mới.** `optionAdjustedPrice`
 * **im lặng** bỏ qua variant không tìm thấy, mà backend thì bỏ hẳn option value
 * không còn SKU active. Ghép hai điều đó lại: shop tắt variant "Lớn" ⇒ mốc mới
 * mất phần chênh của size ⇒ giỏ **hạ giá** đúng lúc backend sẽ tính **cao hơn**
 * (SKU rơi khỏi mọi menu thì tính theo giá SKU). Không so được thì chặn, đừng đoán.
 */
export function computePriceSync(input: PriceSyncInput): PriceSyncResult {
  const { product, fresh, selections, unitPrice } = input;

  if (!selectedVariantsStillExist(fresh, selections)) {
    return { status: "unresolvable", unitPrice, delta: 0 };
  }

  const delta = unitPriceBasis(fresh, selections) - unitPriceBasis(product, selections);

  if (delta === 0) {
    return { status: "unchanged", unitPrice, delta: 0 };
  }

  return { status: "adjusted", unitPrice: unitPrice + delta, delta };
}

function selectedVariantsStillExist(
  fresh: Pick<MenuItem, "options">,
  selections: Record<string, string[]>,
): boolean {
  const available = new Set<string>();
  for (const opt of fresh.options ?? []) {
    for (const variant of opt.variants) {
      available.add(variant.id);
    }
  }

  for (const ids of Object.values(selections)) {
    for (const id of ids) {
      if (!available.has(id)) return false;
    }
  }

  return true;
}
