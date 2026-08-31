"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import {
  ArrowLeft,
  Clock,
  EllipsisVertical,
  LayoutGrid,
  List,
  Pencil,
  Percent,
  Power,
  RefreshCw,
  UtensilsCrossed,
} from "lucide-react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Skeleton,
  Spinner,
  StatusBadge,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@godxjp/ui";
import {
  useShopMenu,
  useSyncShopMenuFromMaster,
  useUpdateShopMenuTimeout,
} from "@/hooks/api/use-shop-menus";
import {
  useShopMenuSchedules,
  useUpsertScheduleOverride,
  useSetScheduleActive,
} from "@/hooks/api/use-menu-schedules";
import { useTranslation } from "@/providers/app-provider";
import { daysOfWeekToLabels, labelsToDaysOfWeek } from "@/lib/menuSchedule";
import type { ShopMenuProduct } from "@/types/shop";
import type { BranchEffectiveSchedule } from "@/types/models/MenuSchedule";
import { cn } from "@/lib/utils";
import type { SectionGroup, ViewMode } from "./components/types";
import { SectionPanel } from "./components/section-panel";
import { ShopSetTimeoutDialog } from "./components/shop-set-timeout-dialog";

// =========================================================================
//  Helpers
// =========================================================================

function buildSectionGroups(
  menuSections: { id: string; name: string }[],
  menuProducts: ShopMenuProduct[],
  labels: { unknown: string; default: string }
): SectionGroup[] {
  const sectionsById = new Map<string, SectionGroup>();
  for (const s of menuSections) {
    sectionsById.set(s.id, { id: s.id, name: s.name, products: [] });
  }

  const unassigned: ShopMenuProduct[] = [];
  for (const mp of menuProducts) {
    if (mp.menu_section_id) {
      let group = sectionsById.get(mp.menu_section_id);
      if (!group) {
        group = {
          id: mp.menu_section_id,
          name: mp.section?.name ?? labels.unknown,
          products: [],
        };
        sectionsById.set(mp.menu_section_id, group);
      }
      group.products.push(mp);
    } else {
      unassigned.push(mp);
    }
  }

  const ordered: SectionGroup[] = [];
  for (const s of menuSections) {
    const g = sectionsById.get(s.id);
    if (g) ordered.push(g);
  }
  for (const [, g] of sectionsById) {
    if (!ordered.find((s) => s.id === g.id)) ordered.push(g);
  }
  if (unassigned.length > 0) {
    ordered.unshift({ id: "unassigned", name: labels.default, products: unassigned });
  }

  return ordered;
}

function toHHMM(time: string): string {
  return time.slice(0, 5);
}

function toHHMMSS(hhmm: string): string {
  return hhmm.length === 5 ? `${hhmm}:00` : hhmm;
}

/**
 * Is today outside this schedule's campaign window? (#1970)
 *
 * A DISPLAY HINT ONLY. It reads the browser's calendar date, which belongs to
 * whoever is signed in — a Hanoi manager looking at a Tokyo shop can be a day
 * off at the margins. The gate that actually decides what the POS and the guest
 * see is server-side, on the SHOP's business date. Do not promote this into a
 * decision: use it to explain, never to allow or block.
 */
function isWindowClosed(schedule: { start_date: string | null; end_date: string | null }): boolean {
  const today = new Date().toLocaleDateString("en-CA"); // en-CA renders YYYY-MM-DD
  return Boolean(
    (schedule.start_date && today < schedule.start_date) ||
      (schedule.end_date && today > schedule.end_date)
  );
}

// =========================================================================
//  View toggle
// =========================================================================

function ViewToggle({ view, onChange }: { view: ViewMode; onChange: (v: ViewMode) => void }) {
  const { t } = useTranslation();
  return (
    <div className="flex items-center rounded-md border bg-muted/40 p-0.5">
      <button
        type="button"
        onClick={() => onChange("list")}
        className={cn(
          "inline-flex items-center justify-center rounded px-2 py-1 transition-colors",
          view === "list"
            ? "bg-background text-foreground shadow-sm"
            : "text-muted-foreground hover:text-foreground"
        )}
        aria-label={t("shop.menu.detail.list_view")}
      >
        <List className="size-3.5" />
      </button>
      <button
        type="button"
        onClick={() => onChange("grid")}
        className={cn(
          "inline-flex items-center justify-center rounded px-2 py-1 transition-colors",
          view === "grid"
            ? "bg-background text-foreground shadow-sm"
            : "text-muted-foreground hover:text-foreground"
        )}
        aria-label={t("shop.menu.detail.grid_view")}
      >
        <LayoutGrid className="size-3.5" />
      </button>
    </div>
  );
}

// =========================================================================
//  Inline editable time cell
// =========================================================================

interface TimeCellProps {
  scheduleId: string;
  field: "start_time" | "end_time";
  value: string;
  isPending: boolean;
  onSave: (scheduleId: string, field: "start_time" | "end_time", value: string) => void;
}

function TimeCell({ scheduleId, field, value, isPending, onSave }: TimeCellProps) {
  const [editing, setEditing] = useState(false);
  const [localValue, setLocalValue] = useState(toHHMM(value));

  function startEditing() {
    setLocalValue(toHHMM(value));
    setEditing(true);
  }

  function handleBlur() {
    if (localValue !== toHHMM(value)) {
      onSave(scheduleId, field, toHHMMSS(localValue));
    }
    setEditing(false);
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Enter" && !e.nativeEvent.isComposing) {
      e.currentTarget.blur();
    } else if (e.key === "Escape") {
      setLocalValue(toHHMM(value));
      setEditing(false);
    }
  }

  if (editing) {
    return (
      <input
        type="time"
        className="w-24 rounded border border-input bg-background px-1.5 py-0.5 text-sm tabular-nums focus:ring-1 focus:ring-ring focus:outline-none"
        value={localValue}
        autoFocus
        disabled={isPending}
        onChange={(e) => setLocalValue(e.target.value)}
        onBlur={handleBlur}
        onKeyDown={handleKeyDown}
      />
    );
  }

  return (
    <button
      type="button"
      className="rounded px-1 py-0.5 text-sm tabular-nums hover:bg-accent hover:text-accent-foreground focus:ring-1 focus:ring-ring focus:outline-none"
      onClick={startEditing}
      disabled={isPending}
    >
      {isPending ? <Spinner className="size-3.5" /> : toHHMM(value)}
    </button>
  );
}

// =========================================================================
//  Override form dialog (customize HQ schedule for this shop)
// =========================================================================

const OVERRIDE_DAYS_DISPLAY_ORDER = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as const;

function OverrideFormDialog({
  schedule,
  shopSlug,
  menuId,
  open,
  onOpenChange,
}: {
  schedule: BranchEffectiveSchedule | null;
  shopSlug: string;
  menuId: string;
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const { t } = useTranslation();
  const [startTime, setStartTime] = useState("");
  const [endTime, setEndTime] = useState("");
  const [days, setDays] = useState<string[]>([]);
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");
  const upsert = useUpsertScheduleOverride(shopSlug, menuId);

  useEffect(() => {
    if (schedule) {
      setStartTime(toHHMM(schedule.start_time));
      setEndTime(toHHMM(schedule.end_time));
      setDays(daysOfWeekToLabels(schedule.days_of_week));
      setStartDate(schedule.start_date ?? "");
      setEndDate(schedule.end_date ?? "");
    }
  }, [schedule]);

  // Both dates empty is a legitimate answer ("no campaign window"), but an end
  // BEFORE the start is not — the backend 422s on it, so say so here instead of
  // spending a round-trip to be told.
  const dateRangeInvalid = startDate !== "" && endDate !== "" && endDate < startDate;

  async function handleSubmit() {
    if (!schedule || days.length === 0 || dateRangeInvalid) return;
    await upsert.mutateAsync({
      scheduleId: schedule.id,
      data: {
        start_time: toHHMMSS(startTime),
        end_time: toHHMMSS(endTime),
        days_of_week: labelsToDaysOfWeek(days),
        // "" → null, which resets the field to HQ's date rather than clearing
        // the window. A shop cannot un-bound a window HQ has bounded (#1970).
        start_date: startDate === "" ? null : startDate,
        end_date: endDate === "" ? null : endDate,
      },
    });
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined} className="sm:max-w-xs">
        <DialogHeader>
          <DialogTitle className="text-base">
            {t("shop.menu.schedules.override_dialog_title")}
          </DialogTitle>
        </DialogHeader>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="mb-1 block text-xs text-muted-foreground">
              {t("hq.menus.schedules.field_start_time")}
            </label>
            <input
              type="time"
              value={startTime}
              onChange={(e) => setStartTime(e.target.value)}
              className="w-full rounded border border-input bg-background px-2 py-1.5 text-sm tabular-nums focus:ring-1 focus:ring-ring focus:outline-none"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs text-muted-foreground">
              {t("hq.menus.schedules.field_end_time")}
            </label>
            <input
              type="time"
              value={endTime}
              onChange={(e) => setEndTime(e.target.value)}
              className="w-full rounded border border-input bg-background px-2 py-1.5 text-sm tabular-nums focus:ring-1 focus:ring-ring focus:outline-none"
            />
          </div>
        </div>
        <div>
          <label className="mb-1 block text-xs text-muted-foreground">
            {t("hq.menus.schedules.field_days")}
          </label>
          <div className="grid grid-cols-7 gap-1">
            {OVERRIDE_DAYS_DISPLAY_ORDER.map((day) => {
              const checked = days.includes(day);
              return (
                <button
                  key={day}
                  type="button"
                  onClick={() =>
                    setDays(checked ? days.filter((d) => d !== day) : [...days, day])
                  }
                  className={cn(
                    "flex h-8 items-center justify-center rounded-md border text-[11px] font-medium transition-colors select-none",
                    checked
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-input bg-background text-foreground hover:bg-muted"
                  )}
                >
                  {day}
                </button>
              );
            })}
          </div>
          {days.length === 0 && (
            <p className="mt-1 text-[11px] text-destructive">
              {t("shop.menu.schedules.days_required")}
            </p>
          )}
          {schedule && (
            <p className="mt-1 text-[11px] text-muted-foreground">
              {t("shop.menu.schedules.hq_days_hint", {
                days: schedule.hq_defaults.days_of_week_labels?.join(", ") ?? "",
              })}
            </p>
          )}
        </div>
        <div>
          <label className="mb-1 block text-xs text-muted-foreground">
            {t("shop.menu.schedules.field_campaign_window")}
          </label>
          <div className="grid grid-cols-2 gap-3">
            <input
              type="date"
              aria-label={t("hq.menus.schedules.field_start_date")}
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              className="w-full rounded border border-input bg-background px-2 py-1.5 text-sm tabular-nums focus:ring-1 focus:ring-ring focus:outline-none"
            />
            <input
              type="date"
              aria-label={t("hq.menus.schedules.field_end_date")}
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
              aria-invalid={dateRangeInvalid || undefined}
              className="w-full rounded border border-input bg-background px-2 py-1.5 text-sm tabular-nums focus:ring-1 focus:ring-ring focus:outline-none aria-invalid:border-destructive"
            />
          </div>
          {dateRangeInvalid && (
            <p className="mt-1 text-[11px] text-destructive">
              {t("shop.menu.schedules.date_range_invalid")}
            </p>
          )}
          <p className="mt-1 text-[11px] text-muted-foreground">
            {t("shop.menu.schedules.campaign_window_hint")}
          </p>
          {schedule && (schedule.hq_defaults.start_date || schedule.hq_defaults.end_date) && (
            <p className="mt-1 text-[11px] text-muted-foreground">
              {t("shop.menu.schedules.hq_dates_hint", {
                from: schedule.hq_defaults.start_date ?? "—",
                to: schedule.hq_defaults.end_date ?? "—",
              })}
            </p>
          )}
        </div>
        <DialogFooter>
          <Button
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={upsert.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button
            size="sm"
            onClick={handleSubmit}
            disabled={upsert.isPending || days.length === 0 || dateRangeInvalid}
          >
            {upsert.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// =========================================================================
//  Schedules section — single table: Switch (ẩn/hiện) + Action (set custom time)
// =========================================================================

function SchedulesSection({ shopSlug, menuId }: { shopSlug: string; menuId: string }) {
  const { t } = useTranslation();
  const { data, isLoading, isError, refetch } = useShopMenuSchedules(shopSlug, menuId);
  const schedules: BranchEffectiveSchedule[] = data?.data ?? [];

  const setActive = useSetScheduleActive(shopSlug, menuId);

  const [overrideDialogTarget, setOverrideDialogTarget] = useState<BranchEffectiveSchedule | null>(
    null
  );

  return (
    <div className="px-4 py-3">
      <div className="mb-2.5 flex items-center gap-2">
        <span className="text-sm font-semibold">{t("hq.menus.schedules.tab")}</span>
        {!isLoading && schedules.length > 0 && (
          <Badge variant="outline" className="h-5 text-[10px]">
            {schedules.length}
          </Badge>
        )}
      </div>

      {isLoading ? (
        <div className="space-y-1.5">
          {[1, 2].map((i) => (
            <Skeleton key={i} className="h-9 w-full" />
          ))}
        </div>
      ) : isError ? (
        <Alert variant="destructive" className="py-2">
          <AlertTitle className="text-xs">{t("common.error_loading")}</AlertTitle>
          <AlertDescription>
            <Button
              variant="outline"
              size="sm"
              className="mt-1 h-6 text-xs"
              onClick={() => refetch()}
            >
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      ) : schedules.length === 0 ? (
        <div className="rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">
          {t("hq.menus.schedules.empty_desc")}
        </div>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="h-8 w-10 text-xs">{t("hq.products.col.stt")}</TableHead>
                <TableHead className="h-8 text-xs">
                  {t("shop.menu.schedules.col_hq_time")}
                </TableHead>
                <TableHead className="h-8 text-xs">
                  {t("shop.menu.schedules.col_shop_time")}
                </TableHead>
                <TableHead className="h-8 text-xs">{t("hq.menus.schedules.col_days")}</TableHead>
                <TableHead className="h-8 text-xs">
                  {t("shop.menu.schedules.col_campaign_window")}
                </TableHead>
                <TableHead className="h-8 w-24 text-xs">{t("common.status")}</TableHead>
                <TableHead className="h-8 w-14 text-right text-xs">{t("common.action")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {schedules.map((s, index) => (
                <TableRow key={s.id}>
                  <TableCell className="py-2 text-xs text-muted-foreground">{index + 1}</TableCell>
                  <TableCell className="py-2 text-sm text-muted-foreground tabular-nums">
                    {toHHMM(s.hq_defaults.start_time)} – {toHHMM(s.hq_defaults.end_time)}
                  </TableCell>
                  <TableCell className="py-2">
                    {s.is_overridden ? (
                      <span className="text-sm font-medium tabular-nums">
                        {toHHMM(s.start_time)} – {toHHMM(s.end_time)}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell className="py-2">
                    <div className="flex flex-wrap gap-1">
                      {s.days_of_week_labels.map((day) => (
                        <Badge key={day} variant="outline" className="h-5 text-[10px]">
                          {day}
                        </Badge>
                      ))}
                    </div>
                  </TableCell>
                  <TableCell className="py-2 text-sm tabular-nums">
                    {s.start_date || s.end_date ? (
                      <span className={cn(isWindowClosed(s) && "text-destructive")}>
                        {s.start_date ?? "—"} → {s.end_date ?? "—"}
                        {isWindowClosed(s) && (
                          <span className="ml-1 text-[11px]">
                            {t("shop.menu.schedules.window_closed")}
                          </span>
                        )}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">
                        {t("shop.menu.schedules.window_always")}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="py-2">
                    <StatusBadge status={s.is_active ? "active" : "inactive"} />
                  </TableCell>
                  <TableCell className="py-2 text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-7">
                          <EllipsisVertical className="size-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => setOverrideDialogTarget(s)}>
                          <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          disabled={setActive.isPending}
                          onClick={() =>
                            setActive.mutate({ scheduleId: s.id, isActive: !s.is_active })
                          }
                        >
                          <Power className="mr-2 size-3.5" />
                          {s.is_active ? t("common.deactivate") : t("common.activate")}
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {/* Override form dialog */}
      <OverrideFormDialog
        schedule={overrideDialogTarget}
        shopSlug={shopSlug}
        menuId={menuId}
        open={!!overrideDialogTarget}
        onOpenChange={(o) => {
          if (!o) setOverrideDialogTarget(null);
        }}
      />
    </div>
  );
}

// =========================================================================
//  Main Page
// =========================================================================

export default function ShopMenuItemsPage() {
  const { t } = useTranslation();
  const params = useParams<{ shopSlug: string; menuId: string }>();
  const { shopSlug, menuId } = params;

  const [view, setView] = useState<ViewMode>("list");
  const [confirmSyncOpen, setConfirmSyncOpen] = useState(false);
  const [timeoutDialogOpen, setTimeoutDialogOpen] = useState(false);

  const { data: menuResponse, isLoading: menuLoading } = useShopMenu(shopSlug, menuId, {
    compact: true,
  });
  const menu = menuResponse?.data ?? null;
  const syncMut = useSyncShopMenuFromMaster(shopSlug, menuId);
  const updateShopTimeout = useUpdateShopMenuTimeout(shopSlug, menuId);
  const { data: shopSchedulesData } = useShopMenuSchedules(shopSlug, menuId);
  const hasSchedules = menu?.has_schedules ?? (shopSchedulesData?.data?.length ?? 0) > 0;

  const unknownLabel = t("shop.menu.detail.unknown_section");
  const defaultLabel = t("shop.menu.detail.default_section");
  const sectionGroups = useMemo<SectionGroup[]>(
    () =>
      buildSectionGroups(
        menu?.menuSections ?? [],
        menu?.menuProducts ?? menu?.menu_products ?? [],
        {
          unknown: unknownLabel,
          default: defaultLabel,
        }
      ),
    [menu, unknownLabel, defaultLabel]
  );

  // Prefer the backend's authoritative distinct-product count (same source as
  // the menu list) so a product placed in multiple sections is still counted
  // once on both screens. Fall back to rendered placements while the field is
  // loading.
  const totalProducts = useMemo(
    () =>
      menu?.menu_products_count ??
      sectionGroups.reduce((n, g) => n + g.products.length, 0),
    [menu?.menu_products_count, sectionGroups]
  );

  if (menuLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    );
  }

  if (!menu) {
    return (
      <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
        {t("shop.menu.detail.not_found")}
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col">
      {/* Header */}
      <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center justify-between border-b bg-background px-4">
        <div className="flex items-center gap-2">
          <Link
            href={`/shop/${shopSlug}/menus`}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <ArrowLeft className="size-4" />
          </Link>
          <h1 className="text-lg font-semibold">{menu.name}</h1>
          <span className="text-xs text-muted-foreground">
            —{" "}
            {t("shop.menu.detail.products_sections", {
              products: totalProducts,
              sections: sectionGroups.length,
            })}
          </span>
        </div>

        <div className="flex items-center gap-2">
          <ViewToggle view={view} onChange={setView} />

          {/* Timeout button — metadata, hidden when menu has no schedules */}
          {hasSchedules && (
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-xs"
              onClick={() => setTimeoutDialogOpen(true)}
              disabled={updateShopTimeout.isPending}
            >
              <Clock className="size-3.5" />
              {t("shop.menus.timeout.button_label")}
              {menu.shop_menu_timeout_minutes != null ? (
                <span className="ml-0.5 rounded bg-primary/10 px-1 py-0.5 text-[10px] font-medium text-primary tabular-nums">
                  {menu.shop_menu_timeout_minutes}
                  {t("common.timeout.minutes_unit")}
                </span>
              ) : (
                <span className="ml-0.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                  {t("common.timeout.badge_default")}
                </span>
              )}
            </Button>
          )}

          {/* Service type (#1226) — READ-ONLY for the shop. #463 gave the branch
              its own override and this was a button that opened an editor; the
              shop role is not allowed to change how a menu is served, so it now
              only reports the value. Rendered as plain text rather than a
              disabled Button on purpose: a greyed-out control reads as "you may
              do this later" and invites clicking, while this is never the shop's
              decision to make. */}
          <span
            className="flex h-8 items-center gap-1.5 px-1 text-xs text-muted-foreground"
            title={t("shop.menus.service_type.readonly_hint")}
          >
            <UtensilsCrossed className="size-3.5" />
            {t(
              (menu.effective_service_type ?? "Both") === "Takeaway"
                ? "hq.menus.form.service_type_takeaway"
                : (menu.effective_service_type ?? "Both") === "DineIn"
                  ? "hq.menus.form.service_type_dinein"
                  : "hq.menus.form.service_type_both"
            )}
            {menu.shop_service_type == null && (
              <span className="ml-0.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                {t("shop.menus.service_type.badge_default")}
              </span>
            )}
          </span>

          {/* #1227 — the whole-menu tax tier. Read-only: HQ owns tax (#1226),
              and the shop value is what HQ last synced down. Rendered even when
              unset: the shop needs to see that this tier imposes nothing, which
              is the only way to tell "every line is 10% because the menu says
              so" apart from "every line happens to be 10% on its own". Unset
              reuses HQ's own wording for that state ("inherit (product/shop)")
              so the shop reads back the same words HQ chose. It deliberately
              shows NO number — a section can hold lines at different rates (see
              this menu: 8% and 10% side by side), so any single figure here
              would be a lie. */}
          <span
            className="flex h-8 items-center gap-1.5 px-1 text-xs text-muted-foreground"
            title={t("shop.menu.detail.tax_readonly_hint")}
          >
            <Percent className="size-3.5" />
            <span className="tabular-nums">
              {menu.tax_rate != null
                ? t("shop.menu.detail.tax_menu_badge", { rate: menu.tax_rate })
                : t("shop.menu.detail.tax_menu_inherit")}
            </span>
          </span>

          {menu.master_menu_id && (
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-xs"
              onClick={() => setConfirmSyncOpen(true)}
              disabled={syncMut.isPending}
              data-testid="shop-menu-sync"
            >
              {syncMut.isPending ? (
                <Spinner className="size-3.5" />
              ) : (
                <RefreshCw className="size-3.5" />
              )}
              {t("shop.menu.detail.sync_from_master")}
            </Button>
          )}
        </div>
      </div>

      <Dialog open={confirmSyncOpen} onOpenChange={setConfirmSyncOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.sync_confirm_title")}</DialogTitle>
            <DialogDescription>{t("shop.menu.sync_confirm_description")}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirmSyncOpen(false)}
              disabled={syncMut.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={() => {
                syncMut.mutate(undefined, {
                  onSettled: () => setConfirmSyncOpen(false),
                });
              }}
              disabled={syncMut.isPending}
              data-testid="shop-menu-sync-confirm"
            >
              {syncMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Cart timeout dialog */}
      <ShopSetTimeoutDialog
        open={timeoutDialogOpen}
        onOpenChange={setTimeoutDialogOpen}
        hqBrandTimeoutMinutes={menu.hq_brand_timeout_minutes}
        hqMenuTimeoutMinutes={menu.hq_menu_timeout_minutes}
        shopDefaultTimeoutMinutes={menu.shop_default_timeout_minutes}
        shopMenuTimeoutMinutes={menu.shop_menu_timeout_minutes}
        effectiveTimeoutMinutes={menu.effective_timeout_minutes}
        isPending={updateShopTimeout.isPending}
        onSave={(minutes) => updateShopTimeout.mutate(minutes)}
      />

      {/* Body: schedules on top, items below */}
      <div className="flex min-h-0 flex-1 flex-col">
        {/* Schedules section */}
        <div className="shrink-0 border-b bg-muted/30">
          <SchedulesSection shopSlug={shopSlug} menuId={menuId} />
        </div>

        {/* Items section */}
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {sectionGroups.length === 0 && (
            <div className="flex flex-col items-center justify-center py-16 text-center text-sm text-muted-foreground">
              <UtensilsCrossed className="mb-2 size-8 text-muted-foreground/40" />
              {t("shop.menu.detail.no_sections")}
            </div>
          )}

          {sectionGroups.map((section) => (
            <SectionPanel
              key={section.id}
              section={section}
              shopSlug={shopSlug}
              menuId={menuId}
              view={view}
              taxRate={menu.section_tax_rates?.[section.id] ?? null}
            />
          ))}
        </div>
      </div>
    </div>
  );
}
