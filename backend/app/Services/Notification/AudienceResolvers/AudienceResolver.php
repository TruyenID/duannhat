<?php

namespace App\Services\Notification\AudienceResolvers;

use App\Contracts\Notifiable;
use App\Models\Brand;
use Illuminate\Support\Collection;

/**
 * Sub-resolver contract for a single audience rule `type`.
 *
 * Each implementation corresponds 1:1 to a `type` value inside the `rule`
 * JSON persisted on `notification_audiences.rule` — `role`, `user`, `shop`,
 * `brand`, `device`. The `AudienceResolverService` orchestrates all
 * registered resolvers; adding a new audience rule type means adding a
 * resolver class here and registering it in `AudienceResolverService`.
 *
 * Each entry in the returned Collection is a row of shape:
 *   [
 *     'notifiable' => Model (User|Device) implementing Notifiable,
 *     'key'        => "{morphClass}:{primaryKey}" — used for dedup by
 *                     the orchestrator,
 *     'trace'      => human-readable origin string, stored on
 *                     notification_recipients.resolved_via (e.g.
 *                     "role:warehouse_manager:{warehouse_id}",
 *                     "user:direct").
 *   ]
 */
interface AudienceResolver
{
    /**
     * @param  array<string, mixed>  $rule  One sub-rule from the rule JSON
     * @return Collection<int, array{notifiable: Notifiable, key: string, trace: string}>
     */
    public function resolve(array $rule, Brand $brand): Collection;

    /**
     * The `type` value this resolver consumes. Must match the string stored
     * in sub-rule `type` field.
     */
    public function type(): string;
}
