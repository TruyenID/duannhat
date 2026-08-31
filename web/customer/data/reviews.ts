import { apiFetch } from "@/lib/api";

export type ReviewSentiment = "up" | "down";

export interface ReviewableItem {
  order_item_id: string;
  product_id: string;
  name: string;
  image: string | null;
  variant_name: string | null;
  /** Unit price (JPY) for display on the review card. */
  price: number;
  already_reviewed: boolean;
}

export interface ReviewableResponse {
  data: {
    order_id: string;
    /** ISO 4217 currency the order was created in (from its branch). */
    currency: string;
    items: ReviewableItem[];
  };
}

export interface ReviewPayload {
  reviews: {
    order_item_id: string;
    product_id: string;
    /** plan-026: 1-5 star rating (backend derives sentiment from it). */
    rating: number;
    tags?: string[] | null;
    comment?: string | null;
  }[];
}

export interface SubmitReviewResponse {
  data: {
    created: number;
    skipped: number;
  };
}

export async function getReviewable(
  orderId: string,
): Promise<{ currency: string; items: ReviewableItem[] }> {
  const res = await apiFetch<ReviewableResponse>(
    `/api/v1/customer/orders/${orderId}/reviewable`
  );
  return { currency: res.data.currency ?? "JPY", items: res.data.items };
}

export async function submitReviews(
  orderId: string,
  payload: ReviewPayload
): Promise<SubmitReviewResponse["data"]> {
  const res = await apiFetch<SubmitReviewResponse>(
    `/api/v1/customer/orders/${orderId}/reviews`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    }
  );
  return res.data;
}

// ─── Branch Review (plan-026) ─────────────────────────────────────────────

export interface BranchReviewableResponse {
  data: {
    can_review: boolean;
    existing_review: {
      id: string;
      rating: number;
      comment: string | null;
      created_at: string;
    } | null;
    branch: {
      id: string;
      name: string;
    };
  };
}

export interface BranchReviewPayload {
  rating: number;
  /** plan-026 aspect ratings (1-5) + highlight chips + uploaded photo ids. */
  service_rating?: number | null;
  staff_rating?: number | null;
  space_rating?: number | null;
  highlights?: string[] | null;
  photo_file_ids?: string[] | null;
  comment?: string | null;
}

export interface UploadedReviewPhoto {
  id: string;
  url: string;
}

/** Upload review photos (multipart). Returns temp File ids + URLs to preview. */
export async function uploadReviewPhotos(
  orderId: string,
  files: File[]
): Promise<UploadedReviewPhoto[]> {
  const body = new FormData();
  files.forEach((f) => body.append("photos[]", f));
  const res = await apiFetch<{ data: UploadedReviewPhoto[] }>(
    `/api/v1/customer/orders/${orderId}/review-photos`,
    { method: "POST", body }
  );
  return res.data;
}

export interface BranchReviewResponse {
  data: {
    id: string;
    rating: number;
    comment: string | null;
    created_at: string;
  };
}

export async function getBranchReviewable(
  orderId: string
): Promise<BranchReviewableResponse["data"]> {
  const res = await apiFetch<BranchReviewableResponse>(
    `/api/v1/customer/orders/${orderId}/branch-reviewable`
  );
  return res.data;
}

export async function submitBranchReview(
  orderId: string,
  payload: BranchReviewPayload
): Promise<BranchReviewResponse["data"]> {
  const res = await apiFetch<BranchReviewResponse>(
    `/api/v1/customer/orders/${orderId}/branch-review`,
    {
      method: "POST",
      body: JSON.stringify(payload),
    }
  );
  return res.data;
}
