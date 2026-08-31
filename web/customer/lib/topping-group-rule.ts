/**
 * #1429 — what a topping group's selection rule actually SAYS to a customer.
 *
 * The product modal used to print one cropped badge built straight from
 * `min_select` ("Chọn 1"), which left three things unsaid:
 *
 *   - whether the group was required at all (the only tell was the badge
 *     turning red, and it only turns red AFTER a failed "add to cart");
 *   - the upper bound — a `min=1, max=3` group also read "Chọn 1", so nobody
 *     discovered they could take three;
 *   - that an optional group was optional. `min=0` with no meaningful cap
 *     rendered NO badge whatsoever, so the group just sat there mute.
 *
 * This module answers all of it in one place, as data, so the modal only has to
 * translate and lay out — and so the mapping can be tested without a DOM.
 */

/** The shape this needs off `ToppingGroup`; kept structural so tests stay light. */
export interface ToppingGroupRuleInput {
  min_select: number;
  max_select: number | null;
  max_qty_per_item?: number;
  items: readonly unknown[];
}

/** Which sentence describes the group, and the numbers that fill it in. */
export type ToppingGroupRuleKind =
  /** No minimum, no meaningful cap — take as many as you like. */
  | "any"
  /** No minimum, real cap. `max` set. */
  | "upTo"
  /** Must take exactly this many. `count` set. */
  | "exact"
  /** Must take between min and max. Both set. */
  | "range"
  /** Must take at least min, no meaningful cap. `min` set. */
  | "atLeast";

export interface ToppingGroupRule {
  /** Does the customer have to pick something before the item can be added? */
  required: boolean;
  kind: ToppingGroupRuleKind;
  /** Set for `exact`. */
  count?: number;
  /** Set for `range` and `atLeast`. */
  min?: number;
  /** Set for `range` and `upTo`. */
  max?: number;
  /**
   * Per-item stacking cap, only when it actually stacks (> 1). Two portions of
   * the same topping is a different offer from two different toppings, and the
   * old badge never mentioned it at all.
   */
  perItem?: number;
}

/**
 * A cap that cannot bite is not a cap. `max_select = null` is explicitly
 * unlimited, and a max at or above the number of items in the group means
 * taking everything still never hits it — saying "up to 6" over a list of six
 * reads like a restriction the customer then wastes attention on. The old badge
 * already hid that case; this keeps the behaviour and gives it a name.
 */
function effectiveMax(group: ToppingGroupRuleInput): number | null {
  const { max_select: max } = group;
  if (max === null || max === undefined) return null;
  if (max >= group.items.length) return null;
  return max;
}

export function describeToppingGroupRule(group: ToppingGroupRuleInput): ToppingGroupRule {
  // Guard the inputs rather than trusting the feed: a negative or fractional
  // min would otherwise render "Chọn -1 món" straight at the customer.
  const min = Math.max(0, Math.floor(group.min_select ?? 0));
  const max = effectiveMax(group);
  const perItemRaw = Math.floor(group.max_qty_per_item ?? 1);
  const perItem = perItemRaw > 1 ? perItemRaw : undefined;

  const required = min > 0;

  if (!required) {
    return max === null
      ? { required, kind: "any", perItem }
      : { required, kind: "upTo", max, perItem };
  }

  if (max === null) {
    return { required, kind: "atLeast", min, perItem };
  }

  // A max BELOW the min is a contradiction in the catalogue, not something the
  // customer can act on. Read it as "exactly max" — the reachable half of the
  // rule — instead of printing an impossible range like "Chọn 3–1 món".
  if (max <= min) {
    return { required, kind: "exact", count: max, perItem };
  }

  return { required, kind: "range", min, max, perItem };
}

/**
 * How many picks still stand between the customer and a satisfied group.
 * Zero when the group is already fine (including every optional group).
 */
export function toppingGroupShortfall(
  group: ToppingGroupRuleInput,
  selectedCount: number,
): number {
  const min = Math.max(0, Math.floor(group.min_select ?? 0));
  return Math.max(0, min - Math.max(0, selectedCount));
}
