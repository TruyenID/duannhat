"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ThumbsUp, ThumbsDown, Check, Star } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetFooter,
} from "@/components/ui/sheet";
import {
  getReviewable,
  submitReviews,
  getBranchReviewable,
  submitBranchReview,
  type ReviewableItem,
  type ReviewSentiment,
} from "@/data/reviews";

interface OrderReviewSheetProps {
  orderId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

interface ItemReview {
  sentiment: ReviewSentiment | null;
  comment: string;
  expandComment: boolean;
}

export default function OrderReviewSheet({
  orderId,
  open,
  onOpenChange,
}: OrderReviewSheetProps) {
  const t = useTranslations("review");
  const [items, setItems] = useState<ReviewableItem[]>([]);
  const [reviews, setReviews] = useState<Record<string, ItemReview>>({});
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  // Branch review state (plan-026)
  const [branchName, setBranchName] = useState("");
  const [branchRating, setBranchRating] = useState<number>(0);
  const [branchComment, setBranchComment] = useState("");
  const [branchExpandComment, setBranchExpandComment] = useState(false);
  const [canReviewBranch, setCanReviewBranch] = useState(false);

  const fetchItems = useCallback(async () => {
    setLoading(true);
    try {
      const [productData, branchData] = await Promise.all([
        getReviewable(orderId),
        getBranchReviewable(orderId).catch(() => null),
      ]);
      setItems(productData.items);
      const initial: Record<string, ItemReview> = {};
      for (const item of productData.items) {
        if (!item.already_reviewed) {
          initial[item.order_item_id] = {
            sentiment: null,
            comment: "",
            expandComment: false,
          };
        }
      }
      setReviews(initial);

      // Branch review status
      if (branchData) {
        setBranchName(branchData.branch.name);
        setCanReviewBranch(branchData.can_review);
      }
    } catch {
      // silently fail — the sheet just shows empty
    } finally {
      setLoading(false);
    }
  }, [orderId]);

  useEffect(() => {
    if (open) {
      fetchItems();
    }
  }, [open, fetchItems]);

  const setSentiment = (orderItemId: string, sentiment: ReviewSentiment) => {
    setReviews((prev) => ({
      ...prev,
      [orderItemId]: {
        ...prev[orderItemId],
        sentiment:
          prev[orderItemId]?.sentiment === sentiment ? null : sentiment,
      },
    }));
  };

  const setComment = (orderItemId: string, comment: string) => {
    setReviews((prev) => ({
      ...prev,
      [orderItemId]: { ...prev[orderItemId], comment },
    }));
  };

  const toggleComment = (orderItemId: string) => {
    setReviews((prev) => ({
      ...prev,
      [orderItemId]: {
        ...prev[orderItemId],
        expandComment: !prev[orderItemId]?.expandComment,
      },
    }));
  };

  const reviewableItems = items.filter((i) => !i.already_reviewed);
  const hasAnySelection =
    Object.values(reviews).some((r) => r.sentiment !== null) ||
    branchRating > 0;

  const handleSubmit = async () => {
    const payload = Object.entries(reviews)
      .filter(([, r]) => r.sentiment !== null)
      .map(([orderItemId, r]) => {
        const item = items.find((i) => i.order_item_id === orderItemId);
        return {
          order_item_id: orderItemId,
          product_id: item!.product_id,
          // Legacy binary sheet → map to star rating (👍=5, 👎=1) for the
          // plan-026 rating-based API.
          rating: r.sentiment === "up" ? 5 : 1,
          comment: r.comment || null,
        };
      });

    setSubmitting(true);
    try {
      const promises: Promise<unknown>[] = [];

      // Submit product reviews
      if (payload.length > 0) {
        promises.push(submitReviews(orderId, { reviews: payload }));
      }

      // Submit branch review (plan-026)
      if (branchRating > 0 && canReviewBranch) {
        promises.push(
          submitBranchReview(orderId, {
            rating: branchRating,
            comment: branchComment || null,
          })
        );
      }

      if (promises.length === 0) return;

      await Promise.all(promises);
      toast.success(t("thankYou"));
      onOpenChange(false);
    } catch {
      // API error — sonner will show generic error
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" className="max-h-[85vh] rounded-t-2xl">
        <SheetHeader>
          <SheetTitle>{t("title")}</SheetTitle>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto px-4 py-2">
          {loading ? (
            <div className="space-y-4 py-4">
              {[1, 2, 3].map((i) => (
                <div
                  key={i}
                  className="h-16 animate-pulse rounded-lg bg-muted"
                />
              ))}
            </div>
          ) : (
            <>
              {/* Branch rating section (plan-026) */}
              {canReviewBranch && (
                <div className="mb-4 rounded-xl border border-border/50 bg-card p-4">
                  <p className="mb-2 text-sm font-medium">
                    {t("branchRating", { branch: branchName })}
                  </p>
                  <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <button
                        aria-label={t('rateStars', { count: star })}
                        key={star}
                        type="button"
                        onClick={() =>
                          setBranchRating(branchRating === star ? 0 : star)
                        }
                        className="p-0.5 transition-transform active:scale-110"
                      >
                        <Star
                          className={`h-7 w-7 ${
                            star <= branchRating
                              ? "fill-amber-400 text-amber-400"
                              : "text-muted-foreground/40"
                          }`}
                        />
                      </button>
                    ))}
                  </div>
                  {branchRating > 0 && (
                    <div className="mt-2">
                      {!branchExpandComment ? (
                        <button
                          type="button"
                          onClick={() => setBranchExpandComment(true)}
                          className="text-xs text-muted-foreground hover:text-foreground"
                        >
                          {t("branchComment")}
                        </button>
                      ) : (
                        <textarea
                          value={branchComment}
                          onChange={(e) => setBranchComment(e.target.value)}
                          placeholder={t("branchComment")}
                          maxLength={2000}
                          rows={2}
                          className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                      )}
                    </div>
                  )}
                </div>
              )}

              {reviewableItems.length === 0 && !canReviewBranch ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  {t("noItems")}
                </p>
              ) : (
                <div className="space-y-3">
                  {items.map((item) => {
                    const review = reviews[item.order_item_id];
                    const isReviewed = item.already_reviewed;

                    return (
                      <div
                        key={item.order_item_id}
                        className={`rounded-xl border p-3 transition-colors ${
                          isReviewed
                            ? "border-border/30 bg-muted/30 opacity-60"
                            : "border-border/50 bg-card"
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          {/* Item image */}
                          <div className="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-muted">
                            {item.image ? (
                              <img
                                src={item.image}
                                alt={item.name}
                                className="h-full w-full object-cover"
                              />
                            ) : (
                              <div className="flex h-full w-full items-center justify-center text-lg text-muted-foreground/30">
                                🍜
                              </div>
                            )}
                          </div>

                          {/* Item name */}
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium leading-tight line-clamp-2">
                              {item.name}
                            </p>
                            {isReviewed && (
                              <span className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                <Check className="h-3 w-3" />
                                {t("alreadyReviewed")}
                              </span>
                            )}
                          </div>

                          {/* Thumbs buttons */}
                          {!isReviewed && review && (
                            <div className="flex shrink-0 gap-2">
                              <button
                                aria-label={`${t('thumbsUp')}: ${item.name}`}
                                type="button"
                                onClick={() =>
                                  setSentiment(item.order_item_id, "up")
                                }
                                className={`flex h-9 w-9 items-center justify-center rounded-full border transition-all ${
                                  review.sentiment === "up"
                                    ? "border-emerald-500 bg-emerald-50 text-emerald-600"
                                    : "border-border bg-background text-muted-foreground hover:border-emerald-300 hover:text-emerald-500"
                                }`}
                              >
                                <ThumbsUp className="h-4 w-4" />
                              </button>
                              <button
                                aria-label={`${t('thumbsDown')}: ${item.name}`}
                                type="button"
                                onClick={() =>
                                  setSentiment(item.order_item_id, "down")
                                }
                                className={`flex h-9 w-9 items-center justify-center rounded-full border transition-all ${
                                  review.sentiment === "down"
                                    ? "border-red-400 bg-red-50 text-red-500"
                                    : "border-border bg-background text-muted-foreground hover:border-red-300 hover:text-red-400"
                                }`}
                              >
                                <ThumbsDown className="h-4 w-4" />
                              </button>
                            </div>
                          )}
                        </div>

                        {/* Comment (expandable) */}
                        {!isReviewed && review?.sentiment && (
                          <div className="mt-2">
                            {!review.expandComment ? (
                              <button
                                type="button"
                                onClick={() =>
                                  toggleComment(item.order_item_id)
                                }
                                className="text-xs text-muted-foreground hover:text-foreground"
                              >
                                {t("commentPlaceholder")}
                              </button>
                            ) : (
                              <textarea
                                value={review.comment}
                                onChange={(e) =>
                                  setComment(
                                    item.order_item_id,
                                    e.target.value
                                  )
                                }
                                placeholder={t("commentPlaceholder")}
                                maxLength={1000}
                                rows={2}
                                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:ring-1 focus:ring-ring"
                              />
                            )}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </>
          )}
        </div>

        <SheetFooter>
          <div className="flex w-full gap-3">
            <Button
              variant="outline"
              className="flex-1"
              onClick={() => onOpenChange(false)}
            >
              {t("skip")}
            </Button>
            <Button
              className="flex-1"
              style={{ backgroundColor: "#27A14F" }}
              disabled={!hasAnySelection || submitting}
              onClick={handleSubmit}
            >
              {submitting ? "..." : t("submit")}
            </Button>
          </div>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
