<?php

/**
 * Notification Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\NotificationPriorityEnum;
use App\Omnify\Modules\Notification\Models\NotificationBaseModel;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Notification — shared content row for a single event (Decision 1).
 *
 * Fans out to N recipient rows in `notification_recipients`. Both polymorphic
 * actor and subject columns resolve through the morph map registered in
 * `OmnifyServiceProvider::boot` (TitleCase aliases — see NOTES.md).
 */
class Notification extends NotificationBaseModel
{
    use HasFactory, Prunable;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }

    /**
     * The polymorphic actor that triggered the event (User or Device, or null
     * for system/service events). Decision 4 — resolved through the shared
     * morph map.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The polymorphic subject the event is about (Recipe, CustomerOrder,
     * StockAlert, …). Null-together with `subject_type`.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Shorter alias for the auto-generated `notificationRecipients()` inverse
     * — matches the plan-008 design spec.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_id');
    }

    /**
     * Soft lookup to `notification_templates.key` (plan-012 T2.3). No DB FK —
     * a deleted / renamed template renders blank and `TemplateRenderer` logs
     * a warning. Arch test guards against Phase A `type` strings missing a
     * template row.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_key', 'key');
    }

    /**
     * Retention query for `php artisan model:prune` (Decision 7).
     *
     * Per-priority cutoffs:
     *   low    → 7 days
     *   normal → 30 days
     *   high   → 30 days
     *   urgent → 90 days
     *
     * Recipient + delivery rows cascade on parent delete.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(function (Builder $q): void {
                $q->where('priority', NotificationPriorityEnum::Low->value)
                    ->where('created_at', '<', now()->subDays(7));
            })
            ->orWhere(function (Builder $q): void {
                $q->whereIn('priority', [
                    NotificationPriorityEnum::Normal->value,
                    NotificationPriorityEnum::High->value,
                ])
                    ->where('created_at', '<', now()->subDays(30));
            })
            ->orWhere(function (Builder $q): void {
                $q->where('priority', NotificationPriorityEnum::Urgent->value)
                    ->where('created_at', '<', now()->subDays(90));
            });
    }
}
