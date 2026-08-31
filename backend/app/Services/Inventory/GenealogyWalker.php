<?php

namespace App\Services\Inventory;

use App\Models\GenealogyLink;
use App\Models\MaterialLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * GenealogyWalker — plan-040 TG.1 (Decision 2).
 *
 * The ONE shared traversal over `genealogy_links`. TraceService, RecallService
 * and RecallDrillService all delegate here so the reversal/manual_adjustment
 * filter, the DAG-safe visited handling, and the org/brand scope live in a
 * single implementation (the audit's root cause #2 was three divergent copies
 * drifting apart — C7 was a Trace patch that never reached Recall).
 *
 * Traversal invariants:
 *   - plan-040 C7: `reversal` + `manual_adjustment` edges are excluded from
 *     every walk — they document cancelled / corrective flows and must not
 *     surface in trace trees or recall blast radius.
 *   - plan-040 NEW-CON-1: visited is tracked PER PATH (passed by value, not by
 *     reference), so a diamond (G→A→L and G→B→L) shows both branches instead of
 *     being collapsed as a false `cycle`. A genuine cycle (a lot reachable on
 *     its own ancestor path) is still cut. Subtrees are memoised per
 *     direction+lot+depth to keep diamond-heavy graphs bounded.
 *   - plan-040 NEW-CON-4: the ORGANIZATION scope is always on — a walk never
 *     traverses into another org's lot (the real tenant boundary). The BRAND
 *     scope is OPT-IN per caller (`$withBrandScope`): Trace keeps it on (a
 *     read/UI tenant-isolation tool, NEW-CON-4), while Recall/RecallDrill walk
 *     ORG-ONLY (brand=null) — plan-040 W5: a food-safety recall must follow the
 *     material across brands within the same org (e.g. central-kitchen brand →
 *     retail brand transfer); brand-scoping the walk would under-report the
 *     blast radius. The INITIATOR authz (which brand may recall a given root
 *     lot) is enforced separately in RecallService, not by pruning the walk.
 */
class GenealogyWalker
{
    public const MAX_CHILDREN_PER_NODE = 100;

    /**
     * Edge event types netted out of every walk (cancelled / corrective flows).
     *
     * @var array<int, string>
     */
    private const EXCLUDED_EVENT_TYPES = ['reversal', 'manual_adjustment'];

    // =========================================================================
    //  Trace tree (TraceService)
    // =========================================================================

    /**
     * Build the recursive trace node for a lot: parents (backward — what fed
     * into it) and/or children (forward — what it produced / which orders
     * consumed it).
     *
     * @param  'forward'|'backward'|'both'  $direction
     * @return array<string, mixed>
     */
    public function traceLot(string $lotId, string $direction, int $maxDepth): array
    {
        $lot = MaterialLot::with(['material:id,sku', 'warehouse:id,name'])->findOrFail($lotId);
        $scope = $this->scopeForLot($lot);

        $node = $this->buildLotNode($lot);

        // plan-040 NEW-CON-2: parent and child walks each get their OWN visited
        // seed + memo. Sharing one array across directions truncated the second
        // walk whenever a lot appeared in both ancestry and descendancy.
        if (in_array($direction, ['backward', 'both'], true)) {
            $memo = [];
            $node['parents'] = $this->walkParents($lotId, $maxDepth, [$lotId => true], $scope, $memo);
        }
        if (in_array($direction, ['forward', 'both'], true)) {
            $memo = [];
            $node['children'] = $this->walkChildren($lotId, $maxDepth, [$lotId => true], $scope, $memo);
        }

        return $node;
    }

    /**
     * Trace a customer order back to every supplier lot that fed into it.
     *
     * @return array<string, mixed>
     */
    public function traceCustomerOrder(string $customerOrderId, int $maxDepth): array
    {
        $salesEdges = GenealogyLink::query()
            ->where('customer_order_id', $customerOrderId)
            ->where('source_event_type', 'customer_order')
            ->limit(self::MAX_CHILDREN_PER_NODE + 1)
            ->get(['id', 'parent_lot_id', 'qty_consumed', 'unit', 'consumed_at', 'source_event_id']);

        $truncated = $salesEdges->count() > self::MAX_CHILDREN_PER_NODE;
        $salesEdges = $salesEdges->take(self::MAX_CHILDREN_PER_NODE);

        $directLots = $salesEdges->map(function (GenealogyLink $edge) use ($maxDepth) {
            $lot = MaterialLot::with(['material:id,sku', 'warehouse:id,name'])->find($edge->parent_lot_id);
            if (! $lot) {
                return null;
            }

            $scope = $this->scopeForLot($lot);
            $memo = [];
            $node = $this->buildLotNode($lot);
            $node['edge'] = [
                'qty_consumed' => (float) $edge->qty_consumed,
                'unit' => $edge->unit,
                'consumed_at' => $edge->consumed_at,
            ];
            $node['parents'] = $this->walkParents((string) $lot->id, $maxDepth - 1, [(string) $lot->id => true], $scope, $memo);

            return $node;
        })->filter()->values()->all();

        return [
            'customer_order_id' => $customerOrderId,
            'direct_lots' => $directLots,
            'truncated' => $truncated,
        ];
    }

    // =========================================================================
    //  Flat blast radius (RecallService / RecallDrillService)
    // =========================================================================

    /**
     * Forward BFS from $rootLotId — every descendant lot reachable through
     * production edges (reversal/manual_adjustment excluded). Always org-scoped;
     * brand-scoping is OPT-IN. The root itself is NOT included (callers prepend
     * it).
     *
     * plan-040 W5: Recall/RecallDrill leave $withBrandScope=false so a recall
     * blast radius follows the material across brands within the org (a cross-
     * brand same-org descendant is NOT pruned). Brand-scoping here would silently
     * cut downstream lots/orders from a food-safety recall.
     *
     * @return array<int, string>
     */
    public function descendantLotIds(string $rootLotId, bool $withBrandScope = false): array
    {
        $root = MaterialLot::find($rootLotId, ['id', 'organization_id', 'brand_id']);
        if (! $root) {
            return [];
        }
        $scope = $this->scopeForLot($root, $withBrandScope);

        $visited = [];
        $queue = [$rootLotId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            foreach ($this->childLotIds((string) $current, $scope) as $childId) {
                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        unset($visited[$rootLotId]);

        return array_keys($visited);
    }

    /**
     * Customer orders consumed from any of $lotIds — filtered to
     * `source_event_type='customer_order'` (plan-040 NEW-CON-3, so the drill
     * and the recall preview compute over the SAME order set).
     *
     * @param  array<int, string>  $lotIds
     * @return array<int, string>
     */
    public function affectedOrderIds(array $lotIds): array
    {
        if (empty($lotIds)) {
            return [];
        }

        return GenealogyLink::query()
            ->whereIn('parent_lot_id', $lotIds)
            ->where('source_event_type', 'customer_order')
            ->whereNotNull('customer_order_id')
            ->distinct()
            ->pluck('customer_order_id')
            ->all();
    }

    // =========================================================================
    //  Recursive walk
    // =========================================================================

    /**
     * @param  array<string, true>  $visited  the current path (by value — copied per branch)
     * @param  array{organization_id: mixed, brand_id: mixed}  $scope
     * @param  array<string, array<int, array<string, mixed>>>  $memo
     * @return array<int, array<string, mixed>>
     */
    private function walkParents(string $lotId, int $depth, array $visited, array $scope, array &$memo): array
    {
        if ($depth <= 0) {
            return [];
        }

        // plan-040 info-8: the subtree memo keys on direction+lot+depth (NOT the
        // per-path $visited). This is safe ONLY under the DAG assumption — a true
        // genealogy graph has no cycle, so the subtree reachable from
        // (lot, depth) is path-independent. A genuine cycle is still cut by the
        // per-path $visited check before recursion; the memo would only return a
        // stale subtree if a cycle AND a diamond collided on the same
        // (lot, depth), which a real acyclic genealogy cannot produce.
        $memoKey = "backward:{$lotId}:{$depth}";
        if (array_key_exists($memoKey, $memo)) {
            return $memo[$memoKey];
        }

        $edges = $this->scopedEdges('child_lot_id', $lotId, 'parent_lot_id', $scope)
            ->get(['id', 'parent_lot_id', 'qty_consumed', 'unit', 'consumed_at', 'source_event_type', 'source_event_id']);

        $truncated = $edges->count() > self::MAX_CHILDREN_PER_NODE;
        $edges = $edges->take(self::MAX_CHILDREN_PER_NODE);

        $nodes = $edges->map(function (GenealogyLink $edge) use ($depth, $visited, $scope, &$memo) {
            $parentId = (string) $edge->parent_lot_id;
            if (isset($visited[$parentId])) {
                return ['lot_id' => $edge->parent_lot_id, 'cycle' => true];
            }

            $lot = MaterialLot::with(['material:id,sku', 'warehouse:id,name'])->find($parentId);
            if (! $lot) {
                return null;
            }

            $node = $this->buildLotNode($lot);
            $node['edge'] = $this->serializeEdge($edge);
            $node['parents'] = $this->walkParents($parentId, $depth - 1, $visited + [$parentId => true], $scope, $memo);

            return $node;
        })->filter()->values()->all();

        if ($truncated) {
            $nodes[] = ['truncated' => true, 'remaining' => true];
        }

        $memo[$memoKey] = $nodes;

        return $nodes;
    }

    /**
     * @param  array<string, true>  $visited
     * @param  array{organization_id: mixed, brand_id: mixed}  $scope
     * @param  array<string, array<int, array<string, mixed>>>  $memo
     * @return array<int, array<string, mixed>>
     */
    private function walkChildren(string $lotId, int $depth, array $visited, array $scope, array &$memo): array
    {
        if ($depth <= 0) {
            return [];
        }

        $memoKey = "forward:{$lotId}:{$depth}";
        if (array_key_exists($memoKey, $memo)) {
            return $memo[$memoKey];
        }

        $edges = $this->scopedEdges('parent_lot_id', $lotId, 'child_lot_id', $scope)
            ->get(['id', 'child_lot_id', 'customer_order_id', 'qty_consumed', 'unit', 'consumed_at', 'source_event_type', 'source_event_id']);

        $truncated = $edges->count() > self::MAX_CHILDREN_PER_NODE;
        $edges = $edges->take(self::MAX_CHILDREN_PER_NODE);

        $nodes = $edges->map(function (GenealogyLink $edge) use ($depth, $visited, $scope, &$memo) {
            // Sales / disposal edge — terminal leaf, no recursion.
            if ($edge->child_lot_id === null) {
                return [
                    'leaf_type' => $edge->source_event_type,
                    'customer_order_id' => $edge->customer_order_id,
                    'edge' => $this->serializeEdge($edge),
                ];
            }

            $childId = (string) $edge->child_lot_id;
            if (isset($visited[$childId])) {
                return ['lot_id' => $edge->child_lot_id, 'cycle' => true];
            }

            $lot = MaterialLot::with(['material:id,sku', 'warehouse:id,name'])->find($childId);
            if (! $lot) {
                return null;
            }

            $node = $this->buildLotNode($lot);
            $node['edge'] = $this->serializeEdge($edge);
            $node['children'] = $this->walkChildren($childId, $depth - 1, $visited + [$childId => true], $scope, $memo);

            return $node;
        })->filter()->values()->all();

        if ($truncated) {
            $nodes[] = ['truncated' => true, 'remaining' => true];
        }

        $memo[$memoKey] = $nodes;

        return $nodes;
    }

    // =========================================================================
    //  Query helpers
    // =========================================================================

    /**
     * Flat list of in-scope child lot ids consumed-into from $parentLotId
     * (reversal/manual_adjustment excluded, brand-scoped).
     *
     * @param  array{organization_id: mixed, brand_id: mixed}  $scope
     * @return array<int, string>
     */
    private function childLotIds(string $parentLotId, array $scope): array
    {
        return $this->scopedEdges('parent_lot_id', $parentLotId, 'child_lot_id', $scope)
            ->whereNotNull('child_lot_id')
            ->pluck('child_lot_id')
            ->all();
    }

    /**
     * Base edge query: pivot on $pivotColumn = $pivotValue, exclude
     * cancelled/corrective event types, and constrain the lot referenced by
     * $scopeColumn to the supplied org scope (always on) plus the brand scope
     * when present (NEW-CON-4 / plan-040 W5 — a null `brand_id` means org-only).
     * Bounded to MAX_CHILDREN_PER_NODE + 1 so callers can detect truncation.
     *
     * @param  array{organization_id: mixed, brand_id: mixed}  $scope
     */
    private function scopedEdges(string $pivotColumn, string $pivotValue, string $scopeColumn, array $scope): Builder
    {
        return GenealogyLink::query()
            ->where("genealogy_links.{$pivotColumn}", $pivotValue)
            ->whereNotIn('source_event_type', self::EXCLUDED_EVENT_TYPES)
            ->where(function ($q) use ($scopeColumn, $scope) {
                // Sales / disposal edges carry a null scoped lot — they terminate
                // at the (already in-scope) pivot, so keep them.
                $q->whereNull("genealogy_links.{$scopeColumn}")
                    ->orWhereExists(function ($sub) use ($scopeColumn, $scope) {
                        $sub->select(DB::raw(1))
                            ->from('material_lots')
                            ->whereColumn('material_lots.id', "genealogy_links.{$scopeColumn}")
                            ->where('material_lots.organization_id', $scope['organization_id']);

                        // plan-040 W5: brand is an OPT-IN narrowing. Recall walks
                        // pass brand_id=null so the traversal follows the material
                        // across brands within the org.
                        if ($scope['brand_id'] !== null) {
                            $sub->where('material_lots.brand_id', $scope['brand_id']);
                        }
                    });
            })
            ->limit(self::MAX_CHILDREN_PER_NODE + 1);
    }

    /**
     * @return array{organization_id: mixed, brand_id: mixed}
     */
    private function scopeForLot(MaterialLot $lot, bool $withBrandScope = true): array
    {
        return [
            'organization_id' => $lot->organization_id,
            'brand_id' => $withBrandScope ? $lot->brand_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLotNode(MaterialLot $lot): array
    {
        return [
            'lot_id' => $lot->id,
            'lot_code' => $lot->lot_code,
            'material' => [
                'id' => $lot->material_id,
                'sku' => $lot->material?->sku,
            ],
            'warehouse' => [
                'id' => $lot->warehouse_id,
                'name' => $lot->warehouse?->name,
            ],
            'source' => $lot->source instanceof \BackedEnum ? $lot->source->value : $lot->source,
            'status' => $lot->status instanceof \BackedEnum ? $lot->status->value : $lot->status,
            'qty_on_hand' => (float) $lot->qty_on_hand,
            'unit' => $lot->unit,
            'expiry_date' => $lot->expiry_date,
            'received_at' => $lot->received_at,
            'supplier_name' => $lot->supplier_name,
            'parents' => [],
            'children' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEdge(GenealogyLink $edge): array
    {
        return [
            'qty_consumed' => (float) $edge->qty_consumed,
            'unit' => $edge->unit,
            'consumed_at' => $edge->consumed_at,
            'source_event_type' => $edge->source_event_type,
            'source_event_id' => $edge->source_event_id,
        ];
    }
}
