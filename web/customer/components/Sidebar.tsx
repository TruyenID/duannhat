"use client";

import type { MenuCategory } from "@/data/menu";

interface SidebarProps {
  categories: MenuCategory[];
  activeId: string;
  onSelect: (id: string) => void;
}

export default function Sidebar({ categories, activeId, onSelect }: SidebarProps) {
  return (
    <nav className="flex flex-col gap-0.5 p-3">
      {categories.map((category) => {
        const isActive = activeId === category.id;
        return (
          <button
            key={category.id}
            onClick={() => onSelect(category.id)}
            className={`w-full text-left text-sm rounded-lg px-3 py-2 transition-colors leading-snug ${
              isActive
                ? "bg-primary/10 text-primary font-semibold"
                : "text-muted-foreground hover:bg-muted hover:text-foreground"
            }`}
          >
            {isActive && (
              <span className="mr-2 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" />
            )}
            {category.name}
          </button>
        );
      })}
    </nav>
  );
}
