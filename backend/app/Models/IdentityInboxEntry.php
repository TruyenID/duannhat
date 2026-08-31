<?php

declare(strict_types=1);

namespace App\Models;

use App\Omnify\Modules\IdentityInboxEntry\Models\IdentityInboxEntryBaseModel;

/**
 * One identity event received from Platform (#3199, ADR 0002).
 *
 * Append-only by intent: after insert the only field that changes is
 * `applied_at` (plus `apply_error` when something went wrong). A received event
 * is a statement about something that already happened upstream — editing one
 * rewrites history rather than correcting it.
 *
 * Table, fillable and casts come from the generated base, which comes from
 * `schemas/Backend/Sso/IdentityInboxEntry.yaml`. Restating them here would give
 * the schema a second, silently divergent source of truth — the class of drift
 * `.githooks/pre-commit` blocks hand-written migrations to prevent.
 */
class IdentityInboxEntry extends IdentityInboxEntryBaseModel
{
    /**
     * Resource types this app mirrors.
     *
     * The feed is shared across services, so a consumer takes what concerns it
     * and counts the rest as ignored — never as applied.
     */
    public const TYPE_ORGANIZATION = 'organization';

    public const TYPE_BRAND = 'brand';

    public const TYPE_BRANCH = 'branch';
}
