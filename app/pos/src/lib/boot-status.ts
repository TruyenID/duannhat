/** Where a tablet lands at boot. */
export type BootStatus = "loading" | "ready" | "needs_setup";

/**
 * Decided by whether the tablet KNOWS a workstation — never by whether that
 * workstation answered.
 *
 * The health probe deliberately has no vote. Tablets on a charging dock wake
 * before the shop's mini-PC does, and the workstation restarts mid-shift for
 * assisted updates; letting one failed 4s probe demote a configured tablet to
 * `needs_setup` put staff back in the Connect wizard on every tablet, every
 * morning. The POS screen already owns that case — it renders an offline /
 * Retry / change-workstation state — while the setup wizard is for a tablet
 * with no workstation at all.
 *
 * It lives here rather than in the provider so it can be tested at all: the
 * provider imports `expo-secure-store`, which drags the whole Expo native
 * runtime into a plain unit test.
 */
export function bootStatusFor(storedUrl: string | null): BootStatus {
  return storedUrl ? "ready" : "needs_setup";
}
