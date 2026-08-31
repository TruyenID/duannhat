/**
 * Product Image Service
 *
 * Manages the `gallery` file collection for a Product.
 *
 * Upload flow:
 *   1. uploadImage(file) → POST /api/v1/files/upload → returns temp ProductImageFile
 *   2. syncImages(brandSlug, productId, fileIds) → POST /hq/{brand}/products/{id}/images/sync
 *      → backend attaches, reorders and soft-deletes in one shot via Product::syncFiles()
 *
 * The first item in the gallery (sort_order = 0) is the primary / main image.
 * Delete flow: remove the ID from local state, call syncImages with the remaining ordered list.
 */

import { apiFetch } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export interface ProductImageFile {
  /** File UUID */
  id: string;
  /** Public URL (S3 / MinIO signed URL from backend) */
  url: string;
  /** Position in the gallery — 0 = main image */
  sort_order: number;
  /** Original uploaded filename */
  original_name: string;
  /** False while still temporary (just uploaded, not yet synced) */
  is_permanent: boolean;
  /** MIME type */
  mime_type?: string;
  /** File size in bytes */
  size?: number;
}

// =========================================================================
//  Helpers
// =========================================================================

function filesUploadUrl(): string {
  return "/api/v1/files/upload";
}

function syncUrl(brandSlug: string, productId: string): string {
  return `/api/v1/hq/${brandSlug}/products/${productId}/images/sync`;
}

// =========================================================================
//  Service
// =========================================================================

export const productImageService = {
  /**
   * Upload a single image to temporary storage.
   *
   * Uses the shared POST /api/v1/files/upload endpoint — no brand scope needed.
   * The returned file has `is_permanent: false`. Call syncImages() afterwards
   * to attach the file to the product and make it permanent.
   */
  uploadImage: (file: File): Promise<{ data: ProductImageFile }> => {
    const body = new FormData();
    body.append("file", file);
    body.append("collection", "gallery");
    return apiFetch<{ data: ProductImageFile }>(filesUploadUrl(), {
      method: "POST",
      body,
    });
  },

  /**
   * Full-replace sync of the product gallery.
   *
   * Sends the complete ordered list of File UUIDs. The backend will:
   *   - Attach any temp files (making them permanent)
   *   - Update sort_order from array position (index 0 = main image)
   *   - Soft-delete any gallery files no longer in the list
   *
   * Call this after every upload, delete, or drag-reorder.
   */
  syncImages: (
    brandSlug: string,
    productId: string,
    fileIds: string[]
  ): Promise<{ data: ProductImageFile[] }> =>
    apiFetch<{ data: ProductImageFile[] }>(syncUrl(brandSlug, productId), {
      method: "POST",
      body: JSON.stringify({ file_ids: fileIds }),
    }),
};
