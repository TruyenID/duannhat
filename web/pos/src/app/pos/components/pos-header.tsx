import { useEffect, useState } from "react";
import { useLocation, useNavigate, useParams } from "react-router-dom";
import {
  BanIcon,
  BanknoteIcon,
  BarChart3Icon,
  ChevronRightIcon,
  ClockIcon,
  GlobeIcon,
  HistoryIcon,
  LockIcon,
  LogOutIcon,
  Settings2Icon,
  StoreIcon,
  UserCircleIcon,
  UtensilsCrossedIcon,
  WalletIcon,
} from "lucide-react";
import { CashEventDialog } from "@/app/shift/cash-event-dialog";
import { AbandonShiftDialog } from "@/app/shift/abandon-shift-dialog";
import { HelpButton } from "@/help/help-button";
import { helpTopicForPath } from "@/help/route-topic";
import type { HelpTopicId } from "@/help/types";
import { useTillCurrent } from "@/hooks/api/use-till";
import {
  Avatar,
  AvatarFallback,
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
import { formatDateWithWeekday, formatTime } from "@/lib/format-date";
import { useAuth } from "@/providers/use-auth";
import { useLocale } from "@/providers/app-provider";
import { ConnectionStatusBadge } from "./connection-status";
import type { LocaleCode } from "@/i18n";

export interface PosHeaderBreadcrumb {
  /** Parent crumb (e.g. "Quầy thu ngân"). Bilingual labels handled by caller. */
  parent: string;
  /** Current page (e.g. "Mở ca"). Rendered bold to indicate where the user is. */
  current: string;
}

export interface PosHeaderProps {
  shopName: string;
  className?: string;
  /**
   * Optional breadcrumb shown to the right of the shop name. Use on
   * dedicated sub-pages (shift open/close, reports, etc.) so the cashier
   * always knows where they are within pos-web.
   */
  breadcrumb?: PosHeaderBreadcrumb;
  /**
   * Opens "Tra cứu nợ". Header-mounted because "who owes us money" is a
   * SHOP-WIDE question: it used to be a button inside OrderCart, which does not
   * render without an order, so answering it began with creating an order for a
   * customer who might not even be the debtor.
   *
   * Optional so screens that cannot host the dialog (shift open/close, reports)
   * simply omit the button rather than showing one that does nothing.
   */
  onDebtLookup?: () => void;
  /**
   * Which guide the `?` opens. Omit and it is derived from the route — which is
   * what `src/app/pos/page.tsx` relies on, since that file sits exactly on its
   * line budget (`page-size-budget.arch.test.ts`) and cannot spare a prop.
   */
  helpTopic?: HelpTopicId;
}

export function PosHeader({
  shopName,
  className,
  breadcrumb,
  onDebtLookup,
  helpTopic,
}: PosHeaderProps) {
  const { device, logout } = useAuth();
  const { locale, setLocale, locales, t } = useLocale();
  const navigate = useNavigate();
  const location = useLocation();
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();

  // Don't offer "Kết ca" while the cashier is already on /shift/close —
  // the menu item would just navigate to the current page. Detect via
  // suffix because the resolved path has shop slug + locale prefix.
  const isOnCloseShift = location.pathname.endsWith("/shift/close");

  const [now, setNow] = useState(() => new Date());
  const [cashEventOpen, setCashEventOpen] = useState(false);
  const [abandonOpen, setAbandonOpen] = useState(false);
  const tillCurrent = useTillCurrent(shopSlug);
  const openSession = tillCurrent.data?.open_session ?? null;

  // 1s tick keeps the header clock matching the screenshot's HH:MM:SS chip.
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  const time = formatTime(now, locale);
  const timeShort = formatTime(now, locale, { withSeconds: false });
  const date = formatDateWithWeekday(now, locale);

  return (
    <header
      data-slot="pos-header"
      className={cn(
        "flex items-center justify-between gap-2 border-b bg-card px-3 py-2 sm:px-6 sm:py-3",
        className,
      )}
    >
      <div className="flex min-w-0 items-center gap-2">
        <h1 className="truncate text-sm font-semibold tracking-tight text-foreground sm:text-lg">
          {t("pos.header.title")}
        </h1>
        <span className="hidden min-w-0 items-center gap-1 truncate text-xs text-muted-foreground md:inline-flex">
          <StoreIcon className="size-3 shrink-0" />
          <span className="truncate">{shopName}</span>
        </span>
        {breadcrumb && (
          <span className="hidden min-w-0 items-center gap-1 truncate text-xs text-muted-foreground sm:inline-flex">
            <ChevronRightIcon className="size-3 shrink-0 text-muted-foreground/60" />
            <span className="truncate">{breadcrumb.parent}</span>
            <ChevronRightIcon className="size-3 shrink-0 text-muted-foreground/60" />
            <strong className="truncate font-semibold text-foreground">
              {breadcrumb.current}
            </strong>
          </span>
        )}
      </div>

      <div className="flex items-center gap-1.5 sm:gap-2">
        {/* Compact clock — sm+ shows full time+date, xs shows HH:MM only */}
        <div className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2 py-1 text-xs tabular-nums text-foreground shadow-sm sm:gap-2 sm:px-3 sm:py-1.5 sm:text-sm">
          <ClockIcon className="size-3.5 shrink-0 text-muted-foreground sm:size-4" />
          <span className="font-medium sm:hidden">{timeShort}</span>
          <span className="hidden font-medium sm:inline">{time}</span>
          <span className="hidden text-muted-foreground md:inline">{date}</span>
        </div>

        <ConnectionStatusBadge />

        {/* Sits before the per-screen actions so its position never moves: it is
            the one control an operator looks for when they are already lost. */}
        <HelpButton
          topic={helpTopic ?? helpTopicForPath(location.pathname)}
          className="size-9"
        />

        {onDebtLookup && (
          <Button
            variant="ghost"
            size="sm"
            className="size-9 px-0"
            onClick={onDebtLookup}
            aria-label={t("pos.debt.lookup")}
            title={t("pos.debt.lookup")}
          >
            <WalletIcon className="size-4" />
          </Button>
        )}

        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          onClick={() => navigate(`/shop/${shopSlug}/reports/history`)}
          aria-label={t("pos.order_history.title")}
          title={t("pos.order_history.title")}
        >
          <HistoryIcon className="size-4" />
        </Button>

        <Button
          variant="ghost"
          size="sm"
          className="size-9 px-0"
          onClick={() => navigate(`/shop/${shopSlug}/reports/revenue`)}
          aria-label={t("revenue.title")}
          title={t("revenue.title")}
        >
          <BarChart3Icon className="size-4" />
        </Button>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="sm"
              className="size-9 px-0"
              aria-label={t("pos.header.language_menu")}
            >
              <GlobeIcon className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuRadioGroup
              value={locale}
              onValueChange={(v) => setLocale(v as LocaleCode)}
            >
              {Object.entries(locales).map(([code, label]) => (
                <DropdownMenuRadioItem key={code} value={code}>
                  {label}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="sm"
              className="size-9 px-0"
              aria-label={t("pos.header.account_menu")}
            >
              <UserCircleIcon className="size-5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent
            align="end"
            sideOffset={8}
            className="w-[18rem] p-0"
          >
            {/* Identity header */}
            <DropdownMenuLabel className="border-b px-4 py-3">
              <div className="flex items-center gap-3">
                <Avatar className="size-10 ring-2 ring-primary/15">
                  <AvatarFallback className="bg-primary/10 text-[13px] font-semibold text-primary">
                    {initials(device?.name)}
                  </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1 leading-tight">
                  <div className="truncate text-[14px] font-semibold text-foreground">
                    {device?.name ?? t("pos.header.user_fallback")}
                  </div>
                  {device?.branch_name && (
                    <div className="mt-0.5 flex items-center gap-1 truncate text-[11px] font-normal text-muted-foreground">
                      <StoreIcon className="size-3 shrink-0" />
                      <span className="truncate">{device.branch_name}</span>
                    </div>
                  )}
                </div>
              </div>
            </DropdownMenuLabel>

            {openSession && (
              <>
                <div className="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t("pos.header.menu.shift_section")}
                </div>
                {!isOnCloseShift && (
                  <ShiftMenuItem
                    icon={<LockIcon className="size-4 text-primary" />}
                    iconBg="bg-primary/10"
                    title={t("shift.close.menu_item")}
                    description={t("shift.close.menu_desc")}
                    onClick={() => navigate(`/shop/${shopSlug}/shift/close`)}
                  />
                )}
                <ShiftMenuItem
                  icon={
                    <BanknoteIcon className="size-4 text-emerald-600 dark:text-emerald-400" />
                  }
                  iconBg="bg-emerald-500/10"
                  title={t("shift.cash_event.menu_item")}
                  description={t("shift.cash_event.menu_desc")}
                  onClick={() => setCashEventOpen(true)}
                />
                <ShiftMenuItem
                  icon={
                    <BanIcon className="size-4 text-amber-600 dark:text-amber-400" />
                  }
                  iconBg="bg-amber-500/10"
                  title={t("shift.abandon.menu_item")}
                  description={t("shift.abandon.menu_desc")}
                  onClick={() => setAbandonOpen(true)}
                />
              </>
            )}

            <DropdownMenuSeparator className="my-0" />
            {/* plan-056 — "Tồn món". Sits ABOVE Settings on purpose: this is a
                during-service action a shop reaches for several times a day
                (something ran out), while Settings is configuration touched
                once a month. No open shift required — running out of an
                ingredient does not wait for one. */}
            <ShiftMenuItem
              icon={
                <UtensilsCrossedIcon className="size-4 text-amber-600 dark:text-amber-400" />
              }
              iconBg="bg-amber-500/10"
              title={t("menu_availability.menu_item")}
              description={t("menu_availability.menu_desc")}
              onClick={() => navigate(`/shop/${shopSlug}/menu-availability`)}
            />
            <DropdownMenuSeparator className="my-0" />
            {/* Settings — always available (no open shift required). */}
            <ShiftMenuItem
              icon={
                <Settings2Icon className="size-4 text-slate-600 dark:text-slate-300" />
              }
              iconBg="bg-slate-500/10"
              title={t("settings.close_report.menu_item")}
              description={t("settings.close_report.menu_desc")}
              onClick={() => navigate(`/shop/${shopSlug}/settings`)}
            />
            <DropdownMenuSeparator className="my-0" />
            <DropdownMenuItem
              onClick={logout}
              className="cursor-pointer gap-2.5 px-4 py-3 text-destructive focus:bg-destructive/10 focus:text-destructive"
            >
              <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-destructive/10">
                <LogOutIcon className="size-4 text-destructive" />
              </span>
              <span className="text-[14px] font-medium">
                {t("pos.header.logout")}
              </span>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {openSession && (
        <>
          <CashEventDialog
            shopSlug={shopSlug}
            sessionId={openSession.id}
            open={cashEventOpen}
            onOpenChange={setCashEventOpen}
          />
          <AbandonShiftDialog
            shopSlug={shopSlug}
            sessionId={openSession.id}
            open={abandonOpen}
            onOpenChange={setAbandonOpen}
            onAbandoned={() => navigate(`/shop/${shopSlug}/shift/open`)}
          />
        </>
      )}
    </header>
  );
}

/**
 * Reusable dropdown item for shift actions. Renders icon-in-soft-circle on
 * the left + title + small description, matching the dialog header pattern
 * used across pos-web (payment-dialog, void-order-dialog, etc).
 */
function ShiftMenuItem({
  icon,
  iconBg,
  title,
  description,
  onClick,
}: {
  icon: React.ReactNode;
  iconBg: string;
  title: string;
  description: string;
  onClick: () => void;
}) {
  return (
    <DropdownMenuItem
      onClick={onClick}
      className="cursor-pointer gap-2.5 px-4 py-2.5 focus:bg-accent"
    >
      <span
        className={cn(
          "flex size-8 shrink-0 items-center justify-center rounded-full",
          iconBg,
        )}
      >
        {icon}
      </span>
      <div className="min-w-0 flex-1 leading-tight">
        <div className="text-[13px] font-medium text-foreground">{title}</div>
        <div className="mt-0.5 truncate text-[11px] text-muted-foreground">
          {description}
        </div>
      </div>
    </DropdownMenuItem>
  );
}

/**
 * Generate 1-2 char initials from a device / user name. Falls back to "?"
 * so the Avatar slot is never blank.
 */
function initials(name?: string | null): string {
  if (!name) return "?";
  const parts = name.trim().split(/[\s-_/]+/).filter(Boolean);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
