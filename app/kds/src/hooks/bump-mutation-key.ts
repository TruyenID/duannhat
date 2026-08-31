/**
 * Mutation key shared by every KDS bump mutation (single-item
 * mark-preparing/mark-ready/mark-served/revert AND bump-all). Components use
 * `useIsMutating({ mutationKey: BUMP_MUTATION_KEY })` to know when *any* bump
 * is in flight, so all bump controls can be disabled together — the
 * anti-misclick guard that a per-hook `isPending` cannot provide.
 */
export const BUMP_MUTATION_KEY = ["kds", "bump"] as const;
