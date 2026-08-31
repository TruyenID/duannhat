import type { MenuCategory, MenuItem } from "@/data/menu";
import MenuCard from "./menu-card";
import { PromoSectionChip } from "./promo-section-chip";

interface MenuGridProps {
  categories: MenuCategory[];
  onItemClick: (item: MenuItem) => void;
  /** Khi có getQty/onAdd/onRemove → mode đặt nhanh bằng nút + */
  getQty?: (id: string) => number;
  onAdd?: (item: MenuItem) => void;
  onRemove?: (item: MenuItem) => void;
}

export default function MenuGrid({ categories, onItemClick, getQty, onAdd, onRemove }: MenuGridProps) {
  const quickMode = !!onAdd;

  return (
    <div className="space-y-10">
      {categories.map((category) => (
        <section key={category.id} id={category.id} className="anim-enter">
          {/* #1185 — a Floating Section is a promo window, not a menu section:
              mark it so a customer can tell the two apart at a glance.
              Merge note: the other branch used a flame emoji here; emoji are
              banned in this UI, so the translated chip stays. */}
          <h2 className="mb-6 flex items-center gap-2 text-2xl font-bold">
            {category.name}
            {category.is_floating_section && <PromoSectionChip />}
          </h2>
          <div className="grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-3 xl:grid-cols-4 anim-stagger">
            {category.items.map((item) => (
              <MenuCard
                key={item.id}
                item={item}
                onClick={() => onItemClick(item)}
                qty={quickMode ? (getQty?.(item.id) ?? 0) : undefined}
                onAdd={quickMode ? () => onAdd(item) : undefined}
                onRemove={quickMode ? () => onRemove?.(item) : undefined}
              />
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
