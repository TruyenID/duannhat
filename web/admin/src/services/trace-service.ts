/**
 * Trace Service — pure TypeScript, no React dependency.
 *
 * Recursive forward + backward genealogy walk over MaterialLots.
 * Backed by /api/v1/hq/{brandSlug}/trace/lot/{lot} and
 *           /api/v1/hq/{brandSlug}/trace/customer-order/{order}.
 *
 * Both endpoints are rate-limited 30/min via throttle:30,1 — UI should
 * debounce the trace-tool input and avoid auto-firing on each keystroke.
 */

import { apiFetch } from "@/lib/api";

import type { MaterialLotSource, MaterialLotStatus } from "./material-lot-service";

export type TraceDirection = "forward" | "backward" | "both";

/**
 * One node in the recursive trace tree. Each lot node carries its parents +
 * children; sales / disposal terminal leaves use the alternate `leaf_type`
 * shape (`lot_id` is absent, `customer_order_id` may be set).
 */
export interface TraceLotNode {
  lot_id: string;
  lot_code: string;
  material: { id: string; sku: string | null };
  warehouse: { id: string; name: string };
  source: MaterialLotSource;
  status: MaterialLotStatus;
  qty_on_hand: number;
  unit: string | null;
  expiry_date: string | null;
  received_at: string;
  supplier_name: string | null;
  parents: TraceNode[];
  children: TraceNode[];
  edge?: TraceEdge;
}

export interface TraceLeafNode {
  leaf_type: "customer_order" | "disposal" | "manual_adjustment";
  customer_order_id: string | null;
  edge: TraceEdge;
}

export interface TraceTruncatedNode {
  truncated: true;
  remaining: true;
}

export interface TraceCycleNode {
  lot_id: string;
  cycle: true;
}

export type TraceNode = TraceLotNode | TraceLeafNode | TraceTruncatedNode | TraceCycleNode;

export interface TraceEdge {
  qty_consumed: number;
  unit: string | null;
  consumed_at: string;
  source_event_type: string;
  source_event_id: string | null;
}

export interface TraceCustomerOrderResponse {
  customer_order_id: string;
  direct_lots: TraceLotNode[];
  truncated: boolean;
}

// =========================================================================
//  Service
// =========================================================================

export const traceService = {
  lot: (
    brandSlug: string,
    lotId: string,
    options: { direction?: TraceDirection; maxDepth?: number } = {}
  ) => {
    const params = new URLSearchParams();
    if (options.direction) params.set("direction", options.direction);
    if (options.maxDepth !== undefined) params.set("max_depth", String(options.maxDepth));

    const qs = params.toString();
    const suffix = qs.length > 0 ? `?${qs}` : "";

    return apiFetch<{ data: TraceLotNode }>(`/api/v1/hq/${brandSlug}/trace/lot/${lotId}${suffix}`);
  },

  customerOrder: (
    brandSlug: string,
    customerOrderId: string,
    options: { maxDepth?: number } = {}
  ) => {
    const params = new URLSearchParams();
    if (options.maxDepth !== undefined) params.set("max_depth", String(options.maxDepth));

    const qs = params.toString();
    const suffix = qs.length > 0 ? `?${qs}` : "";

    return apiFetch<{ data: TraceCustomerOrderResponse }>(
      `/api/v1/hq/${brandSlug}/trace/customer-order/${customerOrderId}${suffix}`
    );
  },
};

// =========================================================================
//  Type guards (the trace tree is a discriminated union)
// =========================================================================

export function isLotNode(node: TraceNode): node is TraceLotNode {
  return "lot_code" in node && !("cycle" in node);
}

export function isLeafNode(node: TraceNode): node is TraceLeafNode {
  return "leaf_type" in node;
}

export function isTruncated(node: TraceNode): node is TraceTruncatedNode {
  return "truncated" in node && node.truncated === true;
}

export function isCycle(node: TraceNode): node is TraceCycleNode {
  return "cycle" in node && node.cycle === true;
}
