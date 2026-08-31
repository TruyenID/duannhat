<?php

/**
 * NotificationRecipient Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationRecipient\Models\NotificationRecipientBaseModel;
use Database\Factories\NotificationRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * NotificationRecipient — per-recipient fan-out with independent interaction
 * state (seen / read / dismissed). Polymorphic `recipient` points at User or
 * Device via the shared morph map.
 */
class NotificationRecipient extends NotificationRecipientBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationRecipientFactory
    {
        return NotificationRecipientFactory::new();
    }

    /**
     * The polymorphic recipient (User or Device) for this fan-out row.
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Filter to rows addressed to `$recipient`. Morph-aware: works for any
     * model that implements `App\Contracts\Notifiable` (User, Device, …).
     */
    public function scopeForRecipient(Builder $query, Model $recipient): Builder
    {
        return $query
            ->where('recipient_type', $recipient->getMorphClass())
            ->where('recipient_id', $recipient->getKey());
    }

    /** Rows the user has not clicked/opened yet. */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** Rows that have not entered the user's viewport yet. */
    public function scopeUnseen(Builder $query): Builder
    {
        return $query->whereNull('seen_at');
    }

    /** Rows the user has NOT archived (default inbox list excludes dismissed). */
    public function scopeNotDismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Idempotent — only sets `seen_at` if still null. Second call is a no-op.
     *
     * Implemented as a single atomic UPDATE with `COALESCE` so two concurrent
     * callers can't both pass a null-check and then both write — the FIRST
     * timestamp wins by construction (issue #172). Same shape as the bulk
     * `NotificationService::bulkMark*` methods.
     */
    public function markSeen(): void
    {
        $this->newQuery()->whereKey($this->getKey())->update([
            'seen_at' => DB::raw('COALESCE(seen_at, '.$this->nowExpression().')'),
            'updated_at' => DB::raw($this->nowExpression()),
        ]);
        $this->refresh();
    }

    /**
     * Idempotent — sets `read_at` (and `seen_at` if still null, since read
     * implies seen). Subsequent calls leave the first timestamps in place.
     */
    public function markRead(): void
    {
        $now = $this->nowExpression();
        $this->newQuery()->whereKey($this->getKey())->update([
            'seen_at' => DB::raw("COALESCE(seen_at, {$now})"),
            'read_at' => DB::raw("COALESCE(read_at, {$now})"),
            'updated_at' => DB::raw($now),
        ]);
        $this->refresh();
    }

    /** Idempotent — only sets `dismissed_at` if still null. */
    public function markDismissed(): void
    {
        $this->newQuery()->whereKey($this->getKey())->update([
            'dismissed_at' => DB::raw('COALESCE(dismissed_at, '.$this->nowExpression().')'),
            'updated_at' => DB::raw($this->nowExpression()),
        ]);
        $this->refresh();
    }

    /**
     * Cross-driver "now()" SQL fragment — MySQL/Postgres ship `NOW()` natively;
     * the test SQLite driver doesn't, so emit `datetime('now')` for it.
     * Matches the idiom used by `NotificationService::bulkMark*`.
     */
    private function nowExpression(): string
    {
        return $this->getConnection()->getDriverName() === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
    }
}
