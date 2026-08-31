"use client";

import { useEffect, useMemo, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import {
  Check,
  ChevronsUpDown,
  GripVertical,
  ImageIcon,
  Pencil,
  Plus,
  Trash2,
  X,
} from "lucide-react";
import { DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";

function sortByPosition<T extends { position?: number | null }>(items: readonly T[]): T[] {
  return [...items].sort((a, b) => (a.position ?? 0) - (b.position ?? 0));
}

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Badge,
  Button,
  Card,
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  Input,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Spinner,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";

import { SkuGalleryDialog } from "./sku-gallery-dialog";

import {
  useGenerateSkuCombinations,
  useProductSku,
  useSyncSkuImages,
} from "@/hooks/api/use-product-skus";
import {
  useExpandProductOption,
  useUpdateProductOption,
  useDeleteProductOption,
  useSyncOptionValues,
} from "@/hooks/api/use-product-options";
import { useForceDeleteProductOptionValue } from "@/hooks/api/use-product-option-values";
import { useQueryClient } from "@tanstack/react-query";
import { productOptionKeys } from "@/hooks/api/query-keys";
import { ApiError } from "@/lib/api";
import { toast } from "sonner";
import type { ProductImageFile } from "@/services/product-image-service";
import type { ProductOption, ProductSku } from "@/services/product-service";
import type { LocaleCode } from "@/i18n";
import { useTranslation } from "@/providers/app-provider";
import { resolveOptionName, resolveOptionValueLabel } from "@/lib/option-i18n";
import { toOptionSlug } from "@/lib/option-slug";

export interface VariantsDisplayProps {
  brandSlug: string;
  productId: string;
  options: ProductOption[];
  skus: ProductSku[];
  productName?: string | null;
}

function skuVariantLabels(sku: ProductSku): string[] {
  return [sku.option_value1, sku.option_value2, sku.option_value3]
    .map((v) => (v ? resolveOptionValueLabel(v) : null))
    .filter((label): label is string => Boolean(label));
}

function SkuThumb({
  sku,
  alt,
  onClick,
  ariaLabel,
}: {
  sku: ProductSku;
  alt: string;
  onClick: () => void;
  ariaLabel: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={ariaLabel}
      data-slot="sku-thumb"
      className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded border bg-muted transition hover:border-primary/50 hover:shadow-sm focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none"
    >
      {sku.image_url ? (
        <Image
          src={sku.image_url}
          alt={alt}
          width={40}
          height={40}
          className="h-full w-full object-cover"
        />
      ) : (
        <ImageIcon className="size-4 text-muted-foreground" />
      )}
    </button>
  );
}

/**
 * Group a SKU price for display.
 *
 * The locale was hardcoded to "vi-VN", so a price of 1234 rendered as "1.234"
 * for every reader — and vi-VN uses "." as the thousands separator, which a
 * ja/en reader parses as a decimal point. A price is exactly the wrong field to
 * make someone squint at.
 *
 * No currency symbol here on purpose: this column has never shown one, and the
 * brand's currency is not resolved on this screen. Fixing the separator is the
 * defect; adding a symbol would be a design change.
 */
function formatCurrency(raw: string | null | undefined, locale: LocaleCode): string {
  if (raw === null || raw === undefined || raw === "") return "0";
  const n = Number(raw);
  if (!Number.isFinite(n)) return raw;
  return n.toLocaleString(LOCALE_TO_INTL[locale]);
}

const LOCALE_TO_INTL: Record<LocaleCode, string> = {
  ja: "ja-JP",
  en: "en-US",
  vi: "vi-VN",
};

// Option `key` / value `value` must satisfy the backend's `^[a-z0-9_]+$`, and a
// fully Japanese label slugifies to "" — see @/lib/option-slug for the fallback.
function slugify(raw: string): string {
  return toOptionSlug(raw, "option");
}

function slugifyValue(raw: string): string {
  return toOptionSlug(raw, "value");
}

// ---------------------------------------------------------------------------
//  AttributeSelector — Combobox matching the new-product options builder
// ---------------------------------------------------------------------------

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
            "h-10 w-full justify-between font-normal",
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

// ---------------------------------------------------------------------------
//  NewOptionRow — inline form to create a brand-new option (no modal)
// ---------------------------------------------------------------------------

function NewOptionRow({
  brandSlug,
  productId,
  usedPositions,
  onDone,
}: {
  brandSlug: string;
  productId: string;
  usedPositions: number[];
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const COMMON_ATTRIBUTES = [
    t("hq.products.options.common_size"),
    t("hq.products.options.common_color"),
    t("hq.products.options.common_material"),
    t("hq.products.options.common_style"),
  ];

  const [name, setName] = useState("");
  const [values, setValues] = useState<string[]>([]);
  // #2488 — chữ đang gõ trong ô thêm giá trị, có kiểm soát để handleDone thấy.
  const [pendingValue, setPendingValue] = useState("");
  const expand = useExpandProductOption(brandSlug, productId);

  const availablePositions = ([1, 2, 3] as const).filter((p) => !usedPositions.includes(p));
  const position = availablePositions[0];

  function addValue(label: string) {
    const trimmed = label.trim();
    if (trimmed) setValues((prev) => [...prev, trimmed]);
  }

  function removeValue(index: number) {
    setValues((prev) => prev.filter((_, i) => i !== index));
  }

  function updateValue(index: number, label: string) {
    setValues((prev) => prev.map((v, i) => (i === index ? label : v)));
  }

  function commitPendingValue() {
    const val = pendingValue.trim();
    if (val) addValue(val);
    setPendingValue("");
  }

  async function handleDone() {
    const trimmedName = name.trim();
    // Fold chữ còn dở (#2488) — blur thường đã commit; đây là lưới đỡ.
    const pending = pendingValue.trim();
    if (pending) setPendingValue("");
    const nonEmpty = [...values, ...(pending ? [pending] : [])].filter((v) => v.trim());
    if (!trimmedName || nonEmpty.length === 0 || !position) return;

    try {
      await expand.mutateAsync({
        key: slugify(trimmedName),
        name: trimmedName,
        position,
        values: nonEmpty.map((v) => ({ value: slugifyValue(v), label: v })),
        default_value_index: 0,
        generate_combinations: true,
      });
      onDone();
    } catch {
      // toast fired by hook
    }
  }

  const canSubmit = name.trim() && values.some((v) => v.trim()) && !expand.isPending && !!position;

  return (
    <div className="rounded-md border bg-muted/20 p-5">
      <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-[240px_40px_1fr]">
        {/* Column 1: Attribute name */}
        <div className="flex flex-col gap-1.5">
          <span className="ml-1 text-[11px] font-bold tracking-tight text-slate-500 uppercase">
            {t("hq.products.options.attribute")}
          </span>
          <AttributeSelector
            value={name || null}
            onSelect={setName}
            commonAttributes={COMMON_ATTRIBUTES}
          />
        </div>

        {/* Column 2: Cancel / discard */}
        <div className="flex flex-col items-center pt-7">
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-9 text-slate-400 hover:bg-destructive/5 hover:text-destructive"
            onClick={onDone}
            disabled={expand.isPending}
          >
            <X className="size-4" />
          </Button>
        </div>

        {/* Column 3: Values */}
        <div className="flex flex-col gap-1.5">
          <span className="ml-1 text-[11px] font-bold tracking-tight text-slate-500 uppercase">
            {t("hq.products.options.value")}
          </span>
          <div className="flex flex-col gap-2">
            {values.map(
              (v, i) =>
                v && (
                  <div key={i} className="relative flex items-center">
                    <Input
                      value={v}
                      onChange={(e) => updateValue(i, e.target.value)}
                      className="h-10 pr-10 text-sm font-medium focus-visible:ring-1 focus-visible:ring-primary/40"
                    />
                    <button
                      type="button"
                      className="absolute top-1/2 right-3 flex size-6 -translate-y-1/2 items-center justify-center text-slate-300 transition-colors hover:text-destructive"
                      onClick={() => removeValue(i)}
                    >
                      <X className="size-4" />
                    </button>
                  </div>
                )
            )}
            {/* #2488 — cùng lưới đỡ với OptionEditForm: chữ gõ dở commit khi
                blur và được fold lúc bấm nút, không bốc hơi im lặng. */}
            <div className="flex items-center">
              <Input
                placeholder={t("hq.products.options.add_value")}
                className="h-10 text-sm focus-visible:ring-1 focus-visible:ring-primary/40"
                value={pendingValue}
                onChange={(e) => setPendingValue(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.nativeEvent.isComposing) {
                    e.preventDefault();
                    commitPendingValue();
                  }
                }}
                onBlur={commitPendingValue}
              />
            </div>
          </div>
          {/* Done button */}
          <div className="mt-2 flex justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleDone}
              disabled={!canSubmit}
              className="h-8 px-8 text-xs"
            >
              {expand.isPending ? <Spinner className="mr-1.5 size-3.5" /> : null}
              {t("common.save")}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
//  OptionViewRow — compact read-only row
// ---------------------------------------------------------------------------

function OptionViewRow({
  option,
  onEdit,
  dragHandleProps,
}: {
  option: ProductOption;
  onEdit: () => void;
  dragHandleProps?: React.HTMLAttributes<HTMLButtonElement>;
}) {
  const { t } = useTranslation();
  const displayName = resolveOptionName(option);
  return (
    <div className="flex items-center gap-3 px-4 py-3">
      <button
        type="button"
        {...dragHandleProps}
        className="cursor-grab text-slate-300 hover:text-slate-400 focus:outline-none active:cursor-grabbing"
        aria-label="drag to reorder"
      >
        <GripVertical className="size-4" />
      </button>
      <span className="w-28 shrink-0 text-sm font-semibold">
        {displayName || (
          <span className="text-muted-foreground italic">{t("hq.products.variants.unnamed")}</span>
        )}
      </span>
      <div className="flex flex-1 flex-wrap gap-1.5">
        {(option.values ?? []).length === 0 ? (
          <span className="text-xs text-muted-foreground italic">
            {t("hq.products.variants.no_value")}
          </span>
        ) : (
          sortByPosition(option.values ?? []).map((v) => (
            <Badge key={v.id} variant="outline" className="h-6 px-2.5 text-xs font-medium">
              {resolveOptionValueLabel(v)}
            </Badge>
          ))
        )}
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 shrink-0 text-xs"
        onClick={onEdit}
      >
        {t("common.edit")}
      </Button>
    </div>
  );
}

// ---------------------------------------------------------------------------
//  OptionEditRow — inline expanded edit form
// ---------------------------------------------------------------------------

/**
 * Draft row state for the values panel. `id` distinguishes existing rows
 * (rename → PUT) from new ones (insert → POST), both folded into a single
 * `sync-values` batch call on "Xong".
 */
interface DraftValueRow {
  id?: string;
  label: string;
  // `_uid` is a stable React key for unsaved rows (id is undefined until save).
  _uid: string;
}

function SortableValueRow({
  row,
  disabled,
  onChange,
  onRemove,
}: {
  row: DraftValueRow;
  disabled: boolean;
  onChange: (label: string) => void;
  onRemove: () => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: row._uid,
    disabled,
  });

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn("flex items-center gap-1", isDragging && "z-10 opacity-50")}
    >
      <button
        type="button"
        {...listeners}
        {...attributes}
        disabled={disabled}
        className="cursor-grab text-slate-300 hover:text-slate-400 focus:outline-none active:cursor-grabbing disabled:opacity-50"
        aria-label="drag to reorder"
      >
        <GripVertical className="size-4" />
      </button>
      <div className="relative flex flex-1 items-center">
        <Input
          value={row.label}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
          className="h-10 pr-10 text-sm font-medium focus-visible:ring-1 focus-visible:ring-primary/40"
        />
        <button
          type="button"
          className="absolute top-1/2 right-3 flex size-6 -translate-y-1/2 items-center justify-center text-slate-300 transition-colors hover:text-destructive disabled:opacity-50"
          onClick={onRemove}
          disabled={disabled}
        >
          <X className="size-4" />
        </button>
      </div>
    </div>
  );
}

function OptionEditRow({
  option,
  brandSlug,
  productId,
  onDone,
  onSaved,
  canDelete,
  onDirtyChange,
}: {
  option: ProductOption;
  brandSlug: string;
  productId: string;
  /** User-driven close — parent guards against unsaved changes. */
  onDone: () => void;
  /** Save / delete succeeded — parent must close immediately, no guard. */
  onSaved: () => void;
  canDelete: boolean;
  /** Bubble local dirty state up so the parent can guard navigation. */
  onDirtyChange?: (dirty: boolean) => void;
}) {
  const { t } = useTranslation();
  const COMMON_ATTRIBUTES = [
    t("hq.products.options.common_size"),
    t("hq.products.options.common_color"),
    t("hq.products.options.common_material"),
    t("hq.products.options.common_style"),
  ];

  // Hydrate from the resolved display name/label so users editing under a
  // locale that has no per-row translation still see the existing value
  // instead of an empty input (which would also wrongly flag the form dirty
  // on first render).
  const resolvedName = resolveOptionName(option);
  const [localName, setLocalName] = useState(resolvedName);
  const [draftRows, setDraftRows] = useState<DraftValueRow[]>(() =>
    sortByPosition(option.values ?? []).map((v) => ({
      id: v.id,
      label: resolveOptionValueLabel(v),
      _uid: v.id,
    }))
  );
  // #2488 — bản nháp phải ĐI THEO dữ liệu server chừng nào người dùng CHƯA gõ.
  //
  // `useState(initialiser)` chỉ chạy một lần, còn `syncValues` bên backend coi
  // danh sách gửi lên là TOÀN BỘ sự thật: thứ gì thiếu thì xoá mềm. Cộng lại:
  // một tab mở sẵn, bấm Lưu sau khi tab khác đã thêm giá trị, sẽ xoá giá trị
  // nó chưa từng nhìn thấy — kịch bản thật của #2488 (hai tab admin cùng mở).
  //
  // Mốc là cờ `touched` chứ KHÔNG phải `isDirty`: isDirty so bản nháp với prop
  // HIỆN TẠI, nên server vừa thêm giá trị là form sạch cũng thành "dirty" và
  // đúng lượt cần nạp lại thì bị chặn. Còn touched hỏi câu đúng — "người dùng
  // đã gõ gì chưa". Chưa gõ → theo server; đang gõ dở → giữ bản nháp (lỗ còn
  // lại này cần phiên bản hoá phía backend để đóng hẳn).
  //
  // Điều chỉnh NGAY KHI RENDER thay vì trong effect — khuôn React chính thống
  // cho derived state, và không thêm cảnh báo setState-trong-effect nào.
  const [touched, setTouched] = useState(false);
  // #2488 — chữ đang gõ trong ô "値を追加": phải là state có kiểm soát để
  // handleSave nhìn thấy nó; input không kiểm soát làm chữ gõ dở bốc hơi.
  const [pendingLabel, setPendingLabel] = useState("");
  const [hydratedFrom, setHydratedFrom] = useState(option.values);
  if (option.values !== hydratedFrom) {
    setHydratedFrom(option.values);
    if (!touched) {
      setLocalName(resolveOptionName(option));
      setDraftRows(
        sortByPosition(option.values ?? []).map((v) => ({
          id: v.id,
          label: resolveOptionValueLabel(v),
          _uid: v.id,
        }))
      );
    }
  }

  const [blockingSkus, setBlockingSkus] = useState<Array<{ id: string; sku: string }> | null>(null);
  const [blockingMenus, setBlockingMenus] = useState<Array<{ id: string; name: string }> | null>(
    null
  );

  // DnD for reordering value rows within the option edit form. Backend
  // re-numbers `position` 1..N from the submitted array order on save, so
  // the only state we need locally is the order of draftRows.
  const valueSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } })
  );

  function handleValueDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    setTouched(true);
    setDraftRows((prev) => {
      const oldIndex = prev.findIndex((r) => r._uid === active.id);
      const newIndex = prev.findIndex((r) => r._uid === over.id);
      if (oldIndex === -1 || newIndex === -1) return prev;
      return arrayMove(prev, oldIndex, newIndex);
    });
  }

  // Dirty = any field differs from the server snapshot we hydrated from.
  // Wrap in useMemo + useEffect-to-emit so parent state updates exactly once
  // per real change (not on every render).
  const isDirty = useMemo(() => {
    if (localName.trim() !== resolvedName) return true;
    const original = sortByPosition(option.values ?? []);
    if (draftRows.length !== original.length) return true;
    return draftRows.some((row, idx) => {
      if (!row.id) return true; // brand-new row
      const orig = original[idx];
      if (!orig || orig.id !== row.id) return true; // reordered
      return resolveOptionValueLabel(orig) !== row.label.trim();
    });
  }, [localName, draftRows, resolvedName, option.values]);

  useEffect(() => {
    onDirtyChange?.(isDirty);
    // Clear the flag when the form unmounts so a "Hủy" still releases the lock.
    return () => onDirtyChange?.(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isDirty]);


  const queryClient = useQueryClient();
  const updateOption = useUpdateProductOption(brandSlug, productId);
  const deleteOption = useDeleteProductOption(brandSlug, productId);
  const syncValues = useSyncOptionValues(brandSlug, productId);
  const forceDelete = useForceDeleteProductOptionValue(brandSlug, productId);

  function addRow(label = "") {
    setTouched(true);
    setDraftRows((prev) => [
      ...prev,
      { label, _uid: `new-${Date.now()}-${Math.random().toString(36).slice(2, 6)}` },
    ]);
  }

  function updateRow(uid: string, label: string) {
    setTouched(true);
    setDraftRows((prev) => prev.map((r) => (r._uid === uid ? { ...r, label } : r)));
  }

  function removeRow(uid: string) {
    setTouched(true);
    setDraftRows((prev) => prev.filter((r) => r._uid !== uid));
  }

  function commitPendingLabel() {
    const val = pendingLabel.trim();
    if (val) addRow(val);
    setPendingLabel("");
  }

  async function handleSave() {
    const trimmedName = localName.trim();
    // Fold chữ còn dở trong ô thêm-giá-trị vào lượt lưu (#2488). Blur trước cú
    // click thường đã commit và xoá pending nên không nhân đôi; đây là lưới đỡ
    // cho đường bàn phím và cho cú click trượt sau khi blur làm layout xê dịch.
    const pending = pendingLabel.trim();
    const rows = pending ? [...draftRows, { label: pending, _uid: `pending-${pending}` }] : draftRows;
    if (pending) setPendingLabel("");
    const cleanRows = rows
      .map((r) => ({ ...r, label: r.label.trim() }))
      .filter((r) => r.label.length > 0);

    if (cleanRows.length === 0) {
      toast.error(t("hq.products.options.add_value"));
      return;
    }

    try {
      await syncValues.mutateAsync({
        optionId: option.id,
        data: {
          name: trimmedName && trimmedName !== resolvedName ? trimmedName : undefined,
          values: cleanRows.map((r) =>
            r.id ? { id: r.id, label: r.label } : { value: slugifyValue(r.label), label: r.label }
          ),
          // #2488 — khai tập id bản nháp NÀY hydrate từ đó (hydratedFrom, không
          // phải option.values: prop có thể đã mới hơn bản nháp). Server lệch
          // thì 409 thay vì để lượt lưu xoá giá trị tab khác vừa thêm.
          known_value_ids: (hydratedFrom ?? []).map((v) => v.id),
        },
      });
      onSaved();
    } catch (err) {
      if (
        err instanceof ApiError &&
        err.status === 409 &&
        (err.body as { error?: string })?.error === "OPTION_VALUES_CHANGED"
      ) {
        // Dữ liệu đã đổi dưới chân form. Nạp bản mới vào bản nháp để người
        // dùng NHÌN THẤY nó trước khi quyết lưu lại — không merge hộ.
        toast.error(t("hq.products.options.values_changed_reload"));
        setTouched(false);
        void queryClient.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
        return;
      }
      if (
        err instanceof ApiError &&
        err.status === 409 &&
        err.body?.error === "OPTION_VALUE_IN_USE"
      ) {
        // BE returned the union of every blocked removal — show one dialog
        // listing all SKUs that would be force-deleted on confirm.
        setBlockingSkus(err.body.blocking_skus as Array<{ id: string; sku: string }>);
      }
      /* non-409 errors toasted by hook */
    }
  }

  async function handleForceConfirm() {
    // Force-delete every value whose id was dropped from the current draft.
    // We process sequentially so a mid-way failure leaves the rest editable.
    const originalIds = (option.values ?? []).map((v) => v.id);
    const stillPresent = new Set(draftRows.map((r) => r.id).filter(Boolean));
    const toForceDelete = originalIds.filter((id) => !stillPresent.has(id));

    for (const id of toForceDelete) {
      try {
        await forceDelete.mutateAsync(id);
      } catch (err) {
        setBlockingSkus(null);
        if (
          err instanceof ApiError &&
          err.status === 409 &&
          err.body?.error === "SKU_IN_MENU"
        ) {
          setBlockingMenus(err.body.blocking_menus as Array<{ id: string; name: string }>);
        }
        /* other errors toasted by hook */
        return;
      }
    }

    setBlockingSkus(null);
    // Retry the batch save now that the blocking SKUs are gone.
    await handleSave();
  }

  async function handleDeleteOption() {
    try {
      await deleteOption.mutateAsync(option.id);
    } catch {
      /* toast fired by hook */
      return;
    }
    onSaved();
  }

  const isBusy = syncValues.isPending || deleteOption.isPending || updateOption.isPending;

  return (
    <div className="p-5">
      <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-[240px_40px_1fr]">
        {/* Column 1: Attribute name */}
        <div className="flex flex-col gap-1.5">
          <span className="ml-1 text-[11px] font-bold tracking-tight text-slate-500 uppercase">
            {t("hq.products.options.attribute")}
          </span>
          <AttributeSelector
            value={localName}
            onSelect={(name) => {
              setTouched(true); // #2488 — đổi tên cũng là "đã gõ", đừng nạp đè
              setLocalName(name);
            }}
            commonAttributes={COMMON_ATTRIBUTES}
          />
        </div>

        {/* Column 2: Delete option */}
        <div className="flex flex-col items-center pt-7">
          {canDelete && (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="size-9 text-slate-400 hover:bg-destructive/5 hover:text-destructive"
              onClick={handleDeleteOption}
              disabled={isBusy}
            >
              {deleteOption.isPending ? (
                <Spinner className="size-4" />
              ) : (
                <Trash2 className="size-4" />
              )}
            </Button>
          )}
        </div>

        {/* Column 3: Values (local draft, batched on Save) */}
        <div className="flex flex-col gap-1.5">
          <span className="ml-1 text-[11px] font-bold tracking-tight text-slate-500 uppercase">
            {t("hq.products.options.value")}
          </span>
          <DndContext sensors={valueSensors} onDragEnd={handleValueDragEnd}>
            <SortableContext
              items={draftRows.map((r) => r._uid)}
              strategy={verticalListSortingStrategy}
            >
              <div className="flex flex-col gap-2">
                {draftRows.map((row) => (
                  <SortableValueRow
                    key={row._uid}
                    row={row}
                    disabled={isBusy}
                    onChange={(label) => updateRow(row._uid, label)}
                    onRemove={() => removeRow(row._uid)}
                  />
                ))}
                {/* #2488 — chữ gõ dở KHÔNG được bốc hơi khi bấm Lưu. Bản cũ là
                    input không kiểm soát chỉ commit bằng Enter (comment nói
                    "Enter or blur" nhưng không có onBlur nào) — gõ 翠ジン rồi bấm
                    thẳng 保存 thì chữ biến mất im lặng mà toast vẫn báo thành
                    công. Đo được trong lượt browser-test #2488. Giờ: Enter
                    commit · blur commit · handleSave fold phần còn dở. */}
                <div className="flex items-center pl-7">
                  <Input
                    placeholder={t("hq.products.options.add_value")}
                    className="h-10 text-sm focus-visible:ring-1 focus-visible:ring-primary/40"
                    disabled={isBusy}
                    value={pendingLabel}
                    onChange={(e) => {
                      setTouched(true);
                      setPendingLabel(e.target.value);
                    }}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" && !e.nativeEvent.isComposing) {
                        e.preventDefault();
                        commitPendingLabel();
                      }
                    }}
                    onBlur={commitPendingLabel}
                  />
                </div>
              </div>
            </SortableContext>
          </DndContext>
          {/* Save button — one round-trip for the whole option */}
          <div className="mt-2 flex justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleSave}
              disabled={isBusy}
              className="h-8 px-8 text-xs"
            >
              {syncValues.isPending ? <Spinner className="mr-1.5 size-3.5" /> : null}
              {t("common.save")}
            </Button>
          </div>
        </div>
      </div>

      {/* Conflict dialog: any removed value still in use by a SKU. Confirm
          force-deletes every blocker then retries the save. */}
      <AlertDialog
        open={blockingSkus !== null}
        onOpenChange={(open) => !open && setBlockingSkus(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("hq.products.options.delete_value_confirm_title")}
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div>
                <p className="mb-2">{t("hq.products.options.delete_value_confirm_desc")}</p>
                <ul className="space-y-0.5 font-mono text-sm">
                  {(blockingSkus ?? []).map((s) => (
                    <li key={s.id} className="text-muted-foreground">
                      {s.sku}
                    </li>
                  ))}
                </ul>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={forceDelete.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={forceDelete.isPending}
              onClick={(e) => {
                e.preventDefault();
                void handleForceConfirm();
              }}
            >
              {forceDelete.isPending ? <Spinner className="mr-1.5 size-3.5" /> : null}
              {t("hq.products.options.delete_value_confirm_action")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* SKU_IN_MENU: shown when a force-delete is blocked because the linked
          SKU is still assigned to a menu. */}
      <AlertDialog
        open={blockingMenus !== null}
        onOpenChange={(open) => !open && setBlockingMenus(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.options.sku_in_menu_title")}</AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div>
                <p className="mb-2">{t("hq.products.options.sku_in_menu_desc")}</p>
                <ul className="space-y-0.5 text-sm">
                  {(blockingMenus ?? []).map((m) => (
                    <li key={m.id} className="text-muted-foreground">
                      {m.name}
                    </li>
                  ))}
                </ul>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogAction onClick={() => setBlockingMenus(null)}>
              {t("common.close")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ---------------------------------------------------------------------------
//  SortableOptionRow — DnD wrapper around view/edit row
// ---------------------------------------------------------------------------

function SortableOptionRow({
  option,
  brandSlug,
  productId,
  editingOptionId,
  onEdit,
  onDone,
  onSaved,
  canDelete,
  onDirtyChange,
}: {
  option: ProductOption;
  brandSlug: string;
  productId: string;
  editingOptionId: string | null;
  onEdit: (id: string) => void;
  onDone: () => void;
  onSaved: () => void;
  canDelete: boolean;
  onDirtyChange?: (dirty: boolean) => void;
}) {
  const isEditing = editingOptionId === option.id;
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: option.id,
    disabled: isEditing,
  });

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "rounded-md border bg-card transition-colors",
        isDragging && "z-10 opacity-50 shadow-lg",
        isEditing && "bg-muted/20"
      )}
    >
      {isEditing ? (
        <OptionEditRow
          option={option}
          brandSlug={brandSlug}
          productId={productId}
          onDone={onDone}
          onSaved={onSaved}
          canDelete={canDelete}
          onDirtyChange={onDirtyChange}
        />
      ) : (
        <OptionViewRow
          option={option}
          onEdit={() => onEdit(option.id)}
          dragHandleProps={
            { ...listeners, ...attributes } as React.HTMLAttributes<HTMLButtonElement>
          }
        />
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
//  Main component
// ---------------------------------------------------------------------------

export function VariantsDisplay({
  brandSlug,
  productId,
  options,
  skus,
  productName,
}: VariantsDisplayProps) {
  const { t, locale } = useTranslation();
  const queryClient = useQueryClient();
  const hasVariants = options.length > 0;
  const canAddOption = options.length < 3;
  const canDeleteOption = options.length > 1 && skus.length === 0;

  const editEntryHref = skus[0]
    ? `/hq/${brandSlug}/products/${productId}/skus/${skus[0].id}`
    : null;

  // #2488 — số biến thể PHẢI khớp tích Descartes của các giá trị tuỳ chọn.
  // Xoá một SKU không xoá giá trị của nó, nên hai con số này lệch nhau được —
  // và trước đây màn hình hiện "2 chip / 1 dòng" mà không nói gì: người dùng
  // thấy thứ mình vừa tạo biến mất, gõ lại thì bị chặn vì giá trị vẫn sống.
  // `generate-combinations` bên backend sinh đúng tổ hợp còn thiếu và KHÔI
  // PHỤC SKU đã xoá mềm kèm giá cũ; hook này được import từ ngày đầu mà chưa
  // từng được gọi — thiếu đúng cái nút.
  const generateMissing = useGenerateSkuCombinations(brandSlug, productId);
  const expectedCombinations = useMemo(() => {
    if (options.length === 0) return 0;
    let product = 1;
    for (const option of options) {
      const count = option.values?.length ?? 0;
      if (count === 0) return 0; // backend 422s on an option with no values
      product *= count;
    }
    return product;
  }, [options]);
  const missingCombinations = Math.max(0, expectedCombinations - skus.length);

  // Inline new-option form
  const [showNewOption, setShowNewOption] = useState(false);
  const usedPositions = options.map((o) => o.position);

  // Which option row is in edit mode (only one at a time)
  const [editingOptionId, setEditingOptionId] = useState<string | null>(null);

  // Unsaved-changes guard for the currently open OptionEditRow. The child
  // bubbles its dirty flag here; if the user tries to switch to another row
  // (or close the form) while dirty, we stash the pending action and pop a
  // confirm dialog.
  const [editorDirty, setEditorDirty] = useState(false);
  // What the user wanted to do next: open another row, close the form, or
  // start the inline new-option form. `null` = dialog closed.
  const [pendingAction, setPendingAction] = useState<
    { kind: "edit"; optionId: string } | { kind: "close" } | { kind: "new" } | null
  >(null);

  function requestEdit(optionId: string) {
    if (editorDirty && editingOptionId !== optionId) {
      setPendingAction({ kind: "edit", optionId });
      return;
    }
    // #2488 — làm tươi dữ liệu NGAY khi mở form. `refetchOnWindowFocus` bị tắt
    // toàn app (query-provider), nên một tab mở sẵn sẽ hydrate form từ dữ liệu
    // cũ và lượt Lưu xoá giá trị tab khác vừa thêm. Invalidate ở đây kéo bản
    // mới về; form chưa bị gõ (touched=false) sẽ tự nạp lại qua khối resync.
    void queryClient.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
    setEditingOptionId(optionId);
  }

  function requestClose() {
    if (editorDirty) {
      setPendingAction({ kind: "close" });
      return;
    }
    setEditingOptionId(null);
  }

  function forceClose() {
    setEditorDirty(false);
    setEditingOptionId(null);
  }

  function requestShowNewOption() {
    if (editorDirty) {
      setPendingAction({ kind: "new" });
      return;
    }
    setShowNewOption(true);
  }

  function discardAndApplyPending() {
    const action = pendingAction;
    setPendingAction(null);
    setEditorDirty(false);
    if (!action) return;
    if (action.kind === "edit") setEditingOptionId(action.optionId);
    else if (action.kind === "close") setEditingOptionId(null);
    else if (action.kind === "new") {
      setEditingOptionId(null);
      setShowNewOption(true);
    }
  }

  // Browser-level guard: warn on tab close / hard navigation / reload when
  // the inline form has unsaved edits. Mirrors the pattern on /products/new.
  useEffect(() => {
    if (!editorDirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [editorDirty]);

  // Ordered options — local state for optimistic DnD reorder
  const sortedByPosition = useMemo(
    () => [...options].sort((a, b) => a.position - b.position),
    [options]
  );
  const [orderedOptions, setOrderedOptions] = useState<ProductOption[]>(sortedByPosition);

  // Sync when server data changes (after mutations refetch)
  useEffect(() => {
    setOrderedOptions([...options].sort((a, b) => a.position - b.position));
  }, [options]);

  // DnD for reordering option rows
  const updateOption = useUpdateProductOption(brandSlug, productId);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 8 } }));

  async function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIndex = orderedOptions.findIndex((o) => o.id === active.id);
    const newIndex = orderedOptions.findIndex((o) => o.id === over.id);
    if (oldIndex === -1 || newIndex === -1) return;

    const reordered = arrayMove(orderedOptions, oldIndex, newIndex);
    setOrderedOptions(reordered);

    // Sequential — the BE swaps the conflicting option atomically on the first
    // request, so subsequent calls for the same drag are no-ops. Sequential
    // also avoids the (product_id, position) unique constraint race condition.
    const changed = reordered.filter((opt, idx) => opt.position !== idx + 1);
    for (const opt of changed) {
      const newPos = (reordered.indexOf(opt) + 1) as 1 | 2 | 3;
      try {
        await updateOption.mutateAsync({ optionId: opt.id, data: { position: newPos } });
      } catch {
        // toast fired by hook; revert optimistic order on error
        setOrderedOptions([...options].sort((a, b) => a.position - b.position));
        break;
      }
    }
  }

  // Gallery dialog
  const [galleryOpenId, setGalleryOpenId] = useState<string | null>(null);
  const galleryQuery = useProductSku(brandSlug, galleryOpenId ?? "");
  const gallery = useMemo<ProductImageFile[]>(() => {
    const items = galleryQuery.data?.data?.gallery ?? [];
    return items.slice().sort((a, b) => a.sort_order - b.sort_order);
  }, [galleryQuery.data]);
  const syncImages = useSyncSkuImages(brandSlug, productId);

  async function handleGalleryConfirm(next: ProductImageFile[]) {
    if (!galleryOpenId) return;
    try {
      await syncImages.mutateAsync({
        skuId: galleryOpenId,
        fileIds: next.map((img) => img.id),
      });
    } catch {
      /* toast fired by hook */
    }
  }

  return (
    <Card className="p-4" data-slot="variants-display">
      {/* Header */}
      <div className="mb-4 flex items-center justify-between gap-2">
        <div className="text-sm font-semibold">{t("hq.products.variants.title")}</div>
        <div className="flex shrink-0 items-center gap-1.5">
          {editEntryHref && hasVariants && (
            <Button
              asChild
              variant="outline"
              size="sm"
              className="h-7 shrink-0 gap-1.5 text-xs whitespace-nowrap"
            >
              <Link href={`${editEntryHref}?new=1`}>
                <Plus className="size-3.5" />
                {t("hq.products.sku_edit.sidebar.new_sku")}
              </Link>
            </Button>
          )}
          {editEntryHref && (
            <Button
              asChild
              variant="outline"
              size="sm"
              className="h-7 shrink-0 gap-1.5 text-xs whitespace-nowrap"
            >
              <Link href={editEntryHref}>
                <Pencil className="size-3.5" />
                {t("hq.products.variants.edit_variant")}
              </Link>
            </Button>
          )}
        </div>
      </div>

      {!hasVariants ? (
        <div className="flex flex-col gap-3">
          {skus.length > 0 ? (
            <div className="flex flex-col gap-2">
              <div className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                {t("hq.products.simple_preview.list_title")}
              </div>
              <div className="overflow-hidden rounded-md border">
                <Table>
                  <TableHeader className="bg-muted/50">
                    <TableRow>
                      <TableHead>{t("hq.products.simple_preview.name")}</TableHead>
                      <TableHead className="w-40">{t("hq.products.variants.col.sku")}</TableHead>
                      <TableHead className="w-32 text-right">
                        {t("hq.products.variants.col.price")}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {skus.map((sku) => (
                      <TableRow key={sku.id} className={!sku.is_active ? "opacity-50" : ""}>
                        <TableCell className="text-xs font-medium">
                          <Link
                            href={`/hq/${brandSlug}/products/${productId}/skus/${sku.id}`}
                            className="text-primary hover:underline"
                          >
                            {sku.name || productName || "—"}
                          </Link>
                        </TableCell>
                        <TableCell>
                          <code className="text-xs text-muted-foreground">{sku.sku ?? "—"}</code>
                        </TableCell>
                        <TableCell className="text-right font-mono text-xs">
                          {formatCurrency(sku.selling_price, locale)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          ) : (
            <div className="rounded-md border border-dashed bg-muted/20 py-8 text-center">
              <p className="text-xs text-muted-foreground">{t("hq.products.variants.simple")}</p>
            </div>
          )}

          {/* Upgrade a simple product into a variant product by adding its
              first option. BE expandOption assigns the new option's default
              value to every existing SKU, so the default SKU stays intact
              and any extra combinations are auto-generated. */}
          {showNewOption ? (
            <NewOptionRow
              brandSlug={brandSlug}
              productId={productId}
              usedPositions={usedPositions}
              onDone={() => setShowNewOption(false)}
            />
          ) : (
            <div className="flex justify-end">
              <button
                type="button"
                onClick={requestShowNewOption}
                className="flex items-center gap-1.5 text-sm text-primary hover:underline focus:outline-none"
              >
                <Plus className="size-4" />
                {t("hq.products.variants.add_option")}
              </button>
            </div>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          {/* Options list with drag-and-drop reorder */}
          <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <SortableContext
              items={orderedOptions.map((o) => o.id)}
              strategy={verticalListSortingStrategy}
            >
              <div className="flex flex-col gap-2">
                {orderedOptions.map((option) => (
                  <SortableOptionRow
                    key={option.id}
                    option={option}
                    brandSlug={brandSlug}
                    productId={productId}
                    editingOptionId={editingOptionId}
                    onEdit={requestEdit}
                    onDone={requestClose}
                    onSaved={forceClose}
                    canDelete={canDeleteOption}
                    onDirtyChange={setEditorDirty}
                  />
                ))}
              </div>
            </SortableContext>
          </DndContext>

          {/* Inline new-option form or add button */}
          {showNewOption ? (
            <NewOptionRow
              brandSlug={brandSlug}
              productId={productId}
              usedPositions={usedPositions}
              onDone={() => setShowNewOption(false)}
            />
          ) : (
            canAddOption && (
              <div className="flex justify-end">
                <button
                  type="button"
                  onClick={requestShowNewOption}
                  className="flex items-center gap-1.5 text-sm text-primary hover:underline focus:outline-none"
                >
                  <Plus className="size-4" />
                  {t("hq.products.variants.add_option")}
                </button>
              </div>
            )
          )}

          {/* #2488 — nói ra sự chênh lệch thay vì để hai con số tự mâu thuẫn */}
          {missingCombinations > 0 && (
            <div
              data-slot="missing-combinations-banner"
              className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
              <span>
                {t("hq.products.variants.missing_combos", {
                  values: expectedCombinations,
                  skus: skus.length,
                })}
              </span>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={generateMissing.isPending}
                onClick={() => generateMissing.mutate()}
              >
                {generateMissing.isPending ? <Spinner className="mr-1.5 size-3.5" /> : null}
                {t("hq.products.variants.generate_missing")}
              </Button>
            </div>
          )}

          {/* SKU list */}
          {skus.length > 0 && (
            <div className="flex flex-col gap-2">
              <div className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                {t("hq.products.variants.list_title", { n: skus.length })}
              </div>
              <div className="overflow-hidden rounded-md border">
                <Table>
                  <TableHeader className="bg-muted/50">
                    <TableRow>
                      <TableHead className="w-12 text-center">
                        {t("hq.products.variants.col.no")}
                      </TableHead>
                      <TableHead className="w-14 text-center">
                        {t("hq.products.col.image")}
                      </TableHead>
                      <TableHead>{t("hq.products.variants.col.variant")}</TableHead>
                      <TableHead className="w-40">{t("hq.products.variants.col.sku")}</TableHead>
                      <TableHead className="w-32 text-right">
                        {t("hq.products.variants.col.price")}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {skus.map((sku, index) => {
                      const labels = skuVariantLabels(sku);
                      const href = `/hq/${brandSlug}/products/${productId}/skus/${sku.id}`;
                      return (
                        <TableRow key={sku.id} className={!sku.is_active ? "opacity-50" : ""}>
                          <TableCell className="text-center text-xs text-muted-foreground tabular-nums">
                            {index + 1}
                          </TableCell>
                          <TableCell>
                            <SkuThumb
                              sku={sku}
                              alt={labels.join(" / ")}
                              ariaLabel={t("hq.products.sku_edit.gallery.dialog_title")}
                              onClick={() => setGalleryOpenId(sku.id)}
                            />
                          </TableCell>
                          <TableCell>
                            <Link href={href} className="flex flex-wrap gap-1 hover:underline">
                              {labels.length === 0 ? (
                                <span className="text-xs text-primary">
                                  {t("hq.products.variants.col.default")}
                                </span>
                              ) : (
                                labels.map((label, i) => (
                                  <Badge
                                    key={`${sku.id}-${i}`}
                                    variant="outline"
                                    className="text-[11px] font-medium"
                                  >
                                    {label}
                                  </Badge>
                                ))
                              )}
                            </Link>
                          </TableCell>
                          <TableCell>
                            <code className="text-xs text-muted-foreground">{sku.sku ?? "—"}</code>
                          </TableCell>
                          <TableCell className="text-right font-mono text-xs">
                            {formatCurrency(sku.selling_price, locale)}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}
        </div>
      )}

      <SkuGalleryDialog
        open={galleryOpenId !== null}
        onOpenChange={(open) => !open && setGalleryOpenId(null)}
        initial={gallery}
        loading={galleryQuery.isLoading}
        onConfirm={handleGalleryConfirm}
      />

      {/* Unsaved-changes confirmation — fires when the user tries to switch
          to another option / close the form / open the new-option form
          without clicking "Xong" first. */}
      <AlertDialog
        open={pendingAction !== null}
        onOpenChange={(open) => !open && setPendingAction(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("hq.products.unsaved.continue_editing")}</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={(e) => {
                e.preventDefault();
                discardAndApplyPending();
              }}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}
