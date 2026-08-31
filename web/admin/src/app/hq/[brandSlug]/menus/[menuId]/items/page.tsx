"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { notFound, useParams, useRouter } from "next/navigation";
import { ApiError } from "@/lib/api";
import {
  ArrowLeft,
  ArrowUpDown,
  Check,
  CheckCircle2,
  Clock,
  EllipsisVertical,
  FolderOpen,
  GripVertical,
  ImageIcon,
  Maximize2,
  Package,
  PauseCircle,
  Pencil,
  PlayCircle,
  Plus,
  Power,
  Save,
  Search,
  Send,
  Star,
  Trash2,
  UtensilsCrossed,
  X,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  closestCenter,
  closestCorners,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type CollisionDetection,
  type DragEndEvent,
  type DragStartEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";

import {
  Alert,
  AlertDescription,
  AlertTitle,
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Skeleton,
  Spinner,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Textarea,
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import { StatusBadge } from "@godxjp/ui";

import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  useActivateMenu,
  useApproveMenu,
  useDeactivateMenu,
  useDeleteMenu,
  useMenu,
  useRejectMenu,
  useSubmitMenu,
  useSyncMenuLayout,
  useUpdateMenu,
  useUpdateMenuProductTaxType,
  useUpdateMenuSectionTaxType,
  useUpdateMenuTaxType,
  useUpdateMenuTimeout,
} from "@/hooks/api/use-menus";
import { HqSetTimeoutDialog } from "./components/hq-set-timeout-dialog";
import { useAllProducts, useProduct } from "@/hooks/api/use-products";
import { useCategoryLookup } from "@/hooks/api/use-categories";
import { useProductTypeLookup } from "@/hooks/api/use-product-types";
import {
  useMenuSchedules,
  useCreateMenuSchedule,
  useUpdateMenuSchedule,
  useDeleteMenuSchedule,
  useReorderMenuSchedules,
} from "@/hooks/api/use-menu-schedules";
import type { Product } from "@/services/product-service";
import { menuSectionService } from "@/services/menu-section-service";
import type { CategoryLookupItem } from "@/services/category-service";
import type { TaxTypeLookupItem } from "@/services/tax-type-service";
import { useTaxTypeLookup } from "@/hooks/api/use-tax-types";
import type { MenuSchedule } from "@/types/models/MenuSchedule";
import {
  formValuesToPayload,
  findOverlappingSchedules,
  type ScheduleSubmitPayload,
} from "@/lib/menuSchedule";
import { useTranslation } from "@/providers/app-provider";
import { formatPriceAmount } from "@/lib/currency";
import { ImageLightbox } from "@/components/shared/image-lightbox";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import {
  ScheduleFormDialog,
  type FormValues as ScheduleFormValues,
} from "../schedules/components/schedule-form-dialog";

// =========================================================================
//  Types
// =========================================================================

interface LocalSection {
  id: string;
  name: string;
  translations: Record<string, string>;
  productIds: string[];
  /**
   * #1187 — this section's items appear in the customer "featured" carousel.
   * Replaces customer-web's old trick of scanning the section NAME for a
   * handful of hard-coded words and star/fire glyphs: renaming a section used
   * to empty the carousel silently, and a shop naming sections in any other
   * language could never fill it.
   */
  isFeatured: boolean;
}

function sectionTranslations(
  translations?: Record<string, { name?: string | null }>,
  fallback = ""
): Record<string, string> {
  const values = emptyLocaleMap();
  for (const locale of ["ja", "en", "vi"] as const) {
    values[locale] = translations?.[locale]?.name ?? "";
  }
  if (!Object.values(values).some((value) => value.trim())) values[DEFAULT_LOCALE] = fallback;
  return values;
}

function canonicalSectionName(translations: Record<string, string>, fallback = ""): string {
  if (!Object.values(translations).some((value) => value.trim())) return fallback.trim();
  return fillLocalesFallback(translations)[DEFAULT_LOCALE].trim();
}

type ActiveDrag =
  | { kind: "product"; product: Product }
  | { kind: "item"; sectionId: string; product: Product }
  | { kind: "section-row"; sectionId: string; sectionName: string }
  | null;

// =========================================================================
//  Draggable Product Card (left panel)
// =========================================================================

function DraggableProductCard({ product, onClick }: { product: Product; onClick: () => void }) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: `product:${product.id}`,
    data: { kind: "product", product },
  });

  return (
    <div
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      onClick={onClick}
      className={`flex cursor-grab items-center gap-2.5 rounded-md border bg-card p-2 transition-all select-none hover:border-primary/40 hover:shadow-sm active:cursor-grabbing ${
        isDragging ? "opacity-40" : ""
      }`}
    >
      <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        {product.image_url ? (
          <Image
            src={product.image_url}
            alt={product.name}
            width={32}
            height={32}
            className="h-full w-full object-cover"
          />
        ) : (
          <ImageIcon className="size-3.5 text-muted-foreground" />
        )}
      </div>
      <div className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm font-medium">
          {product.name || t("hq.menus.items.untitled")}
        </span>
        <span className="text-[11px] text-muted-foreground">
          {t("hq.menus.items.variants_count", { n: product.active_skus_count ?? 0 })}
        </span>
      </div>
      <GripVertical className="size-3.5 shrink-0 text-muted-foreground/50" />
    </div>
  );
}

// =========================================================================
//  Sortable Menu Item (right panel)
// =========================================================================

function SortableMenuItem({
  sectionId,
  product,
  onRemove,
  taxControl,
}: {
  sectionId: string;
  product: Product;
  onRemove: () => void;
  /**
   * The per-item tax override (#1099). A slot rather than props so the row
   * stays a layout concern and the page owns the mutation + lookup.
   */
  taxControl?: React.ReactNode;
}) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: `item:${sectionId}:${product.id}`,
    data: { kind: "item", sectionId, product },
  });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`flex items-center gap-2 rounded-md border bg-card p-2 ${
        isDragging ? "opacity-40" : ""
      }`}
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        className="cursor-grab touch-none text-muted-foreground hover:text-foreground active:cursor-grabbing"
        aria-label={t("hq.menus.items.drag_aria")}
      >
        <GripVertical className="size-3.5" />
      </button>
      <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        {product.image_url ? (
          <Image
            src={product.image_url}
            alt={product.name}
            width={32}
            height={32}
            className="h-full w-full object-cover"
          />
        ) : (
          <ImageIcon className="size-3.5 text-muted-foreground" />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium">
          {product.name || t("hq.menus.items.untitled")}
        </span>
        <span className="text-[11px] text-muted-foreground">
          {t("hq.menus.items.variants_count", { n: product.active_skus_count ?? 0 })}
        </span>
      </div>
      {taxControl}
      <Button
        variant="ghost"
        size="icon"
        className="size-7 shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
        onClick={onRemove}
      >
        <Trash2 className="size-3" />
      </Button>
    </div>
  );
}

/**
 * Per-item consumption-tax override (#1099).
 *
 * A tax type is ONE rate, so "8% on takeaway" is expressed by assigning the
 * reduced type to the TAKEAWAY MENU's items — the resolver's tier 1. Without
 * this control the endpoint existed but nothing could reach it, so a shop that
 * split its takeaway menu still billed every line at the standard rate.
 *
 * `null` means inherit (product → branch default → brand default), which is what
 * the overwhelming majority of lines should stay on; the label shows the rate
 * that inheritance currently lands on so the operator can see the consequence of
 * leaving it alone.
 */
function MenuItemTaxSelect({
  value,
  taxTypes,
  disabled,
  onChange,
}: {
  value: string | null;
  taxTypes: TaxTypeLookupItem[];
  disabled?: boolean;
  onChange: (taxTypeId: string | null) => void;
}) {
  const { t } = useTranslation();
  const INHERIT = "__inherit__";

  return (
    <Select
      value={value ?? INHERIT}
      disabled={disabled}
      onValueChange={(next) => onChange(next === INHERIT ? null : next)}
    >
      <SelectTrigger
        size="sm"
        className="h-7 w-[8.5rem] shrink-0 text-[11px]"
        aria-label={t("hq.menus.items.tax.aria")}
      >
        <SelectValue placeholder={t("hq.menus.items.tax.inherit")} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={INHERIT}>{t("hq.menus.items.tax.inherit")}</SelectItem>
        {taxTypes.map((tt) => (
          <SelectItem key={tt.id} value={tt.id}>
            {tt.name} ({tt.rate}%)
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * #1218 tier 2 — the tax type for one section WITHIN this menu. Same shape as
 * MenuItemTaxSelect, but "inherit" means the MENU here, not the product: this
 * sits directly below the per-item override and above the whole-menu value.
 */
function MenuSectionTaxSelect({
  value,
  taxTypes,
  disabled,
  onChange,
}: {
  value: string | null;
  taxTypes: TaxTypeLookupItem[];
  disabled?: boolean;
  onChange: (taxTypeId: string | null) => void;
}) {
  const { t } = useTranslation();
  const INHERIT = "__inherit__";

  return (
    <Select
      value={value ?? INHERIT}
      disabled={disabled}
      onValueChange={(next) => onChange(next === INHERIT ? null : next)}
    >
      <SelectTrigger
        size="sm"
        className="h-7 w-[9.5rem] shrink-0 text-[11px]"
        aria-label={t("hq.menus.sections.tax.aria")}
      >
        <SelectValue placeholder={t("hq.menus.sections.tax.inherit")} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={INHERIT}>{t("hq.menus.sections.tax.inherit")}</SelectItem>
        {taxTypes.map((tt) => (
          <SelectItem key={tt.id} value={tt.id}>
            {tt.name} ({tt.rate}%)
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * #1218 tier 3 — the tax type for the WHOLE menu, the top of the three tiers on
 * this page (menu → section → item, each overriding the one above it).
 *
 * This is the control that makes "the takeaway menu is 8%" one action instead of
 * an edit per line. Slice 3 shipped the endpoint, the service call, the hook and
 * the copy but never rendered it, so every section read "inherit from the menu"
 * with no way to give the menu a value.
 *
 * Unlike the two tiers below it, this one carries its hint inline: the menu beats
 * the PRODUCT by ruling, so it re-rates catalog-exempt items too, and the only
 * escape is a per-item override. That consequence is invisible from the control
 * itself, so it does not belong behind a hover.
 */
function MenuTaxSelect({
  value,
  taxTypes,
  disabled,
  onChange,
}: {
  value: string | null;
  taxTypes: TaxTypeLookupItem[];
  disabled?: boolean;
  onChange: (taxTypeId: string | null) => void;
}) {
  const { t } = useTranslation();
  const INHERIT = "__inherit__";

  return (
    <div className="mb-3 rounded-md border bg-muted/30 px-3 py-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-xs font-medium text-foreground">{t("hq.menus.tax.label")}</span>
        <Select
          value={value ?? INHERIT}
          disabled={disabled}
          onValueChange={(next) => onChange(next === INHERIT ? null : next)}
        >
          <SelectTrigger
            size="sm"
            className="h-7 w-[11rem] shrink-0 text-[11px]"
            aria-label={t("hq.menus.tax.aria")}
          >
            <SelectValue placeholder={t("hq.menus.tax.inherit")} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={INHERIT}>{t("hq.menus.tax.inherit")}</SelectItem>
            {taxTypes.map((tt) => (
              <SelectItem key={tt.id} value={tt.id}>
                {tt.name} ({tt.rate}%)
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      {value !== null && (
        <p className="mt-1.5 text-[11px] leading-snug text-muted-foreground">
          {t("hq.menus.tax.hint")}
        </p>
      )}
    </div>
  );
}

// =========================================================================
//  Section Panel (right panel, droppable container)
// =========================================================================

function SectionPanel({
  section,
  productMap,
  onRemoveItem,
  onRenameSection,
  onToggleFeatured,
  onRemoveSection,
  dragHandleButtonProps,
  isCollapsed = false,
  renderTaxControl,
  renderSectionTaxControl,
}: {
  section: LocalSection;
  productMap: Map<string, Product>;
  onRemoveItem: (productId: string) => void;
  onRenameSection: (translations: Record<string, string>) => void;
  onToggleFeatured: () => void;
  onRemoveSection: () => void;
  dragHandleButtonProps?: React.HTMLAttributes<HTMLButtonElement>;
  isCollapsed?: boolean;
  renderTaxControl?: (productId: string) => React.ReactNode;
  /** #1218 tier 2 — tax type for this section IN THIS MENU. */
  renderSectionTaxControl?: (sectionId: string) => React.ReactNode;
}) {
  const { t, locale } = useTranslation();
  const [editing, setEditing] = useState(false);
  const [editName, setEditName] = useState<Record<string, string>>(section.translations);

  const { setNodeRef, isOver } = useDroppable({
    id: `section:${section.id}`,
    data: { kind: "section", sectionId: section.id },
  });

  const sortableIds = useMemo(
    () => section.productIds.map((pid) => `item:${section.id}:${pid}`),
    [section.id, section.productIds]
  );

  return (
    <div className="mb-4 flex flex-col gap-2">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          {!editing && dragHandleButtonProps && (
            <button
              type="button"
              {...dragHandleButtonProps}
              className="cursor-grab touch-none text-muted-foreground/40 hover:text-muted-foreground active:cursor-grabbing"
              aria-label={t("hq.menus.items.drag_aria")}
            >
              <GripVertical className="size-3.5" />
            </button>
          )}
          <FolderOpen className="size-3.5 text-muted-foreground" />
          {editing ? (
            <div className="flex items-center gap-1">
              <Input
                translatable
                value={editName as TranslatableValue}
                onChange={(value) => setEditName(value as Record<string, string>)}
                className="h-7 w-64 text-sm"
                autoFocus
              />
              <Button
                variant="ghost"
                size="icon"
                className="size-6"
                onClick={() => {
                  onRenameSection(editName);
                  setEditing(false);
                }}
              >
                <Check className="size-3" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                className="size-6"
                onClick={() => {
                  setEditName(section.translations);
                  setEditing(false);
                }}
              >
                <X className="size-3" />
              </Button>
            </div>
          ) : (
            <span className="text-sm font-medium text-foreground">
              {section.translations[locale] || section.name}
            </span>
          )}
          <Badge variant="outline" className="text-[10px] font-medium">
            {section.productIds.length}
          </Badge>
        </div>
        {!editing && (
          <div className="flex items-center gap-0.5">
            {/* #1218 tier 2 — this section's tax type FOR THIS MENU. It lives on
                the menu↔section pivot, so the same section keeps its own value
                in every other menu that shows it. */}
            {renderSectionTaxControl?.(section.id)}
            {/* #1187 — the shop's own switch for the customer featured
                carousel. Replaces the naming trick that used to control it. */}
            <Button
              variant="ghost"
              size="icon"
              role="switch"
              aria-checked={section.isFeatured}
              aria-label={t("hq.menus.items.featured_toggle")}
              title={t("hq.menus.items.featured_hint")}
              className={
                section.isFeatured
                  ? "size-6 text-amber-500 hover:text-amber-600"
                  : "size-6 text-muted-foreground hover:text-foreground"
              }
              onClick={onToggleFeatured}
            >
              <Star className={section.isFeatured ? "size-3 fill-current" : "size-3"} />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-6 text-muted-foreground hover:text-foreground"
              onClick={() => {
                setEditName(section.translations);
                setEditing(true);
              }}
            >
              <Pencil className="size-3" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-6 text-muted-foreground hover:text-destructive"
              onClick={onRemoveSection}
            >
              <Trash2 className="size-3" />
            </Button>
          </div>
        )}
      </div>

      {isCollapsed ? (
        <div className="flex h-8 items-center rounded-lg border border-dashed border-border bg-muted/20 px-3">
          <span className="text-xs text-muted-foreground">
            {section.productIds.length} {t("hq.menus.items.products_hidden")}
          </span>
        </div>
      ) : (
        <div
          ref={setNodeRef}
          className={`flex min-h-20 flex-col gap-1.5 rounded-lg border border-dashed p-2.5 transition-colors ${
            isOver ? "border-primary bg-primary/5" : "border-border bg-muted/20"
          }`}
        >
          <SortableContext items={sortableIds} strategy={verticalListSortingStrategy}>
            {section.productIds.map((pid) => {
              const product = productMap.get(pid);
              if (!product) return null;
              return (
                <SortableMenuItem
                  key={pid}
                  sectionId={section.id}
                  product={product}
                  onRemove={() => onRemoveItem(pid)}
                  taxControl={renderTaxControl?.(pid)}
                />
              );
            })}
          </SortableContext>
          {section.productIds.length === 0 && (
            <div className="flex items-center justify-center py-4 text-xs text-muted-foreground">
              {t("hq.menus.items.drag_here")}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// =========================================================================
//  Sortable Section Panel (wrapper for section-level drag-to-reorder)
// =========================================================================

function SortableSectionPanel({
  section,
  isCollapsed,
  ...props
}: {
  section: LocalSection;
  productMap: Map<string, Product>;
  onRemoveItem: (productId: string) => void;
  onRenameSection: (translations: Record<string, string>) => void;
  onToggleFeatured: () => void;
  onRemoveSection: () => void;
  isCollapsed?: boolean;
  renderTaxControl?: (productId: string) => React.ReactNode;
  renderSectionTaxControl?: (sectionId: string) => React.ReactNode;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: `sort-section:${section.id}`,
    data: { kind: "section-row", sectionId: section.id, sectionName: section.name },
  });

  return (
    <div
      ref={setNodeRef}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.4 : undefined,
      }}
    >
      <SectionPanel
        section={section}
        dragHandleButtonProps={{ ...attributes, ...listeners }}
        isCollapsed={isCollapsed}
        {...props}
      />
    </div>
  );
}

// =========================================================================
//  Product Detail Dialog
// =========================================================================

function VariantThumbnail({
  src,
  alt,
  onZoom,
}: {
  src: string | null | undefined;
  alt: string;
  onZoom: (src: string, alt: string) => void;
}) {
  const { t } = useTranslation();

  if (!src) {
    return (
      <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        <ImageIcon className="size-3.5 text-muted-foreground" />
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={() => onZoom(src, alt)}
      aria-label={t("hq.menus.items.enlarge_image")}
      className="group relative size-10 shrink-0 cursor-zoom-in overflow-hidden rounded bg-muted"
    >
      <Image src={src} alt={alt} width={40} height={40} className="h-full w-full object-cover" />
      <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/40">
        <Maximize2 className="size-3.5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
      </span>
    </button>
  );
}

function ProductDetailDialog({
  brandSlug,
  productId,
  open,
  onOpenChange,
}: {
  brandSlug: string;
  productId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t, locale } = useTranslation();
  const { data, isLoading } = useProduct(brandSlug, productId);
  const product = data?.data;

  const [lightbox, setLightbox] = useState<{ src: string; alt: string } | null>(null);
  const handleZoom = useCallback((src: string, alt: string) => {
    setLightbox({ src, alt });
  }, []);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        aria-describedby={undefined}
        className="sm:max-w-lg"
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle className="text-base">
            {product?.name || t("hq.menus.items.product_detail")}
          </DialogTitle>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : product ? (
          <div className="flex flex-col gap-4 text-sm">
            <div className="grid grid-cols-2 gap-x-4 gap-y-2">
              <div>
                <span className="text-xs text-muted-foreground">{t("common.status")}</span>
                <div className="mt-0.5">
                  <StatusBadge status={product.status} />
                </div>
              </div>
              <div>
                <span className="text-xs text-muted-foreground">{t("common.type")}</span>
                <p className="mt-0.5 font-medium">{product.productType?.name ?? "—"}</p>
              </div>
              {product.categories && product.categories.length > 0 && (
                <div className="col-span-2">
                  <span className="text-xs text-muted-foreground">
                    {t("hq.products.sidebar.menu_categories")}
                  </span>
                  <div className="mt-0.5 flex flex-wrap gap-1">
                    {product.categories.map((c) => (
                      <Badge key={c.id} variant="outline" className="h-5 text-[10px]">
                        {c.name}
                      </Badge>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {product.options && product.options.length > 0 && (
              <div>
                <span className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                  {t("hq.menus.items.options")}
                </span>
                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1">
                  {product.options.map((opt) => (
                    <div key={opt.id} className="text-xs">
                      <span className="font-medium">{opt.name}:</span>{" "}
                      <span className="text-muted-foreground">
                        {opt.values?.map((v) => v.label).join(", ") ?? "—"}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {product.skus && product.skus.length > 0 && (
              <div>
                <span className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                  {t("hq.menus.items.variants_active")}
                </span>
                <div className="mt-1.5 max-h-52 divide-y overflow-y-auto rounded-md border">
                  {product.skus.map((sku) => {
                    const combo = [sku.option_value1, sku.option_value2, sku.option_value3]
                      .filter(Boolean)
                      .map((v) => v!.label)
                      .join(" / ");

                    const isSimpleSku = !product.options?.length && product.skus!.length === 1;
                    const label =
                      combo ||
                      sku.name ||
                      (isSimpleSku ? product.name : "") ||
                      t("hq.menus.items.default_variant");
                    const skuImage =
                      sku.image_url ??
                      sku.gallery?.[0]?.url ??
                      (isSimpleSku ? (product.image_url ?? null) : null);

                    return (
                      <div
                        key={sku.id}
                        className={`flex items-center gap-2.5 px-3 py-2.5 text-xs ${
                          !sku.is_active ? "opacity-50" : ""
                        }`}
                      >
                        <VariantThumbnail src={skuImage} alt={label} onZoom={handleZoom} />
                        <div className="min-w-0 flex-1">
                          <span className="font-medium">{label}</span>
                          {sku.sku && (
                            <code className="ml-1.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                              {sku.sku}
                            </code>
                          )}
                        </div>
                        <span className="shrink-0 font-semibold tabular-nums">
                          {formatPriceAmount(sku.selling_price, locale)}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        ) : (
          <div className="py-8 text-center text-sm text-muted-foreground">
            {t("hq.menus.items.product_not_found")}
          </div>
        )}
      </DialogContent>

      <ImageLightbox
        src={lightbox?.src ?? null}
        alt={lightbox?.alt ?? ""}
        open={!!lightbox}
        onOpenChange={(o) => {
          if (!o) setLightbox(null);
        }}
      />
    </Dialog>
  );
}

// =========================================================================
//  Add Section — inline input
// =========================================================================

function AddSectionInline({
  usedNames,
  onAdd,
}: {
  usedNames: Set<string>;
  onAdd: (translations: Record<string, string>) => void;
}) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState<Record<string, string>>(() => emptyLocaleMap());

  const trimmed = canonicalSectionName(name);
  const lowerName = trimmed.toLowerCase();
  const isDuplicate = Boolean(trimmed) && usedNames.has(lowerName);

  const commit = () => {
    if (!trimmed) return;
    if (isDuplicate) {
      toast.error(t("hq.menus.items.section_duplicate", { name: trimmed }));
      return;
    }
    onAdd(fillLocalesFallback(name));
    setName(emptyLocaleMap());
    setEditing(false);
  };

  const cancel = () => {
    setName(emptyLocaleMap());
    setEditing(false);
  };

  if (!editing) {
    return (
      <Button
        variant="outline"
        size="sm"
        className="h-9 w-full gap-2 border-dashed text-xs font-medium text-primary hover:bg-primary/5"
        onClick={() => setEditing(true)}
      >
        <Plus className="size-3.5" />
        {t("hq.menus.items.add_section")}
      </Button>
    );
  }

  return (
    <div className="flex items-center gap-1.5 rounded-md border border-dashed border-primary/60 bg-primary/5 p-1.5">
      <FolderOpen className="ml-1 size-3.5 shrink-0 text-muted-foreground" />
      <Input
        translatable
        value={name as TranslatableValue}
        onChange={(value) => setName(value as Record<string, string>)}
        placeholder={t("hq.menus.items.section_placeholder")}
        autoFocus
        className="h-7 text-sm"
      />
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="size-7 shrink-0 text-primary hover:bg-primary/10"
        onClick={commit}
        disabled={!trimmed || isDuplicate}
      >
        <Check className="size-3.5" />
      </Button>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="size-7 shrink-0 text-muted-foreground"
        onClick={cancel}
      >
        <X className="size-3.5" />
      </Button>
    </div>
  );
}

// =========================================================================
//  Schedules section (embedded at top of page)
// =========================================================================

function SchedulesSection({ brandSlug, menuId }: { brandSlug: string; menuId: string }) {
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useMenuSchedules(brandSlug, menuId);
  const schedules: MenuSchedule[] = data?.data ?? [];

  const createMut = useCreateMenuSchedule(brandSlug, menuId);
  const updateMut = useUpdateMenuSchedule(brandSlug, menuId);
  const deleteMut = useDeleteMenuSchedule(brandSlug, menuId);
  const reorderMut = useReorderMenuSchedules(brandSlug, menuId);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<MenuSchedule | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<MenuSchedule | null>(null);
  // Overlap warning (TC-MSCH-106): holds the pending payload + the schedules it
  // collides with until the user confirms or cancels.
  const [overlapPending, setOverlapPending] = useState<{
    payload: ScheduleSubmitPayload;
    overlaps: MenuSchedule[];
  } | null>(null);

  // ── Reorder state (mirrors menus/page.tsx pattern) ──────────────────────
  const [isReordering, setIsReordering] = useState(false);
  const [reorderItems, setReorderItems] = useState<MenuSchedule[]>([]);
  const [hasReorderChanged, setHasReorderChanged] = useState(false);
  const [draggingIndex, setDraggingIndex] = useState<number | null>(null);
  const dragItemIndex = useRef<number | null>(null);
  const dragOverItemIndex = useRef<number | null>(null);

  function enterReorderMode() {
    setReorderItems([...schedules].sort((a, b) => a.priority - b.priority));
    setHasReorderChanged(false);
    setIsReordering(true);
  }

  function exitReorderMode() {
    setIsReordering(false);
    setHasReorderChanged(false);
    setDraggingIndex(null);
  }

  function onDragStart(e: React.DragEvent<HTMLDivElement>, index: number) {
    dragItemIndex.current = index;
    setDraggingIndex(index);
    e.dataTransfer.effectAllowed = "move";
    setTimeout(() => {
      (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]")!.style.opacity = "0.4";
    }, 0);
  }

  function onDragEnter(_e: React.DragEvent<HTMLDivElement>, index: number) {
    dragOverItemIndex.current = index;
  }

  function onDragOver(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
  }

  function onDrop(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    const from = dragItemIndex.current;
    const to = dragOverItemIndex.current;
    if (from !== null && to !== null && from !== to) {
      const updated = [...reorderItems];
      const [removed] = updated.splice(from, 1);
      updated.splice(to, 0, removed);
      setReorderItems(updated);
      setHasReorderChanged(true);
    }
    const row = (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]");
    if (row) row.style.opacity = "1";
    dragItemIndex.current = null;
    dragOverItemIndex.current = null;
    setDraggingIndex(null);
  }

  function onDragEnd(e: React.DragEvent<HTMLDivElement>) {
    const row = (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]");
    if (row) row.style.opacity = "1";
    dragItemIndex.current = null;
    dragOverItemIndex.current = null;
    setDraggingIndex(null);
  }

  async function handleSaveOrder() {
    await reorderMut.mutateAsync(reorderItems.map((s) => s.id));
    setHasReorderChanged(false);
    setIsReordering(false);
  }

  async function commitSubmit(payload: ScheduleSubmitPayload) {
    if (editTarget) {
      await updateMut.mutateAsync({ scheduleId: editTarget.id, data: payload });
    } else {
      await createMut.mutateAsync(payload);
    }
    setDialogOpen(false);
    setEditTarget(null);
    setOverlapPending(null);
  }

  async function handleSubmit(values: ScheduleFormValues) {
    const payload = formValuesToPayload(values);

    // Warn (don't block) when the new window collides with an existing one on a
    // shared day + overlapping time range (TC-MSCH-106).
    const overlaps = findOverlappingSchedules(payload, schedules, editTarget?.id);
    if (overlaps.length > 0) {
      setOverlapPending({ payload, overlaps });
      return;
    }

    await commitSubmit(payload);
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    await deleteMut.mutateAsync(deleteTarget.id);
    setDeleteTarget(null);
  }

  const isSubmitting = createMut.isPending || updateMut.isPending;

  return (
    <div className="px-4 py-3">
      {/* Section header */}
      <div className="mb-2.5 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold">{t("hq.menus.schedules.tab")}</span>
          {!isLoading && schedules.length > 0 && (
            <Badge variant="outline" className="h-5 text-[10px]">
              {schedules.length}
            </Badge>
          )}
          {!isLoading && schedules.length === 0 && (
            <span className="text-xs text-muted-foreground">
              {t("hq.menus.schedules.empty_title")}
            </span>
          )}
        </div>
        {isReordering ? (
          <div className="flex items-center gap-1.5">
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={exitReorderMode}
              disabled={reorderMut.isPending}
            >
              <X className="size-3.5" />
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={handleSaveOrder}
              disabled={!hasReorderChanged || reorderMut.isPending}
            >
              {reorderMut.isPending && <Spinner className="mr-1 size-3.5" />}
              {t("common.save_changes")}
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-1.5">
            {schedules.length > 1 && (
              <Button
                variant="outline"
                size="sm"
                className="h-7 gap-1 text-xs"
                onClick={enterReorderMode}
              >
                <ArrowUpDown className="size-3.5" />
                Reorder
              </Button>
            )}
            <Button
              size="sm"
              className="h-7 gap-1.5 text-xs"
              onClick={() => {
                setEditTarget(null);
                setDialogOpen(true);
              }}
            >
              <Plus className="size-3.5" />
              {t("hq.menus.schedules.add")}
            </Button>
          </div>
        )}
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="space-y-1.5">
          {[1, 2].map((i) => (
            <Skeleton key={i} className="h-9 w-full" />
          ))}
        </div>
      ) : isError ? (
        <Alert variant="destructive" className="py-2">
          <AlertTitle className="text-xs">{t("common.error_loading")}</AlertTitle>
          <AlertDescription>
            <Button
              variant="outline"
              size="sm"
              className="mt-1 h-6 text-xs"
              onClick={() => refetch()}
            >
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      ) : isReordering ? (
        /* ── Drag-and-drop reorder list ───────────────────────────────────── */
        <div className="flex flex-col gap-1">
          {reorderItems.map((s, index) => (
            <div
              key={s.id}
              data-drag-row
              draggable
              onDragStart={(e) => onDragStart(e, index)}
              onDragEnter={(e) => onDragEnter(e, index)}
              onDragOver={onDragOver}
              onDrop={onDrop}
              onDragEnd={onDragEnd}
              className={[
                "flex cursor-grab items-center gap-2.5 rounded-md border bg-card px-2.5 py-2 select-none",
                "transition-all active:cursor-grabbing",
                draggingIndex === index
                  ? "border-primary/40 bg-primary/5 shadow-md"
                  : "hover:border-border/80 hover:shadow-sm",
              ].join(" ")}
            >
              <GripVertical className="size-3.5 shrink-0 text-muted-foreground/40" />
              <span className="inline-flex size-5 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold text-muted-foreground tabular-nums">
                {index + 1}
              </span>
              <span className="flex-1 truncate text-xs tabular-nums">
                {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
              </span>
              <div className="flex shrink-0 flex-wrap gap-1">
                {s.days_of_week_labels.map((day) => (
                  <Badge key={day} variant="outline" className="h-4 text-[9px]">
                    {day}
                  </Badge>
                ))}
              </div>
              <StatusBadge status={s.is_active ? "active" : "inactive"} />
            </div>
          ))}
        </div>
      ) : schedules.length === 0 ? (
        <div className="rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">
          {t("hq.menus.schedules.empty_desc")}
        </div>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="h-8 w-10 text-xs">{t("hq.products.col.stt")}</TableHead>
                <TableHead className="h-8 text-xs">{t("hq.menus.schedules.col_time")}</TableHead>
                <TableHead className="h-8 text-xs">{t("hq.menus.schedules.col_days")}</TableHead>
                <TableHead className="h-8 w-20 text-center text-xs">
                  {t("hq.menus.schedules.col_priority")}
                </TableHead>
                <TableHead className="h-8 w-24 text-xs">{t("common.status")}</TableHead>
                <TableHead className="h-8 w-14 text-right text-xs">{t("common.action")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {schedules.map((s, index) => (
                <TableRow key={s.id}>
                  <TableCell className="py-2 text-xs text-muted-foreground">{index + 1}</TableCell>
                  <TableCell className="py-2 text-sm tabular-nums">
                    {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
                    {s.start_date || s.end_date ? (
                      <span className="mt-0.5 block text-[10px] text-muted-foreground">
                        {(s.start_date ?? "…") + " → " + (s.end_date ?? "…")}
                      </span>
                    ) : null}
                  </TableCell>
                  <TableCell className="py-2">
                    <div className="flex flex-wrap gap-1">
                      {s.days_of_week_labels.map((day) => (
                        <Badge key={day} variant="outline" className="h-5 text-[10px]">
                          {day}
                        </Badge>
                      ))}
                    </div>
                  </TableCell>
                  <TableCell className="py-2 text-center">
                    <span className="inline-flex h-5 items-center rounded bg-blue-50 px-1.5 text-xs font-medium text-blue-700">
                      {s.priority}
                    </span>
                  </TableCell>
                  <TableCell className="py-2">
                    <StatusBadge status={s.is_active ? "active" : "inactive"} />
                  </TableCell>
                  <TableCell className="py-2 text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-7">
                          <EllipsisVertical className="size-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onClick={() => {
                            setEditTarget(s);
                            setDialogOpen(true);
                          }}
                        >
                          <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          disabled={updateMut.isPending}
                          onClick={() =>
                            updateMut.mutate({
                              scheduleId: s.id,
                              data: { is_active: !s.is_active },
                            })
                          }
                        >
                          <Power className="mr-2 size-3.5" />
                          {s.is_active ? t("common.deactivate") : t("common.activate")}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          className="text-destructive"
                          onClick={() => setDeleteTarget(s)}
                        >
                          <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <ScheduleFormDialog
        open={dialogOpen}
        onOpenChange={(o) => {
          setDialogOpen(o);
          if (!o) setEditTarget(null);
        }}
        schedule={editTarget}
        onSubmit={handleSubmit}
        isSubmitting={isSubmitting}
      />

      <AlertDialog
        open={!!deleteTarget}
        onOpenChange={(o) => {
          if (!o) setDeleteTarget(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.menus.schedules.delete_confirm_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.menus.schedules.delete_confirm_desc")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMut.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                void handleDelete();
              }}
              disabled={deleteMut.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Overlap warning (TC-MSCH-106): the new window collides with an existing
          schedule — warn but let the user proceed. */}
      <AlertDialog
        open={!!overlapPending}
        onOpenChange={(o) => {
          if (!o) setOverlapPending(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.menus.schedules.overlap_title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.menus.schedules.overlap_desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          {overlapPending && (
            <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
              {overlapPending.overlaps.map((s) => (
                <li key={s.id} className="flex items-center gap-2">
                  <span className="tabular-nums">
                    {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
                  </span>
                  <span className="flex flex-wrap gap-1">
                    {s.days_of_week_labels.map((day) => (
                      <Badge key={day} variant="outline" className="h-4 text-[9px]">
                        {day}
                      </Badge>
                    ))}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isSubmitting}>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                if (overlapPending) void commitSubmit(overlapPending.payload);
              }}
              disabled={isSubmitting}
            >
              {isSubmitting && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.menus.schedules.overlap_proceed")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// =========================================================================
//  Helpers
// =========================================================================

function serializeSections(sections: LocalSection[]): string {
  return JSON.stringify(
    sections.map((s) => ({
      name: s.name.trim(),
      translations: s.translations,
      ids: s.productIds,
      featured: s.isFeatured,
    }))
  );
}

// =========================================================================
//  Main Page
// =========================================================================

export default function MenuItemsPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string; menuId: string }>();
  const brandSlug = params.brandSlug;
  const menuId = params.menuId;
  const router = useRouter();

  // ------- Data queries -------
  const {
    data: menuResponse,
    isLoading: menuLoading,
    error: menuError,
  } = useMenu(brandSlug, menuId);
  const menu = menuResponse?.data ?? null;

  const { data: schedulesData } = useMenuSchedules(brandSlug, menuId);

  const { data: allProducts, isLoading: productsLoading } = useAllProducts(brandSlug, {
    status: "active",
    sort: "name",
    with_skus: true,
  });
  const products = useMemo<Product[]>(() => allProducts ?? [], [allProducts]);

  const productMap = useMemo(() => {
    const map = new Map<string, Product>();
    for (const p of products) map.set(p.id, p);
    return map;
  }, [products]);

  const menuEmbeddedMap = useMemo(() => {
    const map = new Map<string, Product>();
    for (const mp of menu?.menu_products ?? []) {
      if (!mp.product || !mp.product_id) continue;
      map.set(mp.product_id, mp.product as unknown as Product);
    }
    return map;
  }, [menu]);

  // #1099 — the menu line is where the consumption context lives, so the tax
  // override is edited per menu_product, not per product. One row per
  // (product, section) pair, keyed here by product id within this menu.
  const { data: taxTypeLookup } = useTaxTypeLookup(brandSlug);
  const taxTypes = useMemo(() => taxTypeLookup?.data ?? [], [taxTypeLookup]);
  const updateItemTax = useUpdateMenuProductTaxType(brandSlug, menuId);

  // This page also opens SHOP menus (they have their own tab in the menu list).
  // A shop menu that inherits from HQ takes its structure from HQ, and tax is
  // part of that structure (#1226): syncing rewrites all three tiers from the HQ
  // menu and writes NULL when HQ holds none. Offering the controls here would
  // persist a value the next sync deletes without a trace, so the API refuses it
  // (#1227 follow-up) and the controls come down with it. A shop menu created at
  // the shop inherits from nothing and keeps them.
  const inheritsFromHq = menu?.master_menu_id != null;

  const menuProductByProductId = useMemo(() => {
    const map = new Map<string, { id: string; taxTypeId: string | null }>();
    for (const mp of menu?.menu_products ?? []) {
      if (!mp.product_id) continue;
      map.set(mp.product_id, { id: mp.id, taxTypeId: mp.tax_type_id ?? null });
    }
    return map;
  }, [menu]);

  const renderTaxControl = useCallback(
    (productId: string) => {
      const row = menuProductByProductId.get(productId);
      // A product only just dragged in has no menu_products row until the
      // layout is saved; showing a control that cannot persist would be a lie.
      if (!row || taxTypes.length === 0 || inheritsFromHq) return null;

      return (
        <MenuItemTaxSelect
          value={row.taxTypeId}
          taxTypes={taxTypes}
          disabled={updateItemTax.isPending}
          onChange={(taxTypeId) =>
            updateItemTax.mutate({ menuProductId: row.id, taxTypeId })
          }
        />
      );
    },
    [menuProductByProductId, taxTypes, updateItemTax, inheritsFromHq]
  );

  // #1218 tier 2 — per-section tax type, stored on the menu↔section pivot so it
  // cannot follow the section into other menus. Null = inherit from the menu.
  const updateSectionTax = useUpdateMenuSectionTaxType(brandSlug, menuId);

  const sectionTaxBySectionId = useMemo(() => {
    const map = new Map<string, string | null>();
    // The API serialises this relation camelCase (`menuSections`), and the
    // per-menu values ride the PIVOT — the section row itself has no tax type.
    for (const section of menu?.menuSections ?? []) {
      map.set(section.id, section.pivot?.tax_type_id ?? null);
    }
    return map;
  }, [menu]);

  const renderSectionTaxControl = useCallback(
    (sectionId: string) => {
      // A section only just added exists locally until the layout is saved;
      // offering a control that cannot persist would be a lie, same rule as the
      // per-item one above.
      if (taxTypes.length === 0 || inheritsFromHq || !sectionTaxBySectionId.has(sectionId)) {
        return null;
      }

      return (
        <MenuSectionTaxSelect
          value={sectionTaxBySectionId.get(sectionId) ?? null}
          taxTypes={taxTypes}
          disabled={updateSectionTax.isPending}
          onChange={(taxTypeId) => updateSectionTax.mutate({ menuSectionId: sectionId, taxTypeId })}
        />
      );
    },
    [sectionTaxBySectionId, taxTypes, updateSectionTax, inheritsFromHq]
  );

  // #1218 tier 3 — the whole-menu tax type, the tier both controls above fall
  // back to. Stored on `menus.tax_type_id`, so unlike the section value it is
  // not per-pivot and applies wherever this menu is served.
  const updateMenuTax = useUpdateMenuTaxType(brandSlug, menuId);

  const allProductMap = useMemo(() => {
    if (!menuEmbeddedMap.size) return productMap;
    const map = new Map<string, Product>(productMap);
    for (const [id, p] of menuEmbeddedMap) {
      if (!map.has(id)) map.set(id, p);
    }
    return map;
  }, [productMap, menuEmbeddedMap]);

  const { data: categoriesResponse } = useCategoryLookup(brandSlug);
  const categories = useMemo<CategoryLookupItem[]>(
    () => categoriesResponse?.data ?? [],
    [categoriesResponse]
  );

  const { data: productTypesResponse } = useProductTypeLookup(brandSlug);
  const productTypes = useMemo(() => productTypesResponse?.data ?? [], [productTypesResponse]);

  // ------- Local draft state -------
  const [sections, setSections] = useState<LocalSection[]>([]);
  const [initialized, setInitialized] = useState(false);
  const [originalSnapshot, setOriginalSnapshot] = useState<string>("");
  // Object snapshot of the last-saved layout, so "Hủy" can revert the draft
  // in place (floating-section behaviour) rather than navigate away.
  const [originalSections, setOriginalSections] = useState<LocalSection[]>([]);

  useEffect(() => {
    if (!menu || initialized) return;

    const sectionsById = new Map<string, LocalSection>();

    for (const s of menu.menuSections ?? []) {
      sectionsById.set(s.id, {
        id: s.id,
        name: s.name,
        translations: sectionTranslations(s.translations, s.name),
        productIds: [],
        isFeatured: s.is_featured ?? false,
      });
    }

    const unassigned: string[] = [];
    for (const mp of menu.menu_products ?? []) {
      if (!allProductMap.has(mp.product_id)) continue;

      if (mp.menu_section_id) {
        let local = sectionsById.get(mp.menu_section_id);
        if (!local) {
          local = {
            id: mp.menu_section_id,
            name: mp.section?.name ?? t("hq.menus.items.unknown_section"),
            translations: sectionTranslations(
              undefined,
              mp.section?.name ?? t("hq.menus.items.unknown_section")
            ),
            productIds: [],
            isFeatured: false,
          };
          sectionsById.set(mp.menu_section_id, local);
        }
        if (!local.productIds.includes(mp.product_id)) {
          local.productIds.push(mp.product_id);
        }
      } else {
        unassigned.push(mp.product_id);
      }
    }

    const ordered: LocalSection[] = [];
    for (const s of menu.menuSections ?? []) {
      const local = sectionsById.get(s.id);
      if (local) ordered.push(local);
    }
    for (const [, sec] of sectionsById) {
      if (!ordered.find((s) => s.id === sec.id)) ordered.push(sec);
    }

    if (unassigned.length > 0) {
      ordered.unshift({
        id: `local-default-${Date.now()}`,
        name: t("hq.menus.items.default_section"),
        translations: sectionTranslations(undefined, t("hq.menus.items.default_section")),
        productIds: unassigned,
        isFeatured: false,
      });
    }

    if (ordered.length === 0) {
      ordered.push({
        id: `local-default-${Date.now()}`,
        name: t("hq.menus.items.default_section"),
        translations: sectionTranslations(undefined, t("hq.menus.items.default_section")),
        productIds: [],
        isFeatured: false,
      });
    }

    setSections(ordered);
    setOriginalSnapshot(serializeSections(ordered));
    setOriginalSections(ordered);
    setInitialized(true);
  }, [menu, allProductMap, initialized, t]);

  // ------- Filters -------
  const [searchTerm, setSearchTerm] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<string>("__all__");
  const [productTypeFilter, setProductTypeFilter] = useState<string>("__all__");

  // ------- Product detail dialog -------
  const [detailProductId, setDetailProductId] = useState<string | null>(null);

  // ------- Save mutation -------
  const syncLayoutMut = useSyncMenuLayout(brandSlug);
  const [isSaving, setIsSaving] = useState(false);

  // ------- Workflow mutations -------
  const submitMenu = useSubmitMenu(brandSlug);
  const approveMenu = useApproveMenu(brandSlug);
  const rejectMenu = useRejectMenu(brandSlug);
  const activateMenu = useActivateMenu(brandSlug);
  const deactivateMenu = useDeactivateMenu(brandSlug);
  const deleteMenu = useDeleteMenu(brandSlug);
  const updateTimeout = useUpdateMenuTimeout(brandSlug, menuId);
  const updateMenu = useUpdateMenu(brandSlug);

  // #463 — service-type gate, editable inline from the detail header. Changing
  // it PATCHes just service_type so the customer flow (takeaway vs dine-in) that
  // shows this menu updates immediately.
  function handleServiceTypeChange(value: "Takeaway" | "DineIn" | "Both") {
    if (value === menu?.service_type) return;
    updateMenu.mutate({ id: menuId, data: { service_type: value } });
  }

  // ------- Workflow dialogs -------
  const [rejectDialogOpen, setRejectDialogOpen] = useState(false);
  const [rejectReason, setRejectReason] = useState("");
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [timeoutDialogOpen, setTimeoutDialogOpen] = useState(false);

  // ------- Unsaved-changes guard -------
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);

  // ------- Derived -------
  const assignedProductCounts = useMemo(() => {
    const map = new Map<string, number>();
    for (const s of sections) {
      for (const pid of s.productIds) {
        map.set(pid, (map.get(pid) ?? 0) + 1);
      }
    }
    return map;
  }, [sections]);

  const availableProducts = useMemo(() => {
    const result: Product[] = [];
    for (const product of products) {
      if (categoryFilter !== "__all__") {
        const hasCat = product.categories?.some((c) => c.id === categoryFilter);
        if (!hasCat) continue;
      }
      if (productTypeFilter !== "__all__") {
        if (product.product_type_id !== productTypeFilter) continue;
      }
      if (searchTerm) {
        const q = searchTerm.toLowerCase();
        const match =
          product.name?.toLowerCase().includes(q) ||
          product.sku?.toLowerCase().includes(q) ||
          product.skus?.some(
            (s) => s.name?.toLowerCase().includes(q) || s.sku?.toLowerCase().includes(q)
          );
        if (!match) continue;
      }
      result.push(product);
    }
    return result;
  }, [products, categoryFilter, productTypeFilter, searchTerm]);

  const hasChanges = useMemo(() => {
    if (!initialized) return false;
    return serializeSections(sections) !== originalSnapshot;
  }, [sections, originalSnapshot, initialized]);

  const usedSectionNames = useMemo(
    () => new Set(sections.map((s) => s.name.toLowerCase())),
    [sections]
  );

  // ------- Local actions -------

  const handleRemoveProductFromSection = useCallback((sectionId: string, productId: string) => {
    setSections((prev) =>
      prev.map((s) =>
        s.id === sectionId ? { ...s, productIds: s.productIds.filter((p) => p !== productId) } : s
      )
    );
  }, []);

  const handleAddSection = useCallback((translations: Record<string, string>) => {
    const name = canonicalSectionName(translations);
    setSections((prev) => [
      ...prev,
      {
        id: `local-${Date.now()}`,
        name,
        translations,
        productIds: [],
        isFeatured: false,
      },
    ]);
  }, []);

  const handleRenameSection = useCallback(
    (sectionId: string, translations: Record<string, string>) => {
      const filled = fillLocalesFallback(translations);
      const normalized = canonicalSectionName(filled);
      if (!normalized) return;
      setSections((prev) => {
        const duplicate = prev.some(
          (s) => s.id !== sectionId && s.name.toLowerCase() === normalized.toLowerCase()
        );
        if (duplicate) {
          toast.error(t("hq.menus.items.section_duplicate", { name: normalized }));
          return prev;
        }
        return prev.map((s) =>
          s.id === sectionId ? { ...s, name: normalized, translations: filled } : s
        );
      });
    },
    [t]
  );

  // #1187 — local only; the flag is persisted by handleSave with the rest of
  // the layout, so a cashier-visible carousel never changes on a half-saved page.
  const handleToggleFeatured = useCallback((sectionId: string) => {
    setSections((prev) =>
      prev.map((s) => (s.id === sectionId ? { ...s, isFeatured: !s.isFeatured } : s))
    );
  }, []);

  const handleRemoveSection = useCallback((sectionId: string) => {
    setSections((prev) => prev.filter((s) => s.id !== sectionId));
  }, []);

  // ------- DnD -------
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));
  const [activeDrag, setActiveDrag] = useState<ActiveDrag>(null);

  const sectionSortableIds = useMemo(() => sections.map((s) => `sort-section:${s.id}`), [sections]);

  const collisionDetection: CollisionDetection = useCallback(
    (args) => {
      if (activeDrag?.kind === "section-row") {
        return closestCenter({
          ...args,
          droppableContainers: args.droppableContainers.filter(
            (c) => c.data.current?.kind === "section-row"
          ),
        });
      }
      return closestCorners(args);
    },
    [activeDrag]
  );

  const handleDragStart = useCallback((event: DragStartEvent) => {
    const data = event.active.data.current as
      | { kind: "product"; product: Product }
      | { kind: "item"; sectionId: string; product: Product }
      | { kind: "section-row"; sectionId: string; sectionName: string }
      | undefined;
    if (!data) return;
    setActiveDrag(data);
  }, []);

  const handleDragEnd = useCallback((event: DragEndEvent) => {
    const { active, over } = event;
    setActiveDrag(null);
    if (!over) return;

    const activeData = active.data.current as
      | { kind: "product"; product: Product }
      | { kind: "item"; sectionId: string; product: Product }
      | { kind: "section-row"; sectionId: string }
      | undefined;

    if (!activeData) return;

    // Section reorder — always return early, handled or not
    if (activeData.kind === "section-row") {
      const overData = over.data.current as { kind: string; sectionId?: string } | undefined;
      if (overData?.kind === "section-row" && overData.sectionId) {
        setSections((prev) => {
          const fromIdx = prev.findIndex((s) => s.id === activeData.sectionId);
          const toIdx = prev.findIndex((s) => s.id === overData.sectionId);
          if (fromIdx < 0 || toIdx < 0 || fromIdx === toIdx) return prev;
          return arrayMove(prev, fromIdx, toIdx);
        });
      }
      return;
    }

    const overData = over.data.current as
      | { kind: "section"; sectionId: string }
      | { kind: "item"; sectionId: string; product: Product }
      | undefined;
    if (!overData) return;

    const targetSectionId = overData.kind === "section" ? overData.sectionId : overData.sectionId;
    const overProductId = overData.kind === "item" ? overData.product.id : null;

    if (activeData.kind === "product") {
      const productId = activeData.product.id;
      setSections((prev) =>
        prev.map((s) => {
          if (s.id !== targetSectionId) return s;
          if (s.productIds.includes(productId)) return s;
          let insertAt = s.productIds.length;
          if (overProductId) {
            const idx = s.productIds.indexOf(overProductId);
            if (idx >= 0) insertAt = idx;
          }
          const next = [...s.productIds];
          next.splice(insertAt, 0, productId);
          return { ...s, productIds: next };
        })
      );
      return;
    }

    const sourceSectionId = activeData.sectionId;
    const productId = activeData.product.id;

    if (sourceSectionId === targetSectionId) {
      setSections((prev) =>
        prev.map((s) => {
          if (s.id !== sourceSectionId) return s;
          const fromIdx = s.productIds.indexOf(productId);
          if (fromIdx < 0) return s;
          const toIdx = overProductId
            ? s.productIds.indexOf(overProductId)
            : s.productIds.length - 1;
          if (toIdx < 0 || toIdx === fromIdx) return s;
          return { ...s, productIds: arrayMove(s.productIds, fromIdx, toIdx) };
        })
      );
      return;
    }

    setSections((prev) =>
      prev.map((s) => {
        if (s.id === sourceSectionId) {
          return { ...s, productIds: s.productIds.filter((p) => p !== productId) };
        }
        if (s.id === targetSectionId) {
          if (s.productIds.includes(productId)) return s;
          let insertAt = s.productIds.length;
          if (overProductId) {
            const idx = s.productIds.indexOf(overProductId);
            if (idx >= 0) insertAt = idx;
          }
          const next = [...s.productIds];
          next.splice(insertAt, 0, productId);
          return { ...s, productIds: next };
        }
        return s;
      })
    );
  }, []);

  // ------- Save -------
  const handleSave = useCallback(async (): Promise<boolean> => {
    const seen = new Set<string>();
    for (const s of sections) {
      const key = s.name.trim().toLowerCase();
      if (!key) {
        toast.error(t("hq.menus.items.section_empty_error"));
        return false;
      }
      if (seen.has(key)) {
        toast.error(t("hq.menus.items.section_duplicate_error", { name: s.name }));
        return false;
      }
      seen.add(key);
    }

    setIsSaving(true);
    try {
      const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

      const menu_items = sections.map((s) => ({
        section_name: canonicalSectionName(s.translations, s.name),
        product_ids: s.productIds.filter((id) => id && uuidRegex.test(id)),
      }));
      const response = await syncLayoutMut.mutateAsync({ menuId, data: { menu_items } });

      // The layout endpoint creates/reuses sections, then returns them in the
      // requested order. Persist the complete locale payload before the order.
      // A locale failure must fail the save instead of silently reporting a
      // successful layout with untranslated customer content.
      {
        const freshSections =
          (response as {
            data?: { menuSections?: { id: string; name: string; updated_at: string }[] };
          })?.data
            ?.menuSections ?? [];
        if (freshSections.length > 0) {
          if (freshSections.length !== sections.length) {
            throw new Error("Menu layout response did not contain every section");
          }
          await Promise.all(
            sections.map((local, index) => {
              const filled = fillLocalesFallback(local.translations);
              return menuSectionService.update(brandSlug, freshSections[index].id, {
                updated_at: freshSections[index].updated_at,
                name: filled[DEFAULT_LOCALE],
                // #1187 — rides the same PUT as the name so the carousel flag
                // can never drift from the section it belongs to.
                is_featured: local.isFeatured,
                ...buildI18nPayload({ name: filled }),
              });
            })
          );
          const orderedSections = freshSections.map((section, index) => ({
            id: section.id,
            display_order: index + 1,
          }));
          if (orderedSections.length > 0) {
            await menuSectionService.syncForMenu(brandSlug, menuId, { sections: orderedSections });
          }
        }
      }

      setOriginalSnapshot(serializeSections(sections));
      setOriginalSections(sections);
      return true;
    } catch (error) {
      toast.error(error instanceof Error ? error.message : t("hq.menus.items.save_failed"));
      return false;
    } finally {
      setIsSaving(false);
    }
  }, [sections, menuId, brandSlug, syncLayoutMut, t]);

  const handleRequestExit = useCallback(() => {
    if (hasChanges) {
      setConfirmExitOpen(true);
      return;
    }
    router.back();
  }, [hasChanges, router]);

  /** Discard the draft — snap the layout back to the last-saved state in place
   *  (floating-section behaviour). Stays on the page. */
  const handleRevert = useCallback(() => {
    setSections(originalSections);
  }, [originalSections]);

  // ------- Workflow handlers -------
  const handleSubmitForApproval = useCallback(async () => {
    if (!menu) return;
    try {
      await submitMenu.mutateAsync(menu.id);
    } catch {
      /* toast fired by hook */
    }
  }, [menu, submitMenu]);

  const handleApprove = useCallback(async () => {
    if (!menu) return;
    try {
      await approveMenu.mutateAsync(menu.id);
    } catch {
      /* toast fired by hook */
    }
  }, [menu, approveMenu]);

  const handleReject = useCallback(async () => {
    if (!menu) return;
    const reason = rejectReason.trim();
    if (!reason) return;
    try {
      await rejectMenu.mutateAsync({ id: menu.id, reason });
      setRejectDialogOpen(false);
      setRejectReason("");
    } catch {
      /* toast fired by hook */
    }
  }, [menu, rejectMenu, rejectReason]);

  const handleActivate = useCallback(async () => {
    if (!menu) return;
    try {
      await activateMenu.mutateAsync(menu.id);
    } catch {
      /* toast fired by hook */
    }
  }, [menu, activateMenu]);

  const handleDeactivate = useCallback(async () => {
    if (!menu) return;
    try {
      await deactivateMenu.mutateAsync(menu.id);
    } catch {
      /* toast fired by hook */
    }
  }, [menu, deactivateMenu]);

  const handleDelete = useCallback(async () => {
    if (!menu) return;
    try {
      await deleteMenu.mutateAsync(menu.id);
      setDeleteDialogOpen(false);
      router.push(`/hq/${brandSlug}/menus`);
    } catch {
      /* toast fired by hook */
    }
  }, [menu, deleteMenu, router, brandSlug]);

  // ------- Workflow visibility flags -------
  // Activation/Deactivation are exposed for both master and branch menus per
  // the user-requested behaviour — BE has no master/branch restriction on
  // status transitions, only on the status enum itself.
  const isTrashed = !!menu?.deleted_at;
  const hasSchedules = menu?.has_schedules ?? (schedulesData?.data?.length ?? 0) > 0;
  const productsCount = menu?.menu_products_count ?? menu?.menu_products?.length ?? 0;
  const canSubmitWorkflow = productsCount > 0;
  const showSubmit =
    !!menu && !isTrashed && (menu.status === "Draft" || menu.status === "Rejected");
  const showApproveReject = !!menu && !isTrashed && menu.status === "Pending";
  const showActivate =
    !!menu && !isTrashed && (menu.status === "Approved" || menu.status === "Inactive");
  const showDeactivate = !!menu && !isTrashed && menu.status === "Active";

  const workflowPending =
    submitMenu.isPending ||
    approveMenu.isPending ||
    rejectMenu.isPending ||
    activateMenu.isPending ||
    deactivateMenu.isPending;

  const disabledAll = isSaving || workflowPending || deleteMenu.isPending;

  // ------- Loading state -------
  // 404 / 403 → Next.js not-found page (id doesn't exist or no access), instead
  // of a bare centered "menu not found" line. Runs before the loading guard so
  // a missing id resolves to a proper 404 (TC-MENU-DET2).
  if (menuError instanceof ApiError && (menuError.status === 404 || menuError.status === 403)) {
    notFound();
  }

  if (menuLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    );
  }

  if (!menu) {
    return (
      <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
        {t("hq.menus.items.menu_not_found")}
      </div>
    );
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={collisionDetection}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
      onDragCancel={() => setActiveDrag(null)}
    >
      <div className="flex h-full flex-col">
        {/* Header — no tab nav */}
        <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center border-b bg-background px-4">
          <div className="flex flex-1 items-center gap-2">
            <button
              type="button"
              onClick={handleRequestExit}
              className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <ArrowLeft className="size-4" />
            </button>
            <h1 className="text-lg font-semibold">{menu.name}</h1>
            <StatusBadge status={isTrashed ? "deleted" : menu.status.toLowerCase()} />
            <span className="text-xs text-muted-foreground">
              —{" "}
              {t("hq.menus.items.products_sections_stat", {
                products: assignedProductCounts.size,
                sections: sections.length,
              })}
            </span>
            {hasChanges && (
              <Badge
                variant="outline"
                className="h-5 border-amber-300 bg-amber-50 text-[10px] text-amber-600"
              >
                {t("hq.menus.items.unsaved")}
              </Badge>
            )}
          </div>
          <div className="flex items-center gap-1.5">
            <Button
              variant="outline"
              size="sm"
              className="h-8 text-xs"
              onClick={handleRevert}
              disabled={!hasChanges || disabledAll}
            >
              {t("common.cancel")}
            </Button>

            {/* Workflow — Submit / Resubmit (Draft / Rejected) */}
            {showSubmit && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={handleSubmitForApproval}
                disabled={disabledAll || !canSubmitWorkflow}
                title={
                  canSubmitWorkflow ? undefined : t("hq.menus.workflow.submit_blocked_no_products")
                }
              >
                {submitMenu.isPending ? (
                  <Spinner className="size-3.5" />
                ) : (
                  <Send className="size-3.5" />
                )}
                {menu.status === "Rejected"
                  ? t("hq.menus.actions.resubmit")
                  : t("hq.menus.actions.submit")}
              </Button>
            )}

            {/* Workflow — Reject + Approve (Pending) */}
            {showApproveReject && (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 gap-1.5 border-red-300 text-xs text-red-700 hover:bg-red-50"
                  onClick={() => setRejectDialogOpen(true)}
                  disabled={disabledAll}
                >
                  <XCircle className="size-3.5" />
                  {t("hq.menus.actions.reject")}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-8 gap-1.5 text-xs"
                  onClick={handleApprove}
                  disabled={disabledAll}
                >
                  {approveMenu.isPending ? (
                    <Spinner className="size-3.5" />
                  ) : (
                    <CheckCircle2 className="size-3.5" />
                  )}
                  {t("hq.menus.actions.approve")}
                </Button>
              </>
            )}

            {/* Workflow — Activate (Approved / Inactive) */}
            {showActivate && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 border-green-400 text-xs text-green-700 hover:bg-green-50"
                onClick={handleActivate}
                disabled={disabledAll}
              >
                {activateMenu.isPending ? (
                  <Spinner className="size-3.5" />
                ) : (
                  <PlayCircle className="size-3.5" />
                )}
                {t("hq.menus.actions.activate")}
              </Button>
            )}

            {/* Workflow — Deactivate (Active) */}
            {showDeactivate && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={handleDeactivate}
                disabled={disabledAll}
              >
                {deactivateMenu.isPending ? (
                  <Spinner className="size-3.5" />
                ) : (
                  <PauseCircle className="size-3.5" />
                )}
                {t("hq.menus.actions.deactivate")}
              </Button>
            )}

            {/* Service type (#463) — which customer flow shows this menu. */}
            {!isTrashed && (
              <Select
                value={menu.service_type ?? "Both"}
                onValueChange={(v) =>
                  handleServiceTypeChange(v as "Takeaway" | "DineIn" | "Both")
                }
                disabled={updateMenu.isPending}
              >
                <SelectTrigger
                  className="h-8 gap-1.5 text-xs"
                  title={t("hq.menus.form.service_type_hint")}
                >
                  {updateMenu.isPending ? (
                    <Spinner className="size-3.5" />
                  ) : (
                    <UtensilsCrossed className="size-3.5" />
                  )}
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="Both">
                    {t("hq.menus.form.service_type_both")}
                  </SelectItem>
                  <SelectItem value="Takeaway">
                    {t("hq.menus.form.service_type_takeaway")}
                  </SelectItem>
                  <SelectItem value="DineIn">
                    {t("hq.menus.form.service_type_dinein")}
                  </SelectItem>
                </SelectContent>
              </Select>
            )}

            {/* Timeout — metadata, independent of workflow state; hidden when no schedule */}
            {!isTrashed && hasSchedules && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={() => setTimeoutDialogOpen(true)}
                disabled={updateTimeout.isPending}
              >
                <Clock className="size-3.5" />
                {t("hq.menus.timeout.button_label")}
                {menu.cart_timeout_minutes != null ? (
                  <span className="ml-0.5 rounded bg-primary/10 px-1 py-0.5 text-[10px] font-medium text-primary tabular-nums">
                    {menu.cart_timeout_minutes}
                    {t("common.timeout.minutes_unit")}
                  </span>
                ) : (
                  <span className="ml-0.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                    {t("common.timeout.badge_default")}
                  </span>
                )}
              </Button>
            )}

            {/* Delete — hidden when trashed or Active (BE forbids deleting Active) */}
            {!isTrashed && menu.status !== "Active" && (
              <Button
                type="button"
                variant="destructive"
                size="sm"
                className="h-8 gap-1.5 text-xs"
                onClick={() => setDeleteDialogOpen(true)}
                disabled={disabledAll}
              >
                <Trash2 className="size-3.5" />
                {t("hq.menus.delete_action")}
              </Button>
            )}

            <Button
              size="sm"
              className="h-8 gap-1.5 text-xs"
              onClick={() => handleSave()}
              disabled={!hasChanges || disabledAll}
            >
              {isSaving ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
              {t("common.save")}
            </Button>
          </div>
        </div>

        {/* Rejection banner */}
        {!isTrashed && menu.status === "Rejected" && menu.rejection_reason && (
          <div
            role="alert"
            className="shrink-0 border-b border-red-300 bg-red-50 px-4 py-2 text-xs text-red-800"
          >
            <div className="mb-0.5 flex items-center gap-1.5 font-semibold">
              <XCircle className="size-3.5" />
              {t("hq.menus.workflow.rejected_title")}
            </div>
            <div className="leading-relaxed whitespace-pre-wrap">
              <b>{t("hq.menus.workflow.rejection_reason_label")}</b> {menu.rejection_reason}
            </div>
          </div>
        )}

        {/* Body: schedules on top, items below */}
        <div className="flex min-h-0 flex-1 flex-col">
          {/* Schedules section */}
          <div className="shrink-0 border-b bg-muted/30">
            <SchedulesSection brandSlug={brandSlug} menuId={menuId} />
          </div>

          {/* Items section — fills remaining height */}
          <div className="flex min-h-0 flex-1">
            {/* Left panel: product catalog */}
            <div className="flex w-[320px] shrink-0 flex-col border-r bg-background">
              <div className="space-y-2 border-b p-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                    <Package className="size-3.5 text-primary" />
                    {t("hq.menus.items.products_count", { n: availableProducts.length })}
                  </div>
                  {productsLoading && <Spinner className="size-3.5 text-muted-foreground" />}
                </div>
                <div className="relative">
                  <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    placeholder={t("hq.menus.items.search_products")}
                    value={searchTerm}
                    onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                      setSearchTerm(e.target.value)
                    }
                    className="h-8 pl-8 text-sm"
                  />
                </div>
                <Select
                  value={categoryFilter}
                  onValueChange={(v) => setCategoryFilter(v ?? "__all__")}
                >
                  <SelectTrigger className="h-7 text-xs">
                    <SelectValue placeholder={t("hq.menus.items.all_categories")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__all__">{t("hq.menus.items.all_categories")}</SelectItem>
                    {categories.map((cat) => (
                      <SelectItem key={cat.id} value={cat.id}>
                        {cat.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Select
                  value={productTypeFilter}
                  onValueChange={(v) => setProductTypeFilter(v ?? "__all__")}
                >
                  <SelectTrigger className="h-7 text-xs">
                    <SelectValue placeholder={t("hq.menus.items.all_product_types")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__all__">{t("hq.menus.items.all_product_types")}</SelectItem>
                    {productTypes.map((type) => (
                      <SelectItem key={type.id} value={type.id}>
                        {type.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-[10px] text-muted-foreground">{t("hq.menus.items.drag_hint")}</p>
              </div>
              <div className="flex-1 space-y-1 overflow-y-auto p-2">
                {availableProducts.map((product) => (
                  <DraggableProductCard
                    key={product.id}
                    product={product}
                    onClick={() => setDetailProductId(product.id)}
                  />
                ))}
                {availableProducts.length === 0 && !productsLoading && (
                  <div className="flex flex-col items-center justify-center py-8 text-center text-sm text-muted-foreground">
                    <Package className="mb-2 size-8 text-muted-foreground/40" />
                    {t("hq.menus.items.no_products")}
                  </div>
                )}
              </div>
              <div className="shrink-0 border-t px-3 py-2 text-center text-xs text-muted-foreground">
                {t("hq.menus.items.product_count_total", {
                  shown: availableProducts.length,
                  total: products.length,
                })}
              </div>
            </div>

            {/* Right panel: sections */}
            <div className="min-w-0 flex-1 overflow-y-auto p-4">
              {/* #1218 tier 3 — sits above every section on purpose: the three
                  controls on this page read top-down in the same order the
                  resolver walks them (menu → section → item). */}
              {taxTypes.length > 0 && !inheritsFromHq && (
                <MenuTaxSelect
                  value={menu.tax_type_id ?? null}
                  taxTypes={taxTypes}
                  disabled={updateMenuTax.isPending}
                  onChange={(taxTypeId) => updateMenuTax.mutate(taxTypeId)}
                />
              )}
              {inheritsFromHq && (
                // NAME the HQ menu and link to it. "Set it on the HQ menu"
                // alone reads as nonsense here, because this page is itself
                // under /hq/ — the menu it opened just happens to be a shop
                // menu. Pointing at the actual parent by name is what makes the
                // difference between the two visible.
                <p className="mb-3 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                  {t("hq.menus.tax.hq_managed_hint")}{" "}
                  {menu.masterMenu && (
                    <Link
                      href={`/hq/${brandSlug}/menus/${menu.masterMenu.id}/items`}
                      className="font-medium text-foreground underline underline-offset-2"
                    >
                      {menu.masterMenu.name}
                    </Link>
                  )}
                </p>
              )}
              <SortableContext items={sectionSortableIds} strategy={verticalListSortingStrategy}>
                {sections.map((section) => (
                  <SortableSectionPanel
                    key={section.id}
                    section={section}
                    productMap={allProductMap}
                    onRemoveItem={(pid) => handleRemoveProductFromSection(section.id, pid)}
                    onRenameSection={(name) => handleRenameSection(section.id, name)}
                    onToggleFeatured={() => handleToggleFeatured(section.id)}
                    onRemoveSection={() => handleRemoveSection(section.id)}
                    isCollapsed={activeDrag?.kind === "section-row"}
                    renderTaxControl={renderTaxControl}
                    renderSectionTaxControl={renderSectionTaxControl}
                  />
                ))}
              </SortableContext>
              <AddSectionInline usedNames={usedSectionNames} onAdd={handleAddSection} />
            </div>
          </div>
        </div>

        {/* Product detail dialog */}
        {detailProductId && (
          <ProductDetailDialog
            brandSlug={brandSlug}
            productId={detailProductId}
            open={!!detailProductId}
            onOpenChange={(open) => {
              if (!open) setDetailProductId(null);
            }}
          />
        )}

        {/* Reject dialog — BE requires reason (1–1000 chars). */}
        <Dialog
          open={rejectDialogOpen}
          onOpenChange={(open) => {
            setRejectDialogOpen(open);
            if (!open) setRejectReason("");
          }}
        >
          <DialogContent aria-describedby={undefined} className="sm:max-w-lg">
            <DialogHeader>
              <DialogTitle>{t("hq.menus.reject_dialog.title")}</DialogTitle>
              <DialogDescription>{t("hq.menus.reject_dialog.desc")}</DialogDescription>
            </DialogHeader>
            <div className="flex flex-col gap-2">
              <Textarea
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                rows={4}
                maxLength={1000}
                placeholder={t("hq.menus.reject_dialog.reason_placeholder")}
                disabled={rejectMenu.isPending}
                aria-label={t("hq.menus.reject_dialog.title")}
                className="field-sizing-fixed"
              />
              <div className="text-right text-[11px] text-muted-foreground">
                {rejectReason.length}/1000
              </div>
            </div>
            <DialogFooter>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setRejectDialogOpen(false)}
                disabled={rejectMenu.isPending}
              >
                {t("common.cancel")}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={() => {
                  void handleReject();
                }}
                disabled={!rejectReason.trim() || rejectMenu.isPending}
              >
                {rejectMenu.isPending && <Spinner className="mr-1.5 size-3.5" />}
                {t("hq.menus.reject_dialog.confirm")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete confirmation */}
        <DeleteConfirmDialog
          open={deleteDialogOpen}
          onOpenChange={setDeleteDialogOpen}
          description={menu ? t("hq.menus.delete_confirm", { name: menu.name }) : ""}
          onConfirm={() => {
            void handleDelete();
          }}
          isPending={deleteMenu.isPending}
        />

        {/* Cart timeout dialog */}
        <HqSetTimeoutDialog
          open={timeoutDialogOpen}
          onOpenChange={setTimeoutDialogOpen}
          hqMenuTimeoutMinutes={menu.cart_timeout_minutes}
          hqBrandTimeoutMinutes={menu.hq_brand_timeout_minutes}
          isPending={updateTimeout.isPending}
          onSave={(minutes) => updateTimeout.mutate(minutes)}
        />

        {/* Unsaved-changes confirmation */}
        <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>{t("hq.menus.items.unsaved_title")}</AlertDialogTitle>
              <AlertDialogDescription>{t("hq.menus.items.unsaved_desc")}</AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
              <AlertDialogAction
                onClick={(e) => {
                  e.preventDefault();
                  setConfirmExitOpen(false);
                  router.back();
                }}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              >
                {t("hq.menus.items.discard_and_leave")}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>

      {/* Drag preview */}
      <DragOverlay>
        {activeDrag?.kind === "section-row" && (
          <div className="flex cursor-grabbing items-center gap-2 rounded-md border bg-card px-3 py-2 shadow-lg">
            <GripVertical className="size-3.5 text-muted-foreground/40" />
            <FolderOpen className="size-3.5 text-muted-foreground" />
            <span className="text-sm font-medium">{activeDrag.sectionName}</span>
          </div>
        )}
        {activeDrag?.kind === "product" && (
          <div className="flex w-70 items-center gap-2.5 rounded-md border bg-card p-2 shadow-lg">
            <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
              <ImageIcon className="size-3.5 text-muted-foreground" />
            </div>
            <div className="flex min-w-0 flex-1 flex-col">
              <span className="truncate text-sm font-medium">
                {activeDrag.product.name || t("hq.menus.items.untitled")}
              </span>
              <span className="text-[11px] text-muted-foreground">
                {t("hq.menus.items.variants_count", {
                  n: activeDrag.product.active_skus_count ?? 0,
                })}
              </span>
            </div>
          </div>
        )}
        {activeDrag?.kind === "item" && (
          <div className="flex w-80 items-center gap-2 rounded-md border bg-card p-2 shadow-lg">
            <GripVertical className="size-3.5 text-muted-foreground" />
            <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
              <ImageIcon className="size-3.5 text-muted-foreground" />
            </div>
            <div className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">
                {activeDrag.product.name || t("hq.menus.items.untitled")}
              </span>
              <span className="text-[11px] text-muted-foreground">
                {activeDrag.product.active_skus_count ?? 0} variants
              </span>
            </div>
          </div>
        )}
      </DragOverlay>
    </DndContext>
  );
}
