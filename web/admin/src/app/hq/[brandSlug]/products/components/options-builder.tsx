"use client";

import { useCallback, useState } from "react";
import { Plus, Trash2, X, Check, ChevronsUpDown, ListTree } from "lucide-react";
import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Popover, PopoverContent, PopoverTrigger } from "@godxjp/ui";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { toOptionSlug } from "@/lib/option-slug";
import { useTranslation } from "@/providers/app-provider";

/**
 * ProductOptionsBuilder — simplified local-state options editor.
 * Stacked inputs for values as per user screenshot 3.
 */

export interface DraftOptionValue {
  tempId: string;
  value: string;
  label: string;
}

export interface DraftOption {
  tempId: string;
  key: string;
  name: string;
  position: 1 | 2 | 3;
  values: DraftOptionValue[];
}

interface OptionsBuilderProps {
  options: DraftOption[];
  onChange: (next: DraftOption[]) => void;
}

const MAX_OPTIONS = 3;

let tempIdCounter = Date.now();
function nextTempId(prefix: string): string {
  tempIdCounter += 1;
  return `${prefix}-${tempIdCounter}`;
}

// Option `key` / value `value` must satisfy the backend's `^[a-z0-9_]+$`, and a
// fully Japanese label slugifies to "" \u2014 see @/lib/option-slug for the fallback.
function slugify(raw: string | null | undefined): string {
  return toOptionSlug(raw, "option");
}

function slugifyValue(raw: string | null | undefined): string {
  return toOptionSlug(raw, "value");
}

export function ProductOptionsBuilder({ options, onChange }: OptionsBuilderProps) {
  const { t } = useTranslation();
  const COMMON_ATTRIBUTES = [
    t("hq.products.options.common_size"),
    t("hq.products.options.common_color"),
    t("hq.products.options.common_material"),
    t("hq.products.options.common_style"),
  ];
  const canAddOption = options.length < MAX_OPTIONS;

  const addOption = useCallback(() => {
    if (options.length >= MAX_OPTIONS) return;
    const nextPosition = (options.length + 1) as 1 | 2 | 3;
    onChange([
      ...options,
      {
        tempId: nextTempId("opt"),
        key: "",
        name: "",
        position: nextPosition,
        values: [{ tempId: nextTempId("val"), value: "", label: "" }],
      },
    ]);
  }, [options, onChange]);

  const removeOption = useCallback(
    (tempId: string) => {
      const next = options
        .filter((o) => o.tempId !== tempId)
        .map((o, idx) => ({ ...o, position: (idx + 1) as 1 | 2 | 3 }));
      onChange(next);
    },
    [options, onChange]
  );

  const updateOption = useCallback(
    (tempId: string, patch: Partial<Pick<DraftOption, "key" | "name">>) => {
      onChange(options.map((o) => (o.tempId === tempId ? { ...o, ...patch } : o)));
    },
    [options, onChange]
  );

  const addValue = useCallback(
    (optionTempId: string, label: string = "") => {
      onChange(
        options.map((o) =>
          o.tempId === optionTempId
            ? {
                ...o,
                values: [
                  ...o.values,
                  { tempId: nextTempId("val"), value: slugifyValue(label), label },
                ],
              }
            : o
        )
      );
    },
    [options, onChange]
  );

  const removeValue = useCallback(
    (optionTempId: string, valueTempId: string) => {
      onChange(
        options.map((o) =>
          o.tempId === optionTempId
            ? {
                ...o,
                values: o.values.filter((v) => v.tempId !== valueTempId),
              }
            : o
        )
      );
    },
    [options, onChange]
  );

  const updateValue = useCallback(
    (
      optionTempId: string,
      valueTempId: string,
      patch: Partial<Pick<DraftOptionValue, "value" | "label">>
    ) => {
      onChange(
        options.map((o) =>
          o.tempId === optionTempId
            ? {
                ...o,
                values: o.values.map((v) => (v.tempId === valueTempId ? { ...v, ...patch } : v)),
              }
            : o
        )
      );
    },
    [options, onChange]
  );

  return (
    <div className="flex flex-col gap-4">
      {options.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-md border border-dashed bg-muted/20 p-8 text-center">
          <ListTree className="size-5 text-muted-foreground" />
          <p className="text-xs font-medium text-muted-foreground">
            {t("hq.products.options.empty")}
          </p>
        </div>
      ) : (
        <div className="divide-y overflow-hidden rounded-md border bg-card">
          {options.map((option) => (
            <div
              key={option.tempId}
              className="group grid grid-cols-1 items-start gap-4 p-6 transition-colors hover:bg-muted/30 md:grid-cols-[240px_40px_1fr]"
            >
              {/* Column 1: Attribute Name */}
              <div className="flex flex-col gap-1.5">
                <span className="ml-1 text-[11px] font-bold tracking-tight text-slate-500 uppercase">
                  {t("hq.products.options.attribute")}
                </span>
                <AttributeSelector
                  value={option.name}
                  onSelect={(name) => {
                    updateOption(option.tempId, {
                      name,
                      key: slugify(name),
                    });
                  }}
                  commonAttributes={COMMON_ATTRIBUTES}
                />
              </div>

              {/* Column 2: Remove Attribute Button (middle) */}
              <div className="flex flex-col items-center pt-7">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="mb-1 size-9 text-slate-400 hover:bg-destructive/5 hover:text-destructive"
                  onClick={() => removeOption(option.tempId)}
                >
                  <Trash2 className="size-4.5" />
                </Button>
              </div>

              {/* Column 3: Stacked Value List */}
              <div className="flex flex-col gap-1.5">
                <span className="ml-1 text-[11px] font-bold tracking-tight text-slate-500 uppercase">
                  {t("hq.products.options.value")}
                </span>
                <div className="flex flex-col gap-2">
                  {option.values.map(
                    (v) =>
                      v.label && (
                        <div key={v.tempId} className="group/val relative">
                          <Input
                            value={v.label}
                            onChange={(e) =>
                              updateValue(option.tempId, v.tempId, {
                                label: e.target.value,
                                value: slugifyValue(e.target.value),
                              })
                            }
                            className="h-10 pr-10 text-sm font-medium focus-visible:ring-1 focus-visible:ring-primary/40"
                          />
                          <button
                            type="button"
                            className="absolute top-1/2 right-3 flex size-6 -translate-y-1/2 items-center justify-center text-slate-300 transition-colors hover:text-destructive"
                            onClick={() => removeValue(option.tempId, v.tempId)}
                            disabled={option.values.length === 1}
                          >
                            <X className="size-4" />
                          </button>
                        </div>
                      )
                  )}

                  {/* Add New Value Input always at bottom */}
                  <div className="relative">
                    <Input
                      placeholder={t("hq.products.options.add_value")}
                      className="h-10 text-sm focus-visible:ring-1 focus-visible:ring-primary/40"
                      onKeyDown={(e) => {
                        if (e.key === "Enter" && !e.nativeEvent.isComposing) {
                          e.preventDefault();
                          const val = e.currentTarget.value.trim();
                          if (val) {
                            addValue(option.tempId, val);
                            e.currentTarget.value = "";
                          }
                        }
                      }}
                      // #2488 — chữ gõ dở commit khi rời ô, không bốc hơi im
                      // lặng lúc bấm nút tạo. Blur bắn trước click và React
                      // re-render giữa hai event, nên submit thấy giá trị này.
                      onBlur={(e) => {
                        const val = e.currentTarget.value.trim();
                        if (val) {
                          addValue(option.tempId, val);
                          e.currentTarget.value = "";
                        }
                      }}
                    />
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {canAddOption && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-10 w-full gap-2 border-dashed text-xs font-bold text-primary hover:bg-primary/5"
          onClick={addOption}
        >
          <Plus className="size-3.5" />
          {t("hq.products.options.add_attribute", { current: options.length, max: MAX_OPTIONS })}
        </Button>
      )}
    </div>
  );
}

function AttributeSelector({
  value,
  onSelect,
  commonAttributes,
}: {
  value: string | null | undefined;
  onSelect: (val: string) => void;
  commonAttributes: string[];
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");

  const trimmed = search.trim();
  const matchesExisting = commonAttributes.some((a) => a.toLowerCase() === trimmed.toLowerCase());
  const showCreate = trimmed.length > 0 && !matchesExisting;

  const handleSelect = (val: string) => {
    onSelect(val);
    setSearch("");
    setOpen(false);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className={cn(
            "h-9 w-full justify-between font-normal",
            !value && "text-muted-foreground"
          )}
        >
          <span className="truncate">{value || t("hq.products.options.search_or_add")}</span>
          <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput
            placeholder={t("hq.products.options.search_placeholder")}
            value={search}
            onValueChange={setSearch}
          />
          <CommandList>
            <CommandEmpty>{t("hq.products.options.not_found")}</CommandEmpty>
            <CommandGroup>
              {commonAttributes.map((attr) => (
                <CommandItem key={attr} value={attr} onSelect={() => handleSelect(attr)}>
                  <Check
                    className={cn("mr-2 size-4", value === attr ? "opacity-100" : "opacity-0")}
                  />
                  {attr}
                </CommandItem>
              ))}
            </CommandGroup>
            {showCreate && (
              <>
                <CommandSeparator />
                <CommandGroup>
                  <CommandItem
                    value={`__create__${trimmed}`}
                    onSelect={() => handleSelect(trimmed)}
                    className="text-primary"
                  >
                    <Plus className="mr-2 size-4" />
                    {t("hq.products.options.create", { name: trimmed })}
                  </CommandItem>
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
