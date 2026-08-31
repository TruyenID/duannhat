"use client";

/**
 * Server-side paginated, searchable product picker. Fetches `pageSize`
 * results at a time (default 5) and debounces the search input so typing
 * doesn't hit the API on every keystroke. Used by the material/recipe
 * component builders to pick a parent product before choosing a SKU.
 */

import { useState } from "react";
import { Check, ChevronsUpDown } from "lucide-react";
import {
  Button,
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Spinner,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { useDebounce } from "@/hooks/use-debounce";
import { useProducts } from "@/hooks/api/use-products";
import { useTranslation } from "@/providers/app-provider";

export interface ProductSearchSelectProps {
  brandSlug: string;
  /** Currently selected product id. */
  value: string;
  /** Display label for the trigger when `value` is set but not in the current page's results. */
  label?: string;
  /** Fires with `(id, name)` so the caller can store both for later rendering. */
  onChange: (id: string, name: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  /** Page size for the server query. Defaults to 5. */
  pageSize?: number;
  /** Debounce delay for search input in ms. Defaults to 1500. */
  debounceMs?: number;
}

export function ProductSearchSelect({
  brandSlug,
  value,
  label,
  onChange,
  placeholder,
  disabled,
  className,
  pageSize = 5,
  debounceMs = 1500,
}: ProductSearchSelectProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const debouncedSearch = useDebounce(search, debounceMs);
  const resolvedPlaceholder = placeholder ?? t("product_search_select.placeholder");

  const { data, isLoading, isFetching } = useProducts(brandSlug, {
    per_page: pageSize,
    search: debouncedSearch || undefined,
    sort: "name",
  });
  const products = data?.data ?? [];

  // When `value` is set but missing from the current page, fall back to the
  // label the caller last observed so the trigger button still reads correctly.
  const inList = products.find((p) => p.id === value);
  const triggerLabel = inList?.name ?? label ?? "";

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          data-slot="product-search-select"
          className={cn("h-9 w-full justify-between text-xs font-normal", className)}
        >
          <span className={cn("truncate", !value && "text-muted-foreground")}>
            {value ? triggerLabel || "—" : resolvedPlaceholder}
          </span>
          <ChevronsUpDown className="ml-2 size-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput
            placeholder={t("product_search_select.search_placeholder")}
            value={search}
            onValueChange={setSearch}
          />
          <CommandList>
            {isLoading || isFetching ? (
              <div className="flex items-center justify-center py-6">
                <Spinner className="size-4" />
              </div>
            ) : products.length === 0 ? (
              <CommandEmpty>{t("product_search_select.empty")}</CommandEmpty>
            ) : (
              <CommandGroup>
                {products.map((p) => (
                  <CommandItem
                    key={p.id}
                    value={p.id}
                    onSelect={() => {
                      onChange(p.id, p.name);
                      setOpen(false);
                      setSearch("");
                    }}
                  >
                    <Check
                      className={cn("mr-2 size-3.5", value === p.id ? "opacity-100" : "opacity-0")}
                    />
                    <span className="truncate">{p.name}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
