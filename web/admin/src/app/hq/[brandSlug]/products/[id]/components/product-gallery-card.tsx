"use client";

import type { CSSProperties } from "react";

/**
 * ProductGalleryCard
 *
 * Layout:
 *   ┌──────────────────────────────┐
 *   │ Thư viện ảnh         [+ Thêm] │
 *   ├──────────────────────────────┤
 *   │ ┌──────────────────────────┐ │
 *   │ │                          │ │
 *   │ │     MAIN IMAGE (big)     │ │
 *   │ │        [Main]            │ │
 *   │ └──────────────────────────┘ │
 *   │                              │
 *   │ ┌───┐┌───┐┌───┐┌───┐        │
 *   │ │ 1 ││ 2 ││ 3 ││ 4 │ thumbs │
 *   │ └───┘└───┘└───┘└───┘        │
 *   └──────────────────────────────┘
 *
 * - Ảnh đầu tiên (sort_order = 0) → hiển thị to, aspect-square, có "Main" badge.
 * - Các ảnh còn lại → thumbnails 4 cột dưới main.
 * - Kéo thả đổi thứ tự (bao gồm kéo 1 thumbnail lên "main slot" để đổi ảnh chính).
 * - Upload / delete auto-sync với backend sau mỗi thao tác.
 */

import { useCallback, useId, useRef, useState } from "react";
import Image from "next/image";
import { DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from "@dnd-kit/core";
import { SortableContext, arrayMove, rectSortingStrategy, useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { GripVertical, ImageIcon, Plus, X } from "lucide-react";
import { toast } from "sonner";
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Spinner } from "@godxjp/ui";

import { useTranslation } from "@/providers/app-provider";
import { productImageService, type ProductImageFile } from "@/services/product-image-service";

// =========================================================================
//  Sortable main image (big, primary display)
// =========================================================================

interface SortableMainImageProps {
  image: ProductImageFile;
  isDisabled: boolean;
  mainLabel: string;
  onDelete: (id: string) => void;
}

function SortableMainImage({ image, isDisabled, mainLabel, onDelete }: SortableMainImageProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: image.id,
    disabled: isDisabled,
  });

  const style: CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.4 : 1,
    zIndex: isDragging ? 10 : undefined,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      data-slot="gallery-main"
      className="group relative aspect-square w-full overflow-hidden rounded-lg border-2 border-primary/30 bg-muted shadow-sm ring-1 ring-primary/10"
    >
      <Image
        src={image.url}
        alt={image.original_name}
        fill
        sizes="(max-width: 640px) 100vw, 320px"
        className="object-cover"
      />

      <Badge
        variant="default"
        className="absolute top-2 left-2 gap-1 px-2 py-0.5 text-xs font-semibold shadow"
      >
        <ImageIcon className="size-3" />
        {mainLabel}
      </Badge>

      {!isDisabled && (
        <>
          <button
            type="button"
            aria-label="Delete image"
            onClick={() => onDelete(image.id)}
            className="absolute top-2 right-2 hidden rounded-full bg-black/70 p-1.5 text-white shadow transition group-hover:flex hover:bg-destructive"
          >
            <X className="size-4" />
          </button>

          <div
            {...listeners}
            {...attributes}
            aria-label="Drag to reorder"
            className="absolute right-2 bottom-2 hidden cursor-grab rounded-md bg-black/70 p-1.5 text-white shadow group-hover:flex active:cursor-grabbing"
          >
            <GripVertical className="size-4" />
          </div>
        </>
      )}
    </div>
  );
}

// =========================================================================
//  Sortable thumbnail (compact)
// =========================================================================

interface SortableThumbnailProps {
  image: ProductImageFile;
  isDisabled: boolean;
  onDelete: (id: string) => void;
}

function SortableThumbnail({ image, isDisabled, onDelete }: SortableThumbnailProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: image.id,
    disabled: isDisabled,
  });

  const style: CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.4 : 1,
    zIndex: isDragging ? 10 : undefined,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      data-slot="gallery-thumb"
      className="group relative aspect-square w-full cursor-grab overflow-hidden rounded-md border border-border bg-muted transition hover:border-primary/50 hover:shadow-sm active:cursor-grabbing"
    >
      <Image
        src={image.url}
        alt={image.original_name}
        fill
        sizes="100px"
        className="object-cover"
      />

      {!isDisabled && (
        <button
          type="button"
          aria-label="Delete image"
          onClick={(e) => {
            e.stopPropagation();
            onDelete(image.id);
          }}
          onPointerDown={(e) => e.stopPropagation()}
          className="absolute top-1 right-1 hidden rounded-full bg-black/70 p-0.5 text-white transition group-hover:flex hover:bg-destructive"
        >
          <X className="size-3" />
        </button>
      )}
    </div>
  );
}

// =========================================================================
//  Empty state upload area
// =========================================================================

interface EmptyUploadAreaProps {
  label: string;
  subLabel: string;
  disabled: boolean;
  htmlFor: string;
}

function EmptyUploadArea({ label, subLabel, disabled, htmlFor }: EmptyUploadAreaProps) {
  return (
    <label
      htmlFor={htmlFor}
      aria-disabled={disabled}
      className={`flex aspect-square w-full flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed border-border bg-muted/30 text-center text-muted-foreground transition ${
        disabled
          ? "cursor-not-allowed opacity-60"
          : "cursor-pointer hover:border-primary/50 hover:bg-muted/50"
      }`}
    >
      <div className="rounded-full bg-muted p-3">
        <ImageIcon className="size-6 opacity-60" />
      </div>
      <div className="px-4">
        <p className="text-sm font-medium text-foreground">{label}</p>
        <p className="mt-1 text-xs text-muted-foreground">{subLabel}</p>
      </div>
    </label>
  );
}

// =========================================================================
//  Main component
// =========================================================================

export interface ProductGalleryCardProps {
  initialImages: ProductImageFile[];
  disabled?: boolean;

  /**
   * When provided, the gallery runs in **live** mode: every upload / delete /
   * reorder auto-syncs with `POST .../products/{productId}/images/sync`.
   *
   * When omitted, the gallery runs in **staged** mode: uploads still create
   * temp files on the backend, but nothing is attached to a product yet. The
   * parent tracks the current ordered list via `onImagesChange` and submits
   * the file UUIDs as `gallery_file_ids` when creating the product.
   */
  productId?: string;
  /** Required for live mode; unused in staged mode. */
  brandSlug?: string;

  /** Called whenever the image list changes (both modes). */
  onImagesChange?: (images: ProductImageFile[]) => void;
}

export function ProductGalleryCard({
  brandSlug,
  productId,
  initialImages,
  disabled = false,
  onImagesChange,
}: ProductGalleryCardProps) {
  const { t } = useTranslation();

  const isStaged = !productId;

  const [images, setImages] = useState<ProductImageFile[]>(() =>
    [...initialImages].sort((a, b) => a.sort_order - b.sort_order)
  );
  const [syncing, setSyncing] = useState(false);
  const [uploading, setUploading] = useState(false);

  const fileInputRef = useRef<HTMLInputElement>(null);
  const fileInputId = useId();

  const isDisabled = disabled || syncing || uploading;
  const mainImage = images[0];
  const thumbnails = images.slice(1);

  // ---------------------------------------------------------------------------
  //  State updater — bubbles changes to the parent in both modes
  // ---------------------------------------------------------------------------

  const applyImages = useCallback(
    (next: ProductImageFile[]) => {
      setImages(next);
      onImagesChange?.(next);
    },
    [onImagesChange]
  );

  // ---------------------------------------------------------------------------
  //  Sync helper — live mode only
  // ---------------------------------------------------------------------------

  const syncToBackend = useCallback(
    async (ordered: ProductImageFile[]) => {
      // Staged mode: no backend call. Parent will submit gallery_file_ids on
      // product create / update and the backend will attach + reorder then.
      if (isStaged || !brandSlug || !productId) {
        applyImages(ordered);
        return;
      }

      setSyncing(true);
      try {
        const result = await productImageService.syncImages(
          brandSlug,
          productId,
          ordered.map((img) => img.id)
        );
        applyImages(result.data);
        toast.success(t("toast.product.gallery_synced"));
      } catch (e) {
        toast.error((e as Error).message || t("toast.product.gallery_sync_failed"));
      } finally {
        setSyncing(false);
      }
    },
    [applyImages, brandSlug, isStaged, productId, t]
  );

  // ---------------------------------------------------------------------------
  //  Upload
  // ---------------------------------------------------------------------------

  const handleUpload = useCallback(
    async (files: FileList | null) => {
      if (!files || files.length === 0) {
        return;
      }

      setUploading(true);
      const newImages: ProductImageFile[] = [];

      try {
        for (const file of Array.from(files)) {
          const res = await productImageService.uploadImage(file);
          newImages.push(res.data);
        }
        toast.success(t("toast.product.image_uploaded"));
      } catch (e) {
        toast.error((e as Error).message || t("toast.product.image_upload_failed"));
        setUploading(false);
        return;
      } finally {
        setUploading(false);
        if (fileInputRef.current) {
          fileInputRef.current.value = "";
        }
      }

      const updated = [...images, ...newImages];
      await syncToBackend(updated);
    },
    [images, syncToBackend, t]
  );

  // ---------------------------------------------------------------------------
  //  Delete
  // ---------------------------------------------------------------------------

  const handleDelete = useCallback(
    async (id: string) => {
      const updated = images.filter((img) => img.id !== id);
      await syncToBackend(updated);
    },
    [images, syncToBackend]
  );

  // ---------------------------------------------------------------------------
  //  Drag-and-drop
  // ---------------------------------------------------------------------------

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

  const handleDragEnd = useCallback(
    async (event: DragEndEvent) => {
      const { active, over } = event;
      if (!over || active.id === over.id) {
        return;
      }

      const oldIndex = images.findIndex((img) => img.id === active.id);
      const newIndex = images.findIndex((img) => img.id === over.id);
      const reordered = arrayMove(images, oldIndex, newIndex);
      await syncToBackend(reordered);
    },
    [images, syncToBackend]
  );

  // ---------------------------------------------------------------------------
  //  Render
  // ---------------------------------------------------------------------------

  return (
    <Card data-slot="product-gallery-card">
      <CardHeader className="flex flex-row items-center justify-between gap-2 px-4 pt-4 pb-3">
        <div className="flex min-w-0 items-center gap-2">
          <ImageIcon className="size-4 shrink-0 text-muted-foreground" />
          <CardTitle className="truncate text-sm font-medium">
            {t("product.gallery.title")}
          </CardTitle>
          {images.length > 0 && (
            <span className="shrink-0 text-xs text-muted-foreground">{images.length}</span>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-2">
          {(uploading || syncing) && <Spinner className="size-3.5" />}
          {!disabled && (
            <Button
              asChild
              variant="outline"
              size="sm"
              className="h-7 gap-1.5 text-xs"
              disabled={isDisabled}
            >
              <label htmlFor={fileInputId} className={isDisabled ? "pointer-events-none" : ""}>
                <Plus className="size-3.5" />
                {t("product.gallery.add_images")}
              </label>
            </Button>
          )}
          <input
            ref={fileInputRef}
            id={fileInputId}
            type="file"
            multiple
            accept="image/*"
            disabled={isDisabled}
            className="sr-only"
            tabIndex={-1}
            aria-hidden="true"
            onChange={(e) => handleUpload(e.target.files)}
          />
        </div>
      </CardHeader>

      <CardContent className="space-y-3 px-4 pb-4">
        {images.length === 0 ? (
          <EmptyUploadArea
            label={t("product.gallery.add_images")}
            subLabel={t("product.gallery.empty")}
            disabled={isDisabled}
            htmlFor={fileInputId}
          />
        ) : (
          <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
            <SortableContext items={images.map((img) => img.id)} strategy={rectSortingStrategy}>
              {/* Main (big) image */}
              {mainImage && (
                <SortableMainImage
                  image={mainImage}
                  isDisabled={isDisabled}
                  mainLabel={t("product.gallery.main_badge")}
                  onDelete={handleDelete}
                />
              )}

              {/* Thumbnail strip */}
              {thumbnails.length > 0 && (
                <div className="grid grid-cols-4 gap-2">
                  {thumbnails.map((image) => (
                    <SortableThumbnail
                      key={image.id}
                      image={image}
                      isDisabled={isDisabled}
                      onDelete={handleDelete}
                    />
                  ))}
                </div>
              )}
            </SortableContext>
          </DndContext>
        )}

        {images.length > 1 && !disabled && (
          <p className="pt-1 text-center text-[11px] text-muted-foreground">
            {t("product.gallery.drop_hint")}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
