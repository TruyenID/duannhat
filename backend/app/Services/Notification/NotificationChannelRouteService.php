<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Brand;
use App\Models\NotificationChannelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-organisation channel routing overrides (extracted #1666).
 *
 * The batch and its transaction used to sit in
 * `NotificationChannelRouteAdminController::upsert`. The transaction is the
 * reason this is a service and not a loop at the edge: routing is submitted as
 * a SET by the admin screen, and half a set applied is a routing table nobody
 * asked for — some types on the new channels, some on the old, with no error
 * anyone can act on.
 */
final class NotificationChannelRouteService
{
    /**
     * Apply a full set of routes for one organisation, replacing any existing
     * row per `type`.
     *
     * `brand_id` is pinned to the route's brand and NEVER read from an input
     * row (#171 — cross-brand metadata pollution guard). That is why this takes
     * `Brand` rather than trusting the payload.
     *
     * @param  array<int, array{type: string, channels: mixed, priority_overrides?: mixed}>  $routes
     * @return Collection<int, NotificationChannelRoute>
     */
    public function upsert(string $organizationId, Brand $brand, array $routes): Collection
    {
        return DB::transaction(function () use ($organizationId, $brand, $routes): Collection {
            $out = collect();

            foreach ($routes as $row) {
                $attrs = [
                    'organization_id' => $organizationId,
                    'brand_id' => $brand->id,
                    'type' => $row['type'],
                    'channels' => $row['channels'],
                    'priority_overrides' => $row['priority_overrides'] ?? null,
                ];

                $existing = NotificationChannelRoute::query()
                    ->where('organization_id', $organizationId)
                    ->where('type', $row['type'])
                    ->first();

                if ($existing === null) {
                    $out->push(NotificationChannelRoute::query()->create($attrs));

                    continue;
                }

                $existing->fill($attrs)->save();
                $out->push($existing->fresh());
            }

            return $out;
        });
    }
}
