"use client";

import { WifiOff, ServerCrash, Clock } from "lucide-react";
import { Button } from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

interface ErrorShellProps {
  error: Error;
  onRetry: () => void;
}

function classifyError(error: Error): "timeout" | "network" | "server" {
  // AbortError thrown by AbortSignal.timeout()
  if (error.name === "AbortError" || error.name === "TimeoutError") return "timeout";
  // TypeError: Failed to fetch — no network / connection refused
  if (error instanceof TypeError) return "network";
  // ApiError 5xx
  if (error instanceof ApiError && error.status >= 500) return "server";
  // ApiError 4xx that wasn't handled upstream (shouldn't happen, but safe fallback)
  return "server";
}

const CONFIG = {
  timeout: {
    icon: Clock,
    titleKey: "common.error.timeout.title",
    descKey: "common.error.timeout.description",
  },
  network: {
    icon: WifiOff,
    titleKey: "common.error.network.title",
    descKey: "common.error.network.description",
  },
  server: {
    icon: ServerCrash,
    titleKey: "common.error.server.title",
    descKey: "common.error.server.description",
  },
} as const;

export function ErrorShell({ error, onRetry }: ErrorShellProps) {
  const { t } = useTranslation();
  const kind = classifyError(error);
  const { icon: Icon, titleKey, descKey } = CONFIG[kind];

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-6 px-4 text-center">
      <div className="flex size-14 items-center justify-center rounded-2xl bg-muted">
        <Icon className="size-7 text-muted-foreground" />
      </div>
      <div className="flex flex-col gap-1.5">
        <p className="text-base font-medium text-foreground">{t(titleKey)}</p>
        <p className="max-w-xs text-sm text-muted-foreground">{t(descKey)}</p>
      </div>
      <Button variant="outline" size="sm" onClick={onRetry}>
        {t("common.retry")}
      </Button>
    </div>
  );
}
