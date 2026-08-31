<?php

namespace App\Services\Customer;

use App\Models\Branch;
use App\Models\BranchReview;
use App\Services\FileUploadService;
use App\Services\Order\Contracts\OrderSnapshot;
use Illuminate\Support\Facades\DB;

class BranchReviewService
{
    public function __construct(
        private readonly FileUploadService $fileUploadService = new FileUploadService,
    ) {}

    /**
     * @param  array{service_rating?: int|null, staff_rating?: int|null, space_rating?: int|null, highlights?: array<int, string>|null, photo_file_ids?: array<int, string>|null}  $aspects
     */
    public function submit(OrderSnapshot $order, int $rating, ?string $comment = null, ?string $customerId = null, array $aspects = []): array
    {
        // Check if already reviewed (idempotent — return existing)
        $existing = BranchReview::where('customer_order_id', $order->aggregateId())->first();
        if ($existing) {
            return ['review' => $existing, 'already_existed' => true];
        }

        return DB::transaction(function () use ($order, $rating, $comment, $customerId, $aspects) {
            // Re-check inside transaction to handle race condition
            $existing = BranchReview::where('customer_order_id', $order->aggregateId())->lockForUpdate()->first();
            if ($existing) {
                return ['review' => $existing, 'already_existed' => true];
            }

            $highlights = $aspects['highlights'] ?? null;

            $review = BranchReview::create([
                'branch_id' => $order->branchId(),
                'customer_order_id' => $order->aggregateId(),
                'customer_id' => $customerId,
                'organization_id' => $order->organizationId(),
                'brand_id' => $order->brandId(),
                'rating' => $rating,
                // plan-026 aspect ratings + highlights
                'service_rating' => $aspects['service_rating'] ?? null,
                'staff_rating' => $aspects['staff_rating'] ?? null,
                'space_rating' => $aspects['space_rating'] ?? null,
                'highlights' => ! empty($highlights) ? array_values($highlights) : null,
                'comment' => $comment,
            ]);

            // Attach uploaded review photos (temp File rows → permanent, collection 'review').
            $photoFileIds = $aspects['photo_file_ids'] ?? null;
            if (! empty($photoFileIds)) {
                $this->fileUploadService->attachToModel($review, array_values($photoFileIds), 'review');
            }

            // Lock branch row and recalculate aggregate from scratch
            $branch = Branch::where('id', $order->branchId())->lockForUpdate()->first();
            if ($branch) {
                $avgRating = $this->calculateAvgRating($branch->id);
                $totalCount = BranchReview::where('branch_id', $branch->id)->count();

                $branch->review_avg_rating = $avgRating;
                $branch->review_total_count = $totalCount;
                $branch->save();
            }

            return ['review' => $review, 'already_existed' => false];
        });
    }

    /**
     * Check if an order can be reviewed, and return existing review if any.
     *
     * @return array{can_review: bool, existing_review: BranchReview|null, branch: array{id: string, name: string}}
     */
    public function getReviewableStatus(OrderSnapshot $order): array
    {
        $existingReview = BranchReview::where('customer_order_id', $order->aggregateId())
            ->with('photos')
            ->first();
        $branch = Branch::find($order->branchId());

        return [
            'can_review' => $existingReview === null,
            'existing_review' => $existingReview,
            'branch' => [
                'id' => $branch?->id,
                'name' => $branch?->name ?? 'Unknown',
            ],
        ];
    }

    /**
     * Submit a branch review for a closed order.
     *
     * Runs inside a DB transaction with lockForUpdate on the Branch row
     * to prevent aggregate drift from concurrent submissions.
     *
     * @return array{review: BranchReview, already_existed: bool}
     */
    /**
     * Calculate the average rating for a branch from all its reviews.
     *
     * Returns null when no reviews exist (avoids "0.00" display).
     */
    public function calculateAvgRating(string $branchId): ?float
    {
        $avg = BranchReview::where('branch_id', $branchId)->avg('rating');

        return $avg !== null ? round((float) $avg, 2) : null;
    }
}
