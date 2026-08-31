import { Alert } from "@godxjp/ui/admin";
import { Button } from "@godxjp/ui/general";
import { useEffect, useState } from "react";
import { useNavigate } from "react-router";
import { AlertTriangle, Clock } from "lucide-react";
import { getSyncInfo } from "../../lib/api";
import { useTranslation } from "../../providers/app-provider";

/**
 * plan-042 — global banner surfacing stuck sync operations. Polls GET /api/sync
 * every 5s. Red when there are dead-lettered rows (with a stronger sub-line for
 * payment orphans), neutral when the push loop is merely rate-limited. Hidden
 * when everything is healthy. Best-effort: a failed read never blocks the app.
 */
export function SyncFailureBanner() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [deadCount, setDeadCount] = useState(0);
  const [payCount, setPayCount] = useState(0);
  const [throttled, setThrottled] = useState(false);

  useEffect(() => {
    let active = true;
    const load = async () => {
      try {
        const si = await getSyncInfo();
        if (!active) return;
        setDeadCount(si.dead_letter_count ?? 0);
        setPayCount(si.payment_orphan_count ?? 0);
        setThrottled(Boolean(si.throttled));
      } catch {
        // best-effort — don't block the app on a transient local read
      }
    };
    load();
    const timer = setInterval(load, 5000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, []);

  if (deadCount === 0 && !throttled) return null;

  // Rate-limited only (no dead-letters): neutral "paused" state.
  if (deadCount === 0 && throttled) {
    return (
      <div className="px-4 pt-3">
        <Alert color="warning" className="flex items-center gap-2">
          <Clock size={16} className="shrink-0" />
          <span className="text-sm">{t("sync_recovery.throttled_banner")}</span>
        </Alert>
      </div>
    );
  }

  return (
    <div className="px-4 pt-3">
      <Alert color="destructive" className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-2">
          <AlertTriangle size={16} className="shrink-0" />
          <span className="text-sm">
            {t("sync_recovery.banner", { count: deadCount })}
            {payCount > 0 && ` · ${t("sync_recovery.banner_payments", { count: payCount })}`}
          </span>
        </div>
        <Button size="sm" color="destructive" onClick={() => navigate("/sync-recovery")}>
          {t("sync_recovery.view")}
        </Button>
      </Alert>
    </div>
  );
}
