<?php

/**
 * BranchReview Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\BranchReview\Models\BranchReviewBaseModel;
use Database\Factories\BranchReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * BranchReview — add project-specific model logic here.
 */
class BranchReview extends BranchReviewBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): BranchReviewFactory
    {
        return BranchReviewFactory::new();
    }

    /**
     * Review photos uploaded by the customer (polymorphic File rows,
     * collection `review`). Used by admin to display uploaded images.
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable')
            ->where('collection', 'review')
            ->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            // The rating columns are VARCHAR in the DB (Omnify TinyInteger mapping),
            // so cast them to int to serialize as numbers, not strings.
            'rating' => 'integer',
            'service_rating' => 'integer',
            'staff_rating' => 'integer',
            'space_rating' => 'integer',
        ];
    }

    /**
     * Serialize this review (including plan-026 aspect ratings, highlights,
     * and uploaded photos) for API read responses. Loads the `photos`
     * relation if it is not already eager-loaded.
     *
     * @return array{
     *     id: string,
     *     rating: int,
     *     service_rating: int|null,
     *     staff_rating: int|null,
     *     space_rating: int|null,
     *     highlights: array<int, string>,
     *     comment: string|null,
     *     photos: array<int, array{id: string, url: string|null}>,
     *     created_at: string|null,
     * }
     */
    public function toReviewArray(): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'service_rating' => $this->service_rating,
            'staff_rating' => $this->staff_rating,
            'space_rating' => $this->space_rating,
            'highlights' => $this->highlights ?? [],
            'comment' => $this->comment,
            'photos' => $this->photos->map(fn (File $photo): array => [
                'id' => $photo->id,
                'url' => $photo->getUrl(),
            ])->all(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
