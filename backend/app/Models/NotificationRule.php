<?php

/**
 * NotificationRule Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationRule\Models\NotificationRuleBaseModel;
use Database\Factories\NotificationRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationRule — add project-specific model logic here.
 */
class NotificationRule extends NotificationRuleBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationRuleFactory
    {
        return NotificationRuleFactory::new();
    }

    /**
     * Plan-023 M7 T7.13 guard — does an active rule with a resolvable audience
     * actually cover this (model, trigger) for the given organization?
     *
     * A hardcoded Phase A emitter must only short-circuit itself when the rule
     * engine has a *live replacement* that will actually deliver. A rule counts
     * as coverage only when it is active AND carries a non-empty
     * `action.audience_rule` — the M7 shadow rules (which only store an
     * `audience_name` placeholder) do NOT count, so flipping
     * NOTIFICATION_USE_RULES on can never silently disable a working emitter
     * that has no real replacement yet.
     */
    public static function hasActiveCoverage(string $modelAlias, string $triggerEvent, string $organizationId): bool
    {
        return static::query()
            ->where('trigger_model_type', $modelAlias)
            ->where('trigger_event', $triggerEvent)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNotNull('action->audience_rule')
            ->exists();
    }
}
