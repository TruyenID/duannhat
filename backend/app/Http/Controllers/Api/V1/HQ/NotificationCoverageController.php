<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Plan-023 M8 T8.13 — read-only coverage catalogue endpoint.
 *
 * GET /api/v1/hq/{brandSlug}/notifications/coverage
 *
 * Returns:
 *   system_rules     — active rules (system + brand) with last-fired stats
 *   covered_models   — distinct trigger_model_type values across active rules
 *   uncovered_models — morph map keys minus excluded minus covered
 */
class NotificationCoverageController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Brand $brand): JsonResponse
    {
        Gate::authorize('viewNotificationCoverage', $brand);

        $excluded = (array) config('notifications.coverage.excluded_models', []);

        $systemRules = NotificationRule::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->get()
            ->map(fn (NotificationRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'trigger_event' => $rule->trigger_event,
                'trigger_model_type' => $rule->trigger_model_type,
                'is_active' => $rule->is_active,
                'fire_count' => $rule->fire_count,
                'last_fired_at' => $rule->last_fired_at?->toIso8601String(),
            ]);

        $morphMap = Relation::morphMap();
        $allAliases = array_keys($morphMap);
        $coveredAliases = $systemRules
            ->pluck('trigger_model_type')
            ->unique()
            ->filter()
            ->values();

        $uncoveredAliases = collect($allAliases)
            ->filter(fn ($alias) => ! in_array($alias, $excluded, true))
            ->filter(fn ($alias) => ! $coveredAliases->contains($alias))
            ->values();

        return response()->json([
            'system_rules' => $systemRules,
            'covered_models' => $coveredAliases,
            'uncovered_models' => $uncoveredAliases,
        ]);
    }
}
