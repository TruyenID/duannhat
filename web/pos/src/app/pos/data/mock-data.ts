/**
 * DEV-only fixtures. After plan-007, only `mockShopMenu` remains — it feeds
 * shop-menu-service's `withMock()` fallback when the backend is unreachable
 * during local dev.
 *
 * Table and order mocks were removed with plan-007 Phase 3 — POS now fetches
 * both from the backend via `useTables(shopSlug)` and `useOrder(orderId)`.
 */

import type { ShopMenuResource } from "../types";

// ---------------------------------------------------------------------------
//  Shop Menu — real ShopMenuResource hierarchy (Menu → MenuProduct → Sku)
// ---------------------------------------------------------------------------

export const mockShopMenu: ShopMenuResource = {
  id: "menu-1",
  name: "Menu chính",
  description: "Thực đơn đang áp dụng cho ca trưa",
  status: "Active",
  menu_products_count: 8,
  menu_products: [
    {
      id: "mp-01",
      menu_id: "menu-1",
      product_id: "p-01",
      menu_section_id: null,
      is_active: true,
      display_order: 1,
      product: {
        id: "p-01",
        name: "Phở bò tái",
        description: "Phở bò truyền thống, nước dùng hầm xương 12h",
      },
      skus: [
        {
          id: "sku-01a",
          menu_product_id: "mp-01",
          product_sku_id: "psk-01a",
          selling_price: 75000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-01a", name: "Tô thường", sku: "PHO-TT" },
        },
        {
          id: "sku-01b",
          menu_product_id: "mp-01",
          product_sku_id: "psk-01b",
          selling_price: 95000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-01b", name: "Tô đặc biệt", sku: "PHO-DB" },
        },
      ],
    },
    {
      id: "mp-02",
      menu_id: "menu-1",
      product_id: "p-02",
      menu_section_id: null,
      is_active: true,
      display_order: 2,
      product: { id: "p-02", name: "Bún chả", description: "Bún chả Hà Nội" },
      skus: [
        {
          id: "sku-02",
          menu_product_id: "mp-02",
          product_sku_id: "psk-02",
          selling_price: 80000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-02", name: "Suất thường", sku: "BC-TT" },
        },
      ],
    },
    {
      id: "mp-03",
      menu_id: "menu-1",
      product_id: "p-03",
      menu_section_id: null,
      is_active: true,
      display_order: 3,
      product: { id: "p-03", name: "Nem rán", description: "Nem rán giòn tan" },
      skus: [
        {
          id: "sku-03",
          menu_product_id: "mp-03",
          product_sku_id: "psk-03",
          selling_price: 65000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-03", name: "Đĩa 6 cái", sku: "NEM-6" },
        },
      ],
    },
    {
      id: "mp-04",
      menu_id: "menu-1",
      product_id: "p-04",
      menu_section_id: null,
      is_active: true,
      display_order: 4,
      product: {
        id: "p-04",
        name: "Lẩu thái hải sản",
        description: "Lẩu chua cay, nước dùng tom yum",
      },
      skus: [
        {
          id: "sku-04a",
          menu_product_id: "mp-04",
          product_sku_id: "psk-04a",
          selling_price: 320000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-04a", name: "Nồi nhỏ (2 người)", sku: "LAU-S" },
        },
        {
          id: "sku-04b",
          menu_product_id: "mp-04",
          product_sku_id: "psk-04b",
          selling_price: 420000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-04b", name: "Nồi vừa (3-4 người)", sku: "LAU-M" },
        },
        {
          id: "sku-04c",
          menu_product_id: "mp-04",
          product_sku_id: "psk-04c",
          selling_price: 680000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-04c", name: "Nồi lớn (5-6 người)", sku: "LAU-L" },
        },
      ],
    },
    {
      id: "mp-05",
      menu_id: "menu-1",
      product_id: "p-05",
      menu_section_id: null,
      is_active: true,
      display_order: 5,
      product: {
        id: "p-05",
        name: "Cơm gà xối mỡ",
        description: "Cơm gà giòn, sốt đặc biệt",
      },
      skus: [
        {
          id: "sku-05",
          menu_product_id: "mp-05",
          product_sku_id: "psk-05",
          selling_price: 95000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-05", name: "Suất chuẩn", sku: "CGXM-S" },
        },
      ],
    },
    {
      id: "mp-06",
      menu_id: "menu-1",
      product_id: "p-06",
      menu_section_id: null,
      is_active: true,
      display_order: 6,
      product: {
        id: "p-06",
        name: "Trà đào cam sả",
        description: "Trà đá mát, hương đào tự nhiên",
      },
      skus: [
        {
          id: "sku-06a",
          menu_product_id: "mp-06",
          product_sku_id: "psk-06a",
          selling_price: 35000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-06a", name: "Size M", sku: "TDCS-M" },
        },
        {
          id: "sku-06b",
          menu_product_id: "mp-06",
          product_sku_id: "psk-06b",
          selling_price: 45000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-06b", name: "Size L", sku: "TDCS-L" },
        },
      ],
    },
    {
      id: "mp-07",
      menu_id: "menu-1",
      product_id: "p-07",
      menu_section_id: null,
      is_active: true,
      display_order: 7,
      product: { id: "p-07", name: "Coca 330ml", description: "Nước ngọt lon" },
      skus: [
        {
          id: "sku-07",
          menu_product_id: "mp-07",
          product_sku_id: "psk-07",
          selling_price: 20000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-07", name: "Lon 330ml", sku: "COC-330" },
        },
      ],
    },
    {
      id: "mp-08",
      menu_id: "menu-1",
      product_id: "p-08",
      menu_section_id: null,
      is_active: true,
      display_order: 8,
      product: { id: "p-08", name: "Bia Saigon", description: "Bia lon lạnh" },
      skus: [
        {
          id: "sku-08",
          menu_product_id: "mp-08",
          product_sku_id: "psk-08",
          selling_price: 25000,
          is_price_overridden: false,
          is_active: true,
          product_sku: { id: "psk-08", name: "Lon", sku: "BSG-LON" },
        },
      ],
    },
  ],
};
