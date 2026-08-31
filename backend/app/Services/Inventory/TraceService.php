<?php

namespace App\Services\Inventory;

/**
 * TraceService
 *
 * Recursive forward + backward walk over `genealogy_links`. Bounded by
 * `max_depth` to keep response sizes sane on hot supplier lots that may feed
 * 200+ batches and 5,000+ orders. Each node paginates its children at
 * `GenealogyWalker::MAX_CHILDREN_PER_NODE` and emits a `truncated` flag when
 * more remain.
 *
 * plan-040 TG.1: the traversal itself now lives in the shared
 * {@see GenealogyWalker} (DAG-safe per-path visited, reversal/manual_adjustment
 * filtering, org/brand scope) so Trace, Recall and RecallDrill share one
 * implementation. This service is the trace-specific orchestrator façade.
 *
 * Two entry points:
 *   - traceLot($lotId, $direction, $maxDepth)  — pivot on a MaterialLot
 *   - traceCustomerOrder($orderId, $maxDepth)  — pivot on a sales-edge order
 */
class TraceService
{
    public const MAX_CHILDREN_PER_NODE = GenealogyWalker::MAX_CHILDREN_PER_NODE;

    public const DEFAULT_MAX_DEPTH = 10;

    public const ABSOLUTE_MAX_DEPTH = 25;

    public function __construct(
        private readonly GenealogyWalker $walker,
    ) {}

    /**
     * Trace a lot forward (children — what was produced from this lot or
     * which orders consumed it), backward (parents — what fed into this lot),
     * or both.
     *
     * @param  'forward'|'backward'|'both'  $direction
     * @return array<string, mixed>
     */
    public function traceLot(string $lotId, string $direction = 'both', int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        return $this->walker->traceLot($lotId, $direction, $maxDepth);
    }

    /**
     * Trace a customer order back to every supplier lot that fed into it.
     *
     * @return array<string, mixed>
     */
    public function traceCustomerOrder(string $customerOrderId, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        return $this->walker->traceCustomerOrder($customerOrderId, $maxDepth);
    }
}
