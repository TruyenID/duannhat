"use client";

import { useMemo, useState } from "react";
import { Check, ChevronsUpDown, Save, X } from "lucide-react";
import { Badge } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Card } from "@godxjp/ui";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@godxjp/ui";
import { Popover, PopoverContent, PopoverTrigger } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { cn } from "@/lib/utils";
import {
  useToppingGroupsForProduct,
  useToppingGroupLookup,
  useSyncProductToppingGroups,
} from "@/hooks/api/use-topping-groups";
import { useTranslation } from "@/providers/app-provider";

export interface ProductToppingGroupsCardProps {
  brandSlug: string;
  productId: string;
  disabled?: boolean;
}

export function ProductToppingGroupsCard({
  brandSlug,
  productId,
  disabled = false,
}: ProductToppingGroupsCardProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  const assignedQuery = useToppingGroupsForProduct(brandSlug, productId);
  const lookupQuery = useToppingGroupLookup(brandSlug);
  const syncMutation = useSyncProductToppingGroups(brandSlug, productId);

  const [draftIds, setDraftIds] = useState<string[] | null>(null);

  const serverIds = useMemo(
    () => (assignedQuery.data?.data ?? []).map((g) => g.id),
    [assignedQuery.data]
  );

  const effectiveIds = draftIds ?? serverIds;

  const lookupMap = useMemo(() => {
    const m: Record<string, string> = {};
    for (const g of lookupQuery.data?.data ?? []) m[g.id] = g.name;
    for (const g of assignedQuery.data?.data ?? []) if (!m[g.id]) m[g.id] = g.name;
    return m;
  }, [lookupQuery.data, assignedQuery.data]);

  const allGroups = useMemo(() => lookupQuery.data?.data ?? [], [lookupQuery.data]);

  const selectedGroups = useMemo(
    () => effectiveIds.map((id) => ({ id, name: lookupMap[id] ?? id })),
    [effectiveIds, lookupMap]
  );

  function handleToggle(id: string) {
    setDraftIds(
      effectiveIds.includes(id) ? effectiveIds.filter((x) => x !== id) : [...effectiveIds, id]
    );
  }

  async function handleSave() {
    await syncMutation.mutateAsync({ topping_group_ids: effectiveIds });
    setDraftIds(null);
  }

  const isDirty = draftIds !== null;
  const isDisabledTrigger = disabled || lookupQuery.isLoading;

  if (assignedQuery.isLoading) {
    return (
      <Card className="p-4">
        <div className="mb-2 text-sm font-semibold">
          {t("hq.topping_groups.product_section.title")}
        </div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <Spinner className="size-3.5" />
          {t("common.loading")}
        </div>
      </Card>
    );
  }

  return (
    <Card className="p-4" data-slot="product-topping-groups-card">
      <div className="mb-3 text-sm font-semibold">
        {t("hq.topping_groups.product_section.title")}
      </div>

      <div className="flex flex-col gap-2">
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <div
              role="combobox"
              aria-expanded={open}
              aria-disabled={isDisabledTrigger}
              tabIndex={isDisabledTrigger ? -1 : 0}
              onClick={() => !isDisabledTrigger && setOpen((v) => !v)}
              onKeyDown={(e) => {
                if ((e.key === "Enter" || e.key === " ") && !isDisabledTrigger) {
                  e.preventDefault();
                  setOpen((v) => !v);
                }
              }}
              className={cn(
                "flex min-h-9 w-full cursor-pointer items-start rounded-md border border-input bg-background px-2 py-1.5 text-sm shadow-xs ring-offset-background select-none",
                "focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none",
                open && "ring-2 ring-ring ring-offset-2",
                isDisabledTrigger && "cursor-not-allowed opacity-50"
              )}
            >
              <div className="flex flex-1 flex-wrap gap-1">
                {selectedGroups.length === 0 ? (
                  <span className="px-0.5 text-xs text-muted-foreground">
                    {t("hq.topping_groups.product_section.add_placeholder")}
                  </span>
                ) : (
                  selectedGroups.map((g) => (
                    <Badge
                      key={g.id}
                      variant="secondary"
                      className="gap-1 px-1.5 py-0.5 text-xs font-normal"
                    >
                      {g.name}
                      {!disabled && (
                        <span
                          role="button"
                          tabIndex={0}
                          aria-label={`Remove ${g.name}`}
                          onClick={(e) => {
                            e.stopPropagation();
                            handleToggle(g.id);
                          }}
                          onKeyDown={(e) => {
                            if (e.key === "Enter" || e.key === " ") {
                              e.preventDefault();
                              e.stopPropagation();
                              handleToggle(g.id);
                            }
                          }}
                          className="flex size-3.5 cursor-pointer items-center justify-center rounded-sm hover:bg-muted-foreground/20"
                        >
                          <X className="size-3" />
                        </span>
                      )}
                    </Badge>
                  ))
                )}
              </div>
              <ChevronsUpDown className="mt-0.5 ml-2 size-4 shrink-0 opacity-50" />
            </div>
          </PopoverTrigger>
          <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
            <Command>
              <CommandInput
                placeholder={t("hq.topping_groups.product_section.search_placeholder")}
              />
              <CommandList>
                <CommandEmpty>{t("common.no_results")}</CommandEmpty>
                <CommandGroup>
                  {allGroups.map((g) => {
                    const isSelected = effectiveIds.includes(g.id);
                    return (
                      <CommandItem
                        key={g.id}
                        value={g.name ?? g.id}
                        onSelect={() => handleToggle(g.id)}
                      >
                        <Check
                          className={cn("mr-2 size-4", isSelected ? "opacity-100" : "opacity-0")}
                        />
                        {g.name}
                      </CommandItem>
                    );
                  })}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>

        {isDirty && !disabled && (
          <Button
            type="button"
            size="sm"
            className="h-8 gap-1.5 text-xs"
            onClick={handleSave}
            disabled={syncMutation.isPending}
          >
            {syncMutation.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <Save className="size-3.5" />
            )}
            {t("hq.topping_groups.product_section.sync")}
          </Button>
        )}
      </div>
    </Card>
  );
}
