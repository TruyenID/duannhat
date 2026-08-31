/**
 * Gap-claim state shared between the shift-open page and its GapReconcilePanel.
 *
 * plan-044 R2. The panel owns the selection UI; the open page lifts this state so it
 * can gate the open submit on the held-separately acknowledgement and fold the claimed
 * ids into the OpenShiftPayload. Kept in its own module so the panel file only exports
 * a component (Vite fast-refresh requirement).
 */

export interface GapClaimState {
  /** Payment ids the cashier confirmed belong to this shift. */
  claimedIds: string[];
  /** True when at least one selected payment is cash → the ack is required. */
  cashSelected: boolean;
  /** The "held-separately" acknowledgement value. */
  ack: boolean;
}

export const EMPTY_GAP_CLAIM: GapClaimState = {
  claimedIds: [],
  cashSelected: false,
  ack: false,
};
