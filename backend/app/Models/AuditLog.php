<?php

/**
 * AuditLog Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\AuditLog\Models\AuditLogBaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log row written by `App\Traits\AuditsActivity::logAudit`.
 *
 * Kept intentionally thin — no relations, no casts beyond metadata JSON —
 * so existing models that opt into auditing don't pay for load they don't
 * use. Tests assert shape via `assertDatabaseHas('audit_logs', ...)`.
 *
 * Plan-036 adds the user() relation so the Manager Till Tracking detail
 * page can render "{action} by {actor.name}" without re-querying per row.
 */
class AuditLog extends AuditLogBaseModel
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
