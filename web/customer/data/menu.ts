export interface ProductVariant {
  id: string;
  /** ProductSku.id that matches this variant — used to resolve order sku_id */
  sku_id?: string;
  name: string;
  /** Price delta relative to item.price (not the full SKU price) */
  price: number;
  image?: string;
  default?: boolean;
}

export interface ProductOption {
  id: string;
  name: string;
  type: "single" | "multiple";
  required: boolean;
  max?: number;
  variants: ProductVariant[];
}

export interface ToppingItem {
  id: string; // topping_group_item_id from backend
  name: string;
  price?: number; // Optional — when topping has variants, price lives on each variant
  image?: string;
  default?: boolean;
  /**
   * Default product SKU ID for this topping (when no variants).
   * When topping has variants, each variant carries its own sku_id.
   */
  sku_id?: string;
  /**
   * If this topping item is itself a product with variants (e.g., "Phở Bò" in a combo),
   * the API returns an array of variants directly on the item (not nested in options[].variants).
   * Example: { name: "Beef Pho", variants: [{name: "Regular", price: 0, sku_id: "..."}] }
   */
  variants?: ProductVariant[];
}

export interface ToppingGroup {
  id: string;
  name: string;
  min_select: number;
  max_select: number | null;
  max_qty_per_item: number;
  items: ToppingItem[];
}

export type MenuItemStatus = "available" | "sold_out" | "limited";

export interface ComboItem {
  name: string;
  image?: string | null;
}

export interface MenuItem {
  id: string;
  sku_id?: string; // product_sku_id from backend API, used for order submission
  name: string;
  price: number;
  image: string | null;
  /** Full ordered gallery of product photos from the backend menu API.
   *  `image` remains the first thumbnail for backwards compatibility. */
  images?: string[];
  description?: string | null;
  status?: MenuItemStatus; // mặc định "available" nếu không set
  options?: ProductOption[];
  toppingGroups?: ToppingGroup[];
  /** Whether this product is a combo (product_type.code = 'combo') */
  is_combo?: boolean;
  /** plan-043 — resolved consumption-tax type id (MenuProduct override →
   *  Product). null/undefined = inherit the branch/brand default. */
  tax_type_id?: string | null;
  /** #1099 — effective rate of the resolved tax type (MenuProduct override →
   *  Product). ONE number; consumption context is a menu concern. null =
   *  inherit → use branch default_tax_type.rate. */
  tax_rate?: number | null;
  /** Flat list of items included in the combo (derived from topping groups) */
  comboItems?: ComboItem[];
  /** Client-side enrichment — set from parent MenuCategory.name at fetch time
   *  so the product detail modal can render the category badge without
   *  having to thread the category through every click handler. */
  categoryName?: string;
  /** Optional rating percentage (e.g., 94 for 94%) */
  rating?: number;
  /** Optional review count */
  reviewCount?: number;
  /**
   * plan-019 — Happy Hour promotion overlay surfaced by the backend menu API
   * (`MenuPromotionService::resolveActivePromotionsForMenu`). Present only
   * during an active window; null/undefined otherwise.
   */
  active_promotion?: {
    id: string;
    discount_percent: number;
    discounted_price: number;
    ends_at: string;
    stacking_mode: "exclusive_with_coupons" | "stackable_with_coupons";
  } | null;
  /**
   * #1185 — the pre-discount price, sent only when an active Floating Section
   * ("khung giờ ưu đãi") lowered `price`. Unlike `active_promotion`, the
   * backend has already applied the discount to `price`, so this is what the
   * strikethrough shows.
   */
  base_price?: number | null;
  /** #1185 — which Floating Section produced the discount on `price`. */
  active_floating_section?: {
    floating_section_id: string;
    name: string;
    price: number;
    priority?: number;
  } | null;
  /** #1185 — per-item menu context. With multiple menus merged into one view,
   *  each item carries the id/name/end-time/deadline of the menu it came from
   *  so cart enrichment (#478) stamps the RIGHT menu, not a global one. */
  menu_id?: string;
  menu_name?: string;
  menu_end_time?: string | null;
  item_deadline?: string | null;
}

export interface MenuCategory {
  id: string;
  name: string;
  items: MenuItem[];
  /** #1185 — true when this section IS a Floating Section, not a menu section.
   *  Those lead the view and carry the promo styling. */
  is_floating_section?: boolean;
  /** #1185 — Floating Section display order; smaller wins. Absent on menu sections. */
  priority?: number;
  /** #1187 — shop-controlled flag driving the featured carousel. Replaces the
   *  old heuristic that scanned `name` for hard-coded words and emoji. */
  is_featured?: boolean;
}

// --- Shared option templates ---

const banhMiOptions: ProductOption[] = [
  {
    id: "size",
    name: "Kích thước",
    type: "single",
    required: true,
    variants: [
      { id: "thuong", name: "Thường", price: 0, default: true },
      { id: "lon", name: "Lớn", price: 200 },
    ],
  },
  {
    id: "extra",
    name: "Thêm",
    type: "multiple",
    required: false,
    max: 3,
    variants: [
      { id: "trung", name: "Thêm trứng", price: 100 },
      { id: "pho-mai", name: "Phô mai", price: 150 },
      { id: "cha", name: "Thêm chả", price: 200 },
    ],
  },
];

const bunPhoOptions: ProductOption[] = [
  {
    id: "size",
    name: "Size",
    type: "single",
    required: true,
    variants: [
      { id: "vua", name: "Vừa", price: 0, default: true },
      { id: "lon", name: "Lớn", price: 200 },
    ],
  },
  {
    id: "topping",
    name: "Topping thêm",
    type: "multiple",
    required: false,
    max: 3,
    variants: [
      { id: "them-thit", name: "Thêm thịt", price: 300 },
      { id: "trung-long", name: "Trứng lòng đào", price: 150 },
      { id: "rau", name: "Rau thêm", price: 50 },
    ],
  },
  {
    id: "ngo-tay",
    name: "Ngò tây",
    type: "single",
    required: false,
    variants: [
      { id: "co", name: "Có rau mùi", price: 0, default: true },
      { id: "khong", name: "Không có rau mùi", price: 0 },
    ],
  },
  {
    id: "nuoc-xot",
    name: "Nước xốt",
    type: "multiple",
    required: false,
    variants: [
      { id: "tuong-ot", name: "Tương ớt", price: 50 },
      { id: "giam-toi", name: "Giấm tỏi", price: 50 },
    ],
  },
];

const comOptions: ProductOption[] = [
  {
    id: "com-them",
    name: "Tùy chọn thêm",
    type: "multiple",
    required: false,
    max: 3,
    variants: [
      { id: "them-trung", name: "Thêm trứng", price: 100 },
      { id: "them-suon", name: "Thêm sườn", price: 300 },
      { id: "them-bi", name: "Thêm bì", price: 150 },
    ],
  },
];

// --- Topping Groups ---

const bunPhoTopping: ToppingGroup[] = [
  {
    id: "019e0033-7779-710b-8df5-75351585a320", // group ID from error message
    name: "Size",
    min_select: 1,
    max_select: 1,
    max_qty_per_item: 1,
    items: [
      { id: "thuong", name: "Thường", price: 0, default: true, sku_id: "01900000-0000-0000-0000-000000000001" },
      { id: "lon", name: "Lớn", price: 200, sku_id: "01900000-0000-0000-0000-000000000002" },
    ],
  },
  {
    id: "topping-bun-pho",
    name: "Topping thêm",
    min_select: 0,
    max_select: null,
    max_qty_per_item: 3,
    items: [
      { id: "them-thit", name: "Thêm thịt", price: 300, sku_id: "01900000-0000-0000-0000-000000000003" },
      { id: "trung-long", name: "Trứng lòng đào", price: 150, sku_id: "01900000-0000-0000-0000-000000000004" },
      { id: "rau-them", name: "Rau thêm", price: 50, sku_id: "01900000-0000-0000-0000-000000000005" },
      { id: "hanh-phi", name: "Hành phi", price: 30, sku_id: "01900000-0000-0000-0000-000000000006" },
    ],
  },
];

const cafeTopping: ToppingGroup[] = [
  {
    id: "nhiet-do",
    name: "Nhiệt độ",
    min_select: 1,
    max_select: 1,
    max_qty_per_item: 1,
    items: [
      { id: "nong", name: "Nóng", price: 0, image: "/images/hot-drink.jpg", default: true, sku_id: "01900000-0000-0000-0000-000000000010" },
      { id: "da", name: "Đá", price: 100, image: "/images/iced-drink.jpg", sku_id: "01900000-0000-0000-0000-000000000011" },
    ],
  },
  {
    id: "do-ngot",
    name: "Độ ngọt",
    min_select: 0,
    max_select: 1,
    max_qty_per_item: 1,
    items: [
      { id: "ngot-binh-thuong", name: "Ngọt bình thường", price: 0, default: true, sku_id: "01900000-0000-0000-0000-000000000012" },
      { id: "it-ngot", name: "Ít ngọt", price: 0, sku_id: "01900000-0000-0000-0000-000000000013" },
      { id: "khong-ngot", name: "Không ngọt", price: 0, sku_id: "01900000-0000-0000-0000-000000000014" },
    ],
  },
  {
    id: "topping-them",
    name: "Topping thêm",
    min_select: 0,
    max_select: 3,
    max_qty_per_item: 2,
    items: [
      { id: "tran-chau", name: "Trân châu", price: 50, image: "/images/topping-boba.jpg" },
      { id: "thach-dua", name: "Thạch dừa", price: 50, image: "/images/topping-jelly.jpg" },
      { id: "kem-sua", name: "Kem sữa", price: 100, image: "/images/topping-cream.jpg" },
    ],
  },
];

// --- Categories ---

export const categories: MenuCategory[] = [
  {
    id: "ruou-bia",
    name: "Rượu Bia",
    items: [
      { id: "rb-1", name: "Bia Sài Gòn", price: 500, image: "/images/bia-saigon.jpg" },
      { id: "rb-2", name: "Bia Tiger", price: 550, image: "/images/bia-tiger.jpg" },
      { id: "rb-3", name: "Rượu Sake", price: 800, image: "/images/ruou-sake.jpg", status: "limited" },
      { id: "rb-4", name: "Rượu vang đỏ", price: 1200, image: "/images/ruou-vang.jpg", status: "sold_out" },
    ],
  },
  {
    id: "banh-mi",
    name: "Bánh Mì",
    items: [
      { id: "bm-1", name: "Bánh mì thịt viên", price: 900, image: "/images/banh-mi-thit-vien.jpg", options: banhMiOptions },
      { id: "bm-2", name: "Bánh mì tôm và bơ", price: 900, image: "/images/banh-mi-tom-bo.jpg", options: banhMiOptions },
      { id: "bm-3", name: "Bánh mì xá xíu", price: 900, image: "/images/banh-mi-xa-xiu.jpg", options: banhMiOptions },
      { id: "bm-4", name: "Bánh mì đặc biệt", price: 950, image: "/images/banh-mi-dac-biet.jpg", options: banhMiOptions },
      { id: "bm-5", name: "Bánh mì thịt nướng", price: 850, image: "/images/banh-mi-thit-nuong.jpg", options: banhMiOptions, status: "sold_out" },
      { id: "bm-6", name: "Bánh mì thịt nướng BBQ", price: 850, image: "/images/banh-mi-bbq.jpg", options: banhMiOptions, status: "limited" },
      { id: "bm-7", name: "Bánh mì gà và lá chanh", price: 850, image: "/images/banh-mi-ga.jpg", options: banhMiOptions },
    ],
  },
  {
    id: "mon-nho-nem",
    name: "Các Món Ăn Nhỏ Và Nem Cuốn",
    items: [
      { id: "mn-1", name: "Nem rán", price: 700, image: "/images/nem-ran.jpg" },
      { id: "mn-2", name: "Gỏi cuốn tôm thịt", price: 750, image: "/images/goi-cuon.jpg" },
      { id: "mn-3", name: "Chả giò rế", price: 650, image: "/images/cha-gio.jpg" },
      { id: "mn-4", name: "Bánh cuốn", price: 800, image: "/images/banh-cuon.jpg" },
    ],
  },
  {
    id: "hang-chinh-hang",
    name: "Hàng Chính Hãng",
    items: [
      { id: "hc-1", name: "Cơm tấm sườn bì", price: 1100, image: "/images/com-tam.jpg", options: comOptions },
      { id: "hc-2", name: "Cơm gà xối mỡ", price: 1000, image: "/images/com-ga.jpg", options: comOptions },
      { id: "hc-3", name: "Phở bò đặc biệt", price: 1200, image: "/images/pho-bo.jpg", options: bunPhoOptions, status: "limited" },
      { id: "hc-4", name: "Hủ tiếu Nam Vang", price: 1050, image: "/images/hu-tieu.jpg", options: bunPhoOptions },
    ],
  },
  {
    id: "bun",
    name: "Bún",
    items: [
      { id: "bn-1", name: "Bún bò Huế", price: 1100, image: "/images/bun-bo-hue.jpg", options: bunPhoOptions, toppingGroups: bunPhoTopping },
      { id: "bn-2", name: "Bún chả Hà Nội", price: 1000, image: "/images/bun-cha.jpg", options: bunPhoOptions, toppingGroups: bunPhoTopping },
      { id: "bn-3", name: "Bún riêu cua", price: 1050, image: "/images/bun-rieu.jpg", options: bunPhoOptions, toppingGroups: bunPhoTopping },
      { id: "bn-4", name: "Bún thịt nướng", price: 950, image: "/images/bun-thit-nuong.jpg", options: bunPhoOptions, toppingGroups: bunPhoTopping },
    ],
  },
  {
    id: "trang-mieng",
    name: "Món Tráng Miệng Và Đồ Uống",
    items: [
      { id: "tm-1", name: "Chè ba màu", price: 500, image: "/images/che-ba-mau.jpg" },
      { id: "tm-2", name: "Bánh flan", price: 450, image: "/images/banh-flan.jpg" },
      { id: "tm-3", name: "Trà đá", price: 200, image: "/images/tra-da.jpg" },
      {
        id: "tm-4",
        name: "Cà phê sữa đá",
        price: 500,
        image: "/images/ca-phe.jpg",
        options: [
          {
            id: "type",
            name: "Loại",
            type: "single",
            required: true,
            variants: [
              { id: "da", name: "Đá", price: 0, default: true },
              { id: "nong", name: "Nóng", price: 0 },
            ],
          },
          {
            id: "sua",
            name: "Sữa",
            type: "single",
            required: false,
            variants: [
              { id: "it", name: "Ít sữa", price: 0, default: true },
              { id: "nhieu", name: "Nhiều sữa", price: 50 },
            ],
          },
        ],
        toppingGroups: cafeTopping,
      },
    ],
  },
];
