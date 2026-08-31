"use client";

import { useState, useEffect } from "react";
import { useTranslations } from 'next-intl';
import { Search, ArrowLeft } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import type { MenuItem } from "@/data/menu";
import { MenuListItem } from "./menu-list-item";

// Diacritic-insensitive normalize so "banh" matches "Bánh" and "b" doesn't drag
// in unrelated items via their description. Vietnamese đ/Đ → d.
function normalize(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/đ/g, "d");
}

interface SearchPageProps {
  allItems: MenuItem[];
  onClose: () => void;
  onItemClick: (item: MenuItem) => void;
}

export function SearchPage({ allItems, onClose, onItemClick }: SearchPageProps) {
  const t = useTranslations('menu');
  const [searchQuery, setSearchQuery] = useState("");
  const [searchResults, setSearchResults] = useState<MenuItem[]>([]);

  // Filter items based on search query
  useEffect(() => {
    if (!searchQuery.trim()) {
      setSearchResults([]);
      return;
    }

    // Match by món NAME only (diacritic-insensitive). Searching the description
    // too made "b" surface unrelated items like "Cơm Tấm" / "100% sugar" whose
    // description happened to contain a "b".
    const query = normalize(searchQuery.trim());
    const results = allItems.filter((item) => normalize(item.name).includes(query));
    setSearchResults(results);
  }, [searchQuery, allItems]);

  const hasSearched = searchQuery.trim().length > 0;
  const hasResults = searchResults.length > 0;

  return (
    <div className="fixed inset-0 z-50 bg-white flex flex-col">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-white border-b">
        <div className="flex items-center gap-3 px-4 py-3">
          <button
            onClick={onClose}
            className="shrink-0 p-2 -ml-2"
            aria-label={t('back')}
          >
            <ArrowLeft className="h-5 w-5 text-neutral-900" />
          </button>

          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
            <Input
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder={t('searchPlaceholder')}
              // iOS Safari auto-zooms into any input whose computed
              // font-size < 16px. text-base = 16px on mobile keeps the
              // viewport pinned when the user taps the field; md:text-sm
              // restores the smaller desktop look where zoom isn't a
              // concern.
              className="h-10 pl-9 pr-3 text-base md:text-sm rounded-full border-neutral-300 focus-visible:border-neutral-300 focus-visible:ring-0"
              autoFocus
            />
          </div>
        </div>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-y-auto">
        {!hasSearched ? (
          // Empty state - no search yet
          <div className="flex flex-col items-center justify-center py-20 px-6 text-center">
            <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-neutral-100">
              <Search className="h-10 w-10 text-neutral-400" />
            </div>
            <p className="text-sm text-neutral-600">
              {t('searchPrompt')}
            </p>
          </div>
        ) : hasResults ? (
          // Search results
          <div className="px-4 py-4">
            {/* Query title */}
            <h2 className="mb-4 text-base font-semibold text-neutral-900">
              {searchQuery}
            </h2>

            {/* 2-col on mobile (matches main menu MenuListItem layout) →
                1-col list on tablet+ so MenuListItem can render its
                horizontal desktop variant. Without `min-w-0` the long item
                names + ¥ prices push the grid track wider than the column
                slot on iOS Safari, shifting the card off-screen — caused
                the "lệch món" bug reported 2026-06-15. */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-1 md:gap-4">
              {searchResults.map((item) => (
                <div key={item.id} className="min-w-0">
                  <MenuListItem
                    item={item}
                    onClick={() => onItemClick(item)}
                  />
                </div>
              ))}
            </div>
          </div>
        ) : (
          // No results
          <div className="flex flex-col items-center justify-center py-20 px-6 text-center">
            <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-neutral-100">
              <Search className="h-10 w-10 text-neutral-400" />
            </div>
            <h3 className="mb-2 text-base font-semibold text-neutral-900">
              {t('noMatchTitle')}
            </h3>
            <p className="mb-6 text-sm text-neutral-600">
              {t('tryAnotherKeyword')}
            </p>
            <Button
              onClick={onClose}
              variant="outline"
              className="h-10 px-6"
            >
              {t('backToStore')}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
