"use client";

/**
 * SKU gallery upload dialog.
 *
 * Layout: 1 horizontal row — main image (2× size) first, thumbnails next,
 * then an "Add image" tile. Drag any thumbnail onto the main slot (or
 * reorder inside the row) and the underlying array reorders. Index 0 is
 * always the main image; BE's `galleryFirst()` morph resolves to the file
 * with the lowest sort_order, so the sidebar/row thumbnail elsewhere in
 * the app auto-syncs after save.
 *
 * Upload flow reuses the shared `/api/v1/files/upload` endpoint:
 *   1. Client uploads → temp File (12h expiry)
 *   2. User confirms → caller syncs the ordered list via
 *      `productSkuService.syncImages(...)`. Removed files revert to temp
 *      with a 24h recovery window; the cleanup cron sweeps expired rows.
 */

import { useCallback, useEffect, useRef, useState, type CSSProperties } from "react";
import Image from "next/image";
import { GripVertical, ImageIcon, Plus, X } from "lucide-react";
import { toast } from "sonner";
import { DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, arrayMove, rectSortingStrategy, useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";

import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
} from "@godxjp/ui";

import { productImageService, type ProductImageFile } from "@/services/product-image-service";
import { useTranslation } from "@/providers/app-provider";

interface SortableGalleryTileProps {
  image: ProductImageFile;
  isMain: boolean;
  mainLabel: string;
  onDelete: (id: string) => void;
  disabled: boolean;
}

function SortableGalleryTile({
  image,
  isMain,
  mainLabel,
  onDelete,
  disabled,
}: SortableGalleryTileProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: image.id,
    disabled,
  });

  const style: CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.4 : 1,
    zIndex: isDragging ? 10 : undefined,
  };

  // Main tile is exactly 2× the thumbnail so the row stays on one line.
  const sizeClass = isMain ? "size-48" : "size-24";
  const slot = isMain ? "sku-gallery-main" : "sku-gallery-thumb";
  const container = isMain
    ? "border-2 border-primary/30 shadow-sm ring-1 ring-primary/10 rounded-lg"
    : "border border-border hover:border-primary/50 hover:shadow-sm rounded-md";

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...(isMain ? {} : listeners)}
      {...(isMain ? {} : attributes)}
      data-slot={slot}
      className={`group relative ${sizeClass} shrink-0 cursor-grab overflow-hidden bg-muted transition active:cursor-grabbing ${container}`}
    >
      <Image
        src={image.url}
        alt={image.original_name}
        fill
        sizes={isMain ? "200px" : "100px"}
        className="object-cover"
      />
      {isMain && (
        <Badge
          variant="default"
          className="absolute top-2 left-2 gap-1 px-2 py-0.5 text-[11px] font-semibold shadow"
        >
          <ImageIcon className="size-3" />
          {mainLabel}
        </Badge>
      )}
      {!disabled && (
        <button
          type="button"
          aria-label="Delete image"
          onClick={(e) => {
            e.stopPropagation();
            onDelete(image.id);
          }}
          onPointerDown={(e) => e.stopPropagation()}
          className={`absolute hidden rounded-full bg-black/70 text-white transition group-hover:flex hover:bg-destructive ${
            isMain ? "top-2 right-2 p-1.5" : "top-1 right-1 p-0.5"
          }`}
        >
          <X className={isMain ? "size-4" : "size-3"} />
        </button>
      )}
      {isMain && !disabled && (
        <div
          {...listeners}
          {...attributes}
          aria-label="Drag to reorder"
          className="absolute right-2 bottom-2 hidden cursor-grab rounded-md bg-black/70 p-1.5 text-white shadow group-hover:flex active:cursor-grabbing"
        >
          <GripVertical className="size-4" />
        </div>
      )}
    </div>
  );
}

export interface SkuGalleryDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Initial image list (parent owns fetching). */
  initial: ProductImageFile[];
  /** True while the parent is still loading the SKU's gallery from the API. */
  loading?: boolean;
  /** Called with the ordered draft when the user clicks Confirm. Caller is
   *  responsible for persisting via `productSkuService.syncImages(...)`. */
  onConfirm: (images: ProductImageFile[]) => Promise<void> | void;
}

export function SkuGalleryDialog({
  open,
  onOpenChange,
  initial,
  loading = false,
  onConfirm,
}: SkuGalleryDialogProps) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState<ProductImageFile[]>(initial);
  const [uploading, setUploading] = useState(false);
  const [saving, setSaving] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  // Re-seed draft whenever the dialog opens (or initial changes while open)
  // so cancelled edits don't leak into the next session.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (open) setDraft(initial);
  }, [open, initial]);

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

  const isBusy = uploading || saving;
  const mainImage = draft[0];
  const thumbnails = draft.slice(1);

  const handleUpload = useCallback(
    async (files: FileList | null) => {
      if (!files || files.length === 0) return;
      setUploading(true);
      try {
        const next: ProductImageFile[] = [];
        for (const file of Array.from(files)) {
          const res = await productImageService.uploadImage(file);
          next.push(res.data);
        }
        setDraft((prev) => [...prev, ...next]);
      } catch (e) {
        toast.error((e as Error).message || t("toast.product.image_upload_failed"));
      } finally {
        setUploading(false);
        if (inputRef.current) inputRef.current.value = "";
      }
    },
    [t]
  );

  const removeAt = (id: string) => {
    setDraft((prev) => prev.filter((img) => img.id !== id));
  };

  const handleDragEnd = useCallback((event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    setDraft((prev) => {
      const oldIndex = prev.findIndex((img) => img.id === active.id);
      const newIndex = prev.findIndex((img) => img.id === over.id);
      if (oldIndex < 0 || newIndex < 0) return prev;
      return arrayMove(prev, oldIndex, newIndex);
    });
  }, []);

  const handleConfirm = useCallback(async () => {
    setSaving(true);
    try {
      await onConfirm(draft);
      onOpenChange(false);
    } finally {
      setSaving(false);
    }
  }, [draft, onConfirm, onOpenChange]);

  return (
    <Dialog open={open} onOpenChange={(next) => !isBusy && onOpenChange(next)}>
      <DialogContent aria-describedby={undefined} className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t("hq.products.sku_edit.gallery.dialog_title")}</DialogTitle>
        </DialogHeader>

        <div className="rounded-lg border border-dashed bg-muted/30 p-4">
          {loading ? (
            <div className="flex h-24 w-full items-center justify-center">
              <Spinner className="size-5 text-muted-foreground" />
            </div>
          ) : (
            <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
              <SortableContext items={draft.map((img) => img.id)} strategy={rectSortingStrategy}>
                <div className="flex flex-wrap items-start gap-3">
                  {mainImage && (
                    <SortableGalleryTile
                      image={mainImage}
                      isMain
                      mainLabel={t("product.gallery.main_badge")}
                      onDelete={removeAt}
                      disabled={isBusy}
                    />
                  )}
                  {thumbnails.map((image) => (
                    <SortableGalleryTile
                      key={image.id}
                      image={image}
                      isMain={false}
                      mainLabel={t("product.gallery.main_badge")}
                      onDelete={removeAt}
                      disabled={isBusy}
                    />
                  ))}
                  <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    disabled={isBusy}
                    data-slot="sku-gallery-add"
                    className="flex size-24 shrink-0 flex-col items-center justify-center gap-1 rounded-md border border-dashed bg-background text-muted-foreground transition hover:border-primary/50 hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {uploading ? (
                      <Spinner className="size-4" />
                    ) : (
                      <>
                        <Plus className="size-4" />
                        <span className="text-[11px] font-medium">
                          {t("hq.products.sku_edit.gallery.add_image")}
                        </span>
                      </>
                    )}
                  </button>
                </div>
              </SortableContext>
            </DndContext>
          )}

          {!loading && draft.length > 0 && (
            <p className="mt-3 text-center text-[11px] text-muted-foreground">
              {t("hq.products.sku_edit.gallery.drop_hint")}
            </p>
          )}

          <input
            ref={inputRef}
            type="file"
            multiple
            accept="image/*"
            className="hidden"
            onChange={(e) => handleUpload(e.target.files)}
          />
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isBusy}
          >
            {t("common.cancel")}
          </Button>
          <Button type="button" onClick={handleConfirm} disabled={isBusy}>
            {saving && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
