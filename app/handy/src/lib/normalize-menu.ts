/**
 * normalize-menu.ts
 *
 * BE serializes decimal(15,2) columns (selling_price, default_price,
 * extra_price) as strings ("3000.00") due to Laravel's `decimal:2` cast.
 * All arithmetic in handy components expects plain numbers, so we coerce
 * at the API boundary — here in the query layer.
 */

import type {
  ShopMenuByDayResource,
  ShopMenuProduct,
  ShopMenuToppingGroup,
  ShopMenuToppingGroupItem,
} from '@/types/pos';

function normalizeToppingItem(item: ShopMenuToppingGroupItem): ShopMenuToppingGroupItem {
  return {
    ...item,
    skus: item.skus?.map((s) => ({
      ...s,
      extra_price: s.extra_price, // stays string — resolveToppingExtraPrice already parseFloat's it
    })),
  };
}

function normalizeToppingGroup(group: ShopMenuToppingGroup): ShopMenuToppingGroup {
  return {
    ...group,
    // Filter items hidden by HQ product-level override before they reach any component.
    items: group.items
      ?.filter((item) => !item.is_hidden)
      .map(normalizeToppingItem),
  };
}

function normalizeSkus(skus: ShopMenuProduct['skus']): ShopMenuProduct['skus'] {
  return skus?.map((s) => ({
    ...s,
    selling_price: parseFloat(s.selling_price as unknown as string) || 0,
    default_price: s.default_price != null
      ? parseFloat(s.default_price as unknown as string) || 0
      : s.default_price,
  }));
}

export function normalizeMenuProduct(mp: ShopMenuProduct): ShopMenuProduct {
  const toppingGroups = mp.product?.topping_groups
    ?.map(normalizeToppingGroup)
    // Drop groups that have no visible items left after is_hidden filtering.
    .filter((g) => !g.items || g.items.length > 0);

  return {
    ...mp,
    skus: normalizeSkus(mp.skus),
    product: mp.product
      ? { ...mp.product, topping_groups: toppingGroups }
      : mp.product,
  };
}

export function normalizeMenuByDay(menus: ShopMenuByDayResource[]): ShopMenuByDayResource[] {
  return menus.map((m) => ({
    ...m,
    menu_products: m.menu_products?.map(normalizeMenuProduct),
  }));
}

export function normalizeMenuProducts(products: ShopMenuProduct[]): ShopMenuProduct[] {
  return products.map(normalizeMenuProduct);
}
