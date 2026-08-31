"use client";

import { useMemo, useState, useCallback } from "react";
import { useTranslations } from 'next-intl';
import type { Brand, Branch } from "@/data/brands";
import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { AlertCircle, Check, ChevronLeft, ChevronRight, Loader2, Search, X } from "lucide-react";

/** Normalize Vietnamese text for search (remove diacritics + lowercase) */
function normalize(text: string): string {
  return text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d")
    .replace(/Đ/g, "d");
}

export default function BrandSwitcherSheet() {
  const {
    isSwitcherOpen,
    closeSwitcher,
    switchBranch,
    currentBranch,
    branches,
    isLoadingBranches,
    branchesError,
    refetchBranches,
  } = useBrand();
  const { totalItems, clearCart } = useCart();
  const t = useTranslations('switcher');
  const tc = useTranslations('common');
  const [pendingSlug, setPendingSlug] = useState<string | null>(null);
  const [selectedBrandId, setSelectedBrandId] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  // Group branches by brand
  const brandGroups = useMemo(() => {
    const map = new Map<string, { brand: Brand; branches: Branch[] }>();
    for (const branch of branches) {
      const existing = map.get(branch.brand.id);
      if (existing) {
        existing.branches.push(branch);
      } else {
        map.set(branch.brand.id, {
          brand: branch.brand,
          branches: [branch],
        });
      }
    }
    return Array.from(map.values());
  }, [branches]);

  const selectedGroup = brandGroups.find(
    (g) => g.brand.id === selectedBrandId,
  );

  const normalizedSearch = normalize(search.trim());
  const isSearching = normalizedSearch.length > 0;

  const filteredBrandGroups = useMemo(() => {
    if (!isSearching) return brandGroups;
    return brandGroups.filter(
      (g) =>
        normalize(g.brand.name).includes(normalizedSearch) ||
        normalize(g.brand.description ?? "").includes(normalizedSearch),
    );
  }, [brandGroups, normalizedSearch, isSearching]);

  const filteredBranches = useMemo(() => {
    if (!selectedGroup) return [];
    if (!isSearching) return selectedGroup.branches;
    return selectedGroup.branches.filter(
      (b) =>
        normalize(b.name).includes(normalizedSearch) ||
        normalize(b.description ?? "").includes(normalizedSearch) ||
        normalize(b.code ?? "").includes(normalizedSearch),
    );
  }, [selectedGroup, normalizedSearch, isSearching]);

  const globalBranchResults = useMemo(() => {
    if (!isSearching || selectedBrandId) return [];
    const results: { brand: Brand; branch: Branch }[] = [];
    for (const group of brandGroups) {
      for (const branch of group.branches) {
        if (
          normalize(branch.name).includes(normalizedSearch) ||
          normalize(branch.description ?? "").includes(normalizedSearch) ||
          normalize(branch.code ?? "").includes(normalizedSearch)
        ) {
          results.push({ brand: group.brand, branch });
        }
      }
    }
    return results;
  }, [brandGroups, normalizedSearch, isSearching, selectedBrandId]);

  function handleSelectBranch(slug: string) {
    if (slug === currentBranch.slug) {
      handleClose();
      return;
    }
    if (totalItems > 0) {
      setPendingSlug(slug);
    } else {
      switchBranch(slug);
      setSelectedBrandId(null);
      setSearch("");
    }
  }

  function confirmSwitch() {
    if (pendingSlug) {
      clearCart();
      switchBranch(pendingSlug);
    }
    setPendingSlug(null);
    setSelectedBrandId(null);
    setSearch("");
  }

  const handleClose = useCallback(() => {
    closeSwitcher();
    setSelectedBrandId(null);
    setSearch("");
  }, [closeSwitcher]);

  function goBackToBrands() {
    setSelectedBrandId(null);
    setSearch("");
  }

  return (
    <>
      {/* Switcher dialog */}
      <Dialog open={isSwitcherOpen} onOpenChange={(o) => !o && handleClose()}>
        <DialogContent
          showCloseButton={false}
          className="flex max-h-[70vh] w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden rounded-2xl p-0 sm:max-w-md"
        >
          {/* Header */}
          <div className="flex items-center gap-2 border-b px-4 py-3">
            {selectedGroup ? (
              <>
                <button
                  aria-label={tc('back')}
                  type="button"
                  onClick={goBackToBrands}
                  className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                  <ChevronLeft className="h-4 w-4" />
                </button>
                <span className="flex-1 text-sm font-semibold">
                  {selectedGroup.brand.name}
                </span>
                <span className="text-xs text-muted-foreground">
                  {t('branchCount', { count: selectedGroup.branches.length })}
                </span>
              </>
            ) : (
              <>
                <span className="flex-1 text-sm font-semibold">
                  {t('selectBrand')}
                </span>
                <span className="text-xs text-muted-foreground">
                  {t('brandCount', { count: brandGroups.length })}
                </span>
              </>
            )}
            {/* Always-visible close button — the brand-list view had no way to
                dismiss the dialog (showCloseButton=false + back button only
                appears after drilling into a brand), leaving users stuck. */}
            <button
              type="button"
              onClick={handleClose}
              aria-label={tc('close')}
              className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <X className="h-4 w-4" />
            </button>
          </div>

          {/* Search */}
          <div className="border-b px-4 py-2.5">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder={
                  selectedGroup
                    ? t('searchBranch')
                    : t('searchBrandOrBranch')
                }
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="h-9 pl-9 pr-8 text-sm"
              />
              {search && (
                <button
                  aria-label={tc('close')}
                  type="button"
                  onClick={() => setSearch("")}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              )}
            </div>
          </div>

          {/* Content */}
          <div className="flex-1 overflow-y-auto">
            {isLoadingBranches ? (
              <div className="flex flex-col items-center justify-center gap-2 py-10 text-muted-foreground">
                <Loader2 className="h-5 w-5 animate-spin" aria-label={t('loading')} />
                <p className="text-sm">{t('loadingBranches')}</p>
              </div>
            ) : branchesError ? (
              <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                <AlertCircle className="h-6 w-6 text-destructive" />
                <p className="text-sm font-semibold">{t('loadError')}</p>
                <Button variant="outline" size="sm" onClick={refetchBranches}>
                  {tc('retry')}
                </Button>
              </div>
            ) : selectedGroup ? (
              filteredBranches.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">
                  {t('noBranch')}
                </p>
              ) : (
                filteredBranches.map((branch) => {
                  const isActive = branch.slug === currentBranch.slug;
                  return (
                    <BranchRow
                      key={branch.id}
                      branch={branch}
                      isActive={isActive}
                      onClick={() => handleSelectBranch(branch.slug)}
                    />
                  );
                })
              )
            ) : (
              <>
                {/* Global branch results when searching */}
                {isSearching && globalBranchResults.length > 0 && (
                  <div>
                    <div className="sticky top-0 z-10 border-b bg-muted/50 px-4 py-1.5 backdrop-blur-sm">
                      <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {t('branches', { count: globalBranchResults.length })}
                      </span>
                    </div>
                    {globalBranchResults.map(({ brand, branch }) => {
                      const isActive = branch.slug === currentBranch.slug;
                      return (
                        <BranchRow
                          key={branch.id}
                          branch={branch}
                          subtitle={brand.name}
                          isActive={isActive}
                          onClick={() => handleSelectBranch(branch.slug)}
                        />
                      );
                    })}
                  </div>
                )}

                {/* Brand rows */}
                {filteredBrandGroups.length === 0 &&
                globalBranchResults.length === 0 ? (
                  <p className="py-10 text-center text-sm text-muted-foreground">
                    {t('noResults')}
                  </p>
                ) : (
                  <>
                    {isSearching && filteredBrandGroups.length > 0 && (
                      <div className="sticky top-0 z-10 border-b bg-muted/50 px-4 py-1.5 backdrop-blur-sm">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {t('brands', { count: filteredBrandGroups.length })}
                        </span>
                      </div>
                    )}
                    {filteredBrandGroups.map((group) => {
                      const isCurrentBrand = group.branches.some(
                        (b) => b.slug === currentBranch.slug,
                      );
                      return (
                        <button
                          key={group.brand.id}
                          type="button"
                          onClick={() => {
                            setSelectedBrandId(group.brand.id);
                            setSearch("");
                          }}
                          className={`flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50 ${
                            isCurrentBrand ? "bg-primary/5" : ""
                          }`}
                        >
                          <span
                            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-sm font-bold ${
                              isCurrentBrand
                                ? "bg-primary text-primary-foreground"
                                : "bg-muted text-muted-foreground"
                            }`}
                          >
                            {group.brand.name.charAt(0)}
                          </span>
                          <div className="min-w-0 flex-1">
                            <span
                              className={`truncate text-sm font-semibold ${
                                isCurrentBrand
                                  ? "text-primary"
                                  : "text-foreground"
                              }`}
                            >
                              {group.brand.name}
                            </span>
                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                              {t('branchCount', { count: group.branches.length })}
                              {group.brand.description &&
                                ` · ${group.brand.description}`}
                            </p>
                          </div>
                          <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/40" />
                        </button>
                      );
                    })}
                  </>
                )}
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* Confirm switch dialog */}
      <Dialog
        open={!!pendingSlug}
        onOpenChange={(o) => !o && setPendingSlug(null)}
      >
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>{t('switchTitle')}</DialogTitle>
            <DialogDescription>
              {t('switchDesc', { count: totalItems })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="flex-row justify-end gap-2">
            <Button variant="outline" onClick={() => setPendingSlug(null)}>
              {tc('cancel')}
            </Button>
            <Button variant="destructive" onClick={confirmSwitch}>
              {t('confirmSwitch')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

/** Reusable branch row */
function BranchRow({
  branch,
  subtitle,
  isActive,
  onClick,
}: {
  branch: Branch;
  subtitle?: string;
  isActive: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-muted/50 ${
        isActive ? "bg-primary/5" : ""
      }`}
    >
      <span
        className={`h-2 w-2 shrink-0 rounded-full ${
          isActive ? "bg-primary" : "bg-transparent"
        }`}
      />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-1.5">
          <span
            className={`truncate text-sm ${
              isActive
                ? "font-semibold text-primary"
                : "font-medium text-foreground"
            }`}
          >
            {branch.name}
          </span>
          {branch.code && (
            <span className="shrink-0 rounded bg-muted px-1.5 py-px text-[10px] font-medium tabular-nums text-muted-foreground">
              {branch.code}
            </span>
          )}
        </div>
        <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
          {subtitle ? `${subtitle} · ` : ""}
          {branch.description ?? ""}
        </p>
      </div>
      {isActive && <Check className="h-4 w-4 shrink-0 text-primary" />}
    </button>
  );
}
