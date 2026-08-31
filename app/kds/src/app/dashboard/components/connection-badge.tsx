import { useEffect, useState } from "react";
import { useTranslation } from "@/i18n";
import {
  resolveBaseUrl,
  isUsingWorkstation,
  unreachableTimeRemaining,
} from "@/services/base-url-resolver";
import { useRealtime } from "@/providers/use-realtime";
import { cn } from "@/lib/utils";

export function ConnectionBadge() {
  const { t } = useTranslation();
  const { isConnected } = useRealtime();
  // Poll every 3s for LAN↔cloud mode text + backoff countdown.
  const [, force] = useState(0);
  useEffect(() => {
    const interval = setInterval(() => force((n) => n + 1), 3_000);
    return () => clearInterval(interval);
  }, []);

  const using = isUsingWorkstation();
  const { url } = resolveBaseUrl();
  const host = (() => {
    try {
      return new URL(url).host;
    } catch {
      return url;
    }
  })();
  const backoffMs = unreachableTimeRemaining();
  const backoffSecs = Math.ceil(backoffMs / 1000);

  return (
    <span
      data-testid="connection-badge"
      data-mode={using ? "workstation" : "cloud"}
      data-ws={isConnected ? "live" : "polling"}
      className={cn(
        "inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-sm font-medium",
        using
          ? "bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200"
          : "bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200"
      )}
    >
      <span
        className={cn(
          "inline-block w-1.5 h-1.5 rounded-full",
          isConnected ? "bg-green-500" : "bg-amber-400",
        )}
      />
      {using
        ? t("connection.lan", { url: host })
        : backoffMs > 0
        ? `${t("connection.cloud")} · ${backoffSecs}s`
        : t("connection.cloud")}
      {!isConnected && (
        <span className="opacity-60 text-xs">({t("connection.polling")})</span>
      )}
    </span>
  );
}
