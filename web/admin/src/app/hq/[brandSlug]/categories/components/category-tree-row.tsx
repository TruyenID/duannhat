"use client";

import { ChevronDown, ChevronRight, Folder } from "lucide-react";
import Link from "next/link";
import type { Category } from "@/services/category-service";
import { useTranslation } from "@/providers/app-provider";

interface CategoryTreeRowProps {
  category: Category;
  depth: number;
  hasChildren: boolean;
  isExpanded: boolean;
  detailHref: string;
  onToggle: (id: string) => void;
}

/**
 * Cell renderer for the Name column of the categories DataTable.
 *
 * Indents by depth (16px per level), shows a chevron when the category has
 * children, and renders the name as a real Next `<Link>` to the detail
 * page (per debug-001 — clicking the name used to open the edit drawer).
 */
export function CategoryTreeRow({
  category,
  depth,
  hasChildren,
  isExpanded,
  detailHref,
  onToggle,
}: CategoryTreeRowProps) {
  const { t } = useTranslation();
  return (
    <div className="flex items-center gap-1.5" style={{ paddingLeft: `${depth * 16}px` }}>
      {hasChildren ? (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onToggle(category.id);
          }}
          className="inline-flex size-4 items-center justify-center rounded text-muted-foreground hover:bg-accent"
          aria-label={
            isExpanded ? t("hq.categories.tree.collapse") : t("hq.categories.tree.expand")
          }
        >
          {isExpanded ? (
            <ChevronDown className="size-3.5" />
          ) : (
            <ChevronRight className="size-3.5" />
          )}
        </button>
      ) : (
        <span className="inline-block size-4" />
      )}
      <Folder className="size-3.5 text-muted-foreground" />
      <Link href={detailHref} className="font-medium text-primary hover:underline">
        {category.name}
      </Link>
    </div>
  );
}
