export type KioskEntryRoute = "/login" | "/settings" | "/advertise";
export type SettingsPasscodeGate = "recovery" | "setup" | "verify" | "open";

/**
 * A paired kiosk without a passcode must finish first-pair hardening before it
 * can enter the customer-facing idle screen.
 */
export function resolveKioskEntry(
  isAuthenticated: boolean,
  isPasscodeConfigured: boolean,
): KioskEntryRoute {
  if (!isAuthenticated) return "/login";
  return isPasscodeConfigured ? "/advertise" : "/settings";
}

/**
 * Before pairing, Settings remains available as a recovery surface for LAN
 * configuration, but passcode setup is unavailable. Pairing arms setup; an
 * existing passcode always requires verification.
 */
export function resolveSettingsPasscodeGate({
  isAuthenticated,
  isPasscodeConfigured,
  isUnlocked,
}: {
  isAuthenticated: boolean;
  isPasscodeConfigured: boolean;
  isUnlocked: boolean;
}): SettingsPasscodeGate {
  if (isUnlocked) return "open";
  if (isPasscodeConfigured) return "verify";
  return isAuthenticated ? "setup" : "recovery";
}
