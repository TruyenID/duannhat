import {
  CheckIcon,
  CloudIcon,
  RadioIcon,
  RotateCcwIcon,
  WifiOffIcon,
} from "lucide-react";
import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { formatTime } from "@/lib/format-date";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import { useWorkstation } from "@/providers/workstation-provider";
import type { ApiMode } from "@/services/workstation/base-url-resolver";
import {
  resetRequestStats,
  useRequestStats,
} from "@/services/workstation/request-stats";
import { WorkstationUrlEditor } from "./workstation-url-editor";

/**
 * Top-bar dropdown showing current LAN/Cloud routing state. Click opens a menu
 * with the API mode radio + Test Connection action — POS staff can flip modes
 * mid-shift if workstation reliability becomes an issue.
 */
export function ConnectionStatusBadge() {
  const { t, locale } = useTranslation();
  const {
    mode,
    setMode,
    status,
    workstationReachable,
    workstationUrl,
    cloudUrl,
    testConnection,
    lastCheckedAt,
  } = useWorkstation();
  const stats = useRequestStats();

  const presentation = badgePresentation(status);

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className={cn(
            "h-9 gap-1.5 rounded-md border px-2.5 text-xs font-medium",
            presentation.bg,
            presentation.border,
            presentation.fg,
          )}
          aria-label={t("connection.label")}
        >
          <presentation.Icon className="size-3.5" />
          <span className="hidden sm:inline">{t(presentation.labelKey)}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-[18rem]">
        <DropdownMenuLabel className="leading-tight">
          <div className="flex items-start justify-between gap-2">
            <div className="text-sm font-medium text-foreground">
              {t("connection.dropdown_title")}
            </div>
            {/* The mode picker below decides whether printing, the card
                terminal and the cash recycler exist at all. That is worth
                explaining where the choice is made. */}
            <HelpButton topic="connection" className="-mr-1 -mt-1 size-7" />
          </div>
          <div className="font-mono text-[10px] font-normal text-muted-foreground">
            {mode !== "cloud" && workstationReachable
              ? `LAN: ${workstationUrl}`
              : `Cloud: ${cloudUrl}`}
          </div>
          {lastCheckedAt && (
            <div className="mt-1 text-[10px] font-normal text-muted-foreground">
              {t("connection.last_check", {
                time: formatTime(lastCheckedAt, locale),
              })}
            </div>
          )}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />

        <WorkstationUrlEditor />

        <DropdownMenuSeparator />
        <DropdownMenuRadioGroup
          value={mode}
          onValueChange={(v) => setMode(v as ApiMode)}
        >
          <DropdownMenuRadioItem value="auto">
            {t("connection.mode.auto")}
          </DropdownMenuRadioItem>
          <DropdownMenuRadioItem value="workstation">
            {t("connection.mode.workstation")}
          </DropdownMenuRadioItem>
          <DropdownMenuRadioItem value="cloud">
            {t("connection.mode.cloud")}
          </DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />

        {/* Live counters — proof that LAN mode is doing what it claims. */}
        <div className="px-2 py-1.5">
          <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            {t("connection.stats.title")}
          </div>
          <div className="space-y-1">
            <StatLine
              icon={<RadioIcon className="size-3 text-emerald-600" />}
              label={t("connection.stats.lan")}
              count={stats.lan}
              failures={stats.lanFailures}
              lastAt={stats.lanLastAt}
              t={t}
              locale={locale}
            />
            <StatLine
              icon={<CloudIcon className="size-3 text-sky-600" />}
              label={t("connection.stats.cloud")}
              count={stats.cloud}
              failures={stats.cloudFailures}
              lastAt={stats.cloudLastAt}
              t={t}
              locale={locale}
            />
          </div>
        </div>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          onClick={(e) => {
            e.preventDefault();
            void testConnection();
          }}
        >
          <CheckIcon className="mr-2 size-4" />
          {t("connection.test")}
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={(e) => {
            e.preventDefault();
            resetRequestStats();
          }}
          disabled={stats.lan === 0 && stats.cloud === 0}
        >
          <RotateCcwIcon className="mr-2 size-4" />
          {t("connection.stats.reset")}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function StatLine({
  icon,
  label,
  count,
  failures,
  lastAt,
  t,
  locale,
}: {
  icon: React.ReactNode;
  label: string;
  count: number;
  failures: number;
  lastAt: number | null;
  t: (k: string, p?: Record<string, string>) => string;
  locale: string;
}) {
  return (
    <div className="flex items-center justify-between gap-2 text-[11px]">
      <span className="flex items-center gap-1.5 text-muted-foreground">
        {icon}
        {label}
      </span>
      <span className="font-mono tabular-nums text-foreground">
        {count.toLocaleString()}
        {failures > 0 && (
          <span className="ml-1 text-[10px] text-amber-700 dark:text-amber-400">
            {t("connection.stats.failure_suffix", { n: String(failures) })}
          </span>
        )}
        {lastAt && count > 0 && (
          <span className="ml-1.5 text-[10px] text-muted-foreground">
            {formatTime(new Date(lastAt), locale, { withSeconds: false })}
          </span>
        )}
      </span>
    </div>
  );
}

interface Presentation {
  Icon: typeof RadioIcon;
  labelKey: string;
  bg: string;
  border: string;
  fg: string;
}

function badgePresentation(status: string): Presentation {
  switch (status) {
    case "lan-active":
      return {
        Icon: RadioIcon,
        labelKey: "connection.badge.lan",
        bg: "bg-emerald-50",
        border: "border-emerald-200",
        fg: "text-emerald-700",
      };
    case "cloud-auto":
      return {
        Icon: CloudIcon,
        labelKey: "connection.badge.cloud_auto",
        bg: "bg-amber-50",
        border: "border-amber-200",
        fg: "text-amber-700",
      };
    case "cloud-manual":
      return {
        Icon: CloudIcon,
        labelKey: "connection.badge.cloud_manual",
        bg: "bg-sky-50",
        border: "border-sky-200",
        fg: "text-sky-700",
      };
    case "disconnected":
      return {
        Icon: WifiOffIcon,
        labelKey: "connection.badge.disconnected",
        bg: "bg-rose-50",
        border: "border-rose-200",
        fg: "text-rose-700",
      };
    case "checking":
    default:
      return {
        Icon: RadioIcon,
        labelKey: "connection.badge.checking",
        bg: "bg-muted",
        border: "border-border",
        fg: "text-muted-foreground",
      };
  }
}
