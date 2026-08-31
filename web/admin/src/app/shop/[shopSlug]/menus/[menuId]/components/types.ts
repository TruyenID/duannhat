import type { ShopMenuProduct } from "@/types/shop";

export type ViewMode = "list" | "grid";

export interface SectionGroup {
  id: string;
  name: string;
  products: ShopMenuProduct[];
}
