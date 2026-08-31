/**
 * Soft auth failure banner — Cloud/WS could not verify the device token
 * (503 "auth verification unavailable") but the stored token may still be
 * valid. Offer an explicit re-pair path so the cashier is never stuck.
 */
import { KeyRoundIcon } from "lucide-react";
import { Button } from "@godxjp/ui";
import { useAuth } from "@/providers/use-auth";
import { useTranslation } from "@/providers/app-provider";

export function AuthRecoveryBanner() {
  const { t } = useTranslation();
  const { authRecoveryReason, dismissAuthRecovery, logout } = useAuth();

  if (!authRecoveryReason) return null;

  return (
    <div
      role="alert"
      aria-live="assertive"
      data-testid="auth-recovery-banner"
      // Stacked ABOVE the offline banner, not on top of it. Both fire together
      // when the workstation goes unreachable, and they used to share identical
      // coordinates — so the z-50 auth banner hid the z-40 "you are offline"
      // line, the one message that explains why nothing is saving.
      className="fixed bottom-[max(11rem,calc(env(safe-area-inset-bottom)+6.5rem))] left-3 right-3 z-50 flex items-start gap-3 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-3 text-destructive shadow-lg sm:right-auto sm:max-w-md lg:bottom-[max(7.5rem,calc(env(safe-area-inset-bottom)+6.5rem))]"
    >
      <KeyRoundIcon className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 flex-1 space-y-2 text-xs leading-snug">
        <p className="text-sm font-semibold">{t("auth.recovery.title")}</p>
        <p className="opacity-90">{t("auth.recovery.desc")}</p>
        <p className="break-words font-mono text-[11px] opacity-80">
          {authRecoveryReason}
        </p>
        {/* The primary action must NOT be destructive. This banner fires on
            503 "auth verification unavailable" — the workstation could not
            REACH Cloud to check the token, so the token is probably fine and
            the fault usually clears itself (or degrades onto the stale-cache
            path). Logging out here relays pairing to the same unreachable
            Cloud, fails again, and leaves a till that needs a new 6-digit code
            from HQ before it can sell — a worse outage than the one the
            cashier was told about. Re-pairing stays reachable, as the opt-in. */}
        <div className="flex flex-wrap gap-2 pt-0.5">
          <Button size="sm" onClick={dismissAuthRecovery}>
            {t("auth.recovery.keep_working")}
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => {
              dismissAuthRecovery();
              logout();
            }}
          >
            {t("auth.recovery.repair")}
          </Button>
        </div>
      </div>
    </div>
  );
}
