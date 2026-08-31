<?php

/**
 * ProductReview Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductReview\Models\ProductReviewBaseModel;
use Database\Factories\ProductReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductReview — add project-specific model logic here.
 *
 * The Product denormalized aggregates (review_total_count / review_up_count /
 * review_rating_sum) are rolled back on delete by `ProductReview::deleting` hook
 * (registered in AppServiceProvider). This model used to ALSO register a
 * `deleting` hook doing the same reversal (plan-025); plan-047 added the
 * observer without removing the hook, so every review delete decremented twice
 * and a reviewed order's force-delete left the counts at -1. The observer is the
 * single owner now.
 */
class ProductReview extends ProductReviewBaseModel
{
    use HasFactory;

    protected static function newFactory(): ProductReviewFactory
    {
        return ProductReviewFactory::new();
    }
}
