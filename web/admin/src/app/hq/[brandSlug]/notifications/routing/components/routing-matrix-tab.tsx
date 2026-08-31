"use client";

/**
 * HQ Notification Routing matrix (plan-012 T3.11) — extracted from the
 * routing page so a sibling Providers tab can live in the same route.
 *
 * Row = dot-namespaced notification type, column = channel + Actions.
 * Each cell is a Switch; priority overrides live behind a per-row Sheet
 * so the main grid stays dense. Delete button wipes the row entirely
 * (sends channels=[] which the backend treats as "no route → fallback").
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import { Bell, Mail, MonitorSmartphone, Radio, Sliders, Trash2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import {
  useChannelRoutes,
  useDeleteChannelRoute,
  useUpsertChannelRoutes,
} from "@/hooks/api/use-channel-routes";
import { useTranslation } from "@/providers/app-provider";
import type {
  ChannelRouteRow,
  NotificationChannel,
  NotificationPriority,
  UpsertChannelRouteInput,
} from "@/services/notification-routing-service";

const CHANNELS: NotificationChannel[] = ["in_app", "realtime", "email", "push"];
const CHANNEL_ICONS: Record<NotificationChannel, typeof Bell> = {
  in_app: Bell,
  realtime: Radio,
  email: Mail,
  push: MonitorSmartphone,
};
const PRIORITIES: NotificationPriority[] = ["low", "normal", "high", "urgent"];

const KNOWN_TYPES = [
  "stock.alert.low",
  "stock.alert.out",
  "order.paid",
  "order.status_changed",
  "recipe.approved",
  "recipe.rejected",
  "system.critical",
];

interface RowState {
  channels: Set<NotificationChannel>;
  priorityOverrides: Partial<Record<NotificationPriority, NotificationChannel[]>>;
  hasServerRow: boolean;
}

type StagingMap = Map<string, RowState>;

function seedStaging(rows: ChannelRouteRow[]): StagingMap {
  const map: StagingMap = new Map();
  KNOWN_TYPES.forEach((t) =>
    map.set(t, { channels: new Set(), priorityOverrides: {}, hasServerRow: false })
  );
  rows.forEach((r) => {
    map.set(r.type, {
      channels: new Set(r.channels),
      priorityOverrides: r.priority_overrides ?? {},
      hasServerRow: true,
    });
  });
  return map;
}

export interface RoutingMatrixTabProps {
  brandSlug: string;
}

export function RoutingMatrixTab({ brandSlug }: RoutingMatrixTabProps) {
  const { t } = useTranslation();
  const { data: routes, isLoading, isError } = useChannelRoutes(brandSlug);
  const upsert = useUpsertChannelRoutes(brandSlug);
  const deleteRoute = useDeleteChannelRoute(brandSlug);

  const [staging, setStaging] = useState<StagingMap>(new Map());
  const [newType, setNewType] = useState("");
  const [dirty, setDirty] = useState(false);
  const [overrideSheetFor, setOverrideSheetFor] = useState<string | null>(null);
  const [deleteConfirmFor, setDeleteConfirmFor] = useState<string | null>(null);

  useEffect(() => {
    if (routes) {
      setStaging(seedStaging(routes));
      setDirty(false);
    }
  }, [routes]);

  const orderedTypes = useMemo(() => Array.from(staging.keys()).sort(), [staging]);

  function toggleCell(type: string, channel: NotificationChannel, next: boolean) {
    setStaging((prev) => {
      const copy = new Map(prev);
      const row = copy.get(type) ?? {
        channels: new Set<NotificationChannel>(),
        priorityOverrides: {},
        hasServerRow: false,
      };
      const channels = new Set(row.channels);
      if (next) channels.add(channel);
      else channels.delete(channel);
      copy.set(type, { ...row, channels });
      return copy;
    });
    setDirty(true);
  }

  function addType() {
    const trimmed = newType.trim();
    if (!trimmed || staging.has(trimmed)) return;
    setStaging((prev) => {
      const copy = new Map(prev);
      copy.set(trimmed, {
        channels: new Set<NotificationChannel>(["in_app"]),
        priorityOverrides: {},
        hasServerRow: false,
      });
      return copy;
    });
    setNewType("");
    setDirty(true);
  }

  function updateOverrides(
    type: string,
    overrides: Partial<Record<NotificationPriority, NotificationChannel[]>>
  ) {
    setStaging((prev) => {
      const copy = new Map(prev);
      const row = copy.get(type);
      if (!row) return copy;
      copy.set(type, { ...row, priorityOverrides: overrides });
      return copy;
    });
    setDirty(true);
  }

  async function handleSave() {
    const payload: UpsertChannelRouteInput[] = [];
    orderedTypes.forEach((type) => {
      const row = staging.get(type);
      if (!row) return;
      if (row.channels.size === 0) return;
      payload.push({
        type,
        channels: Array.from(row.channels),
        priority_overrides:
          Object.keys(row.priorityOverrides).length > 0 ? row.priorityOverrides : null,
      });
    });

    if (payload.length === 0) {
      setDirty(false);
      return;
    }
    await upsert.mutateAsync(payload);
    setDirty(false);
  }

  async function handleDelete(type: string) {
    const row = staging.get(type);
    if (row?.hasServerRow) {
      await deleteRoute.mutateAsync(type);
    }
    setStaging((prev) => {
      const copy = new Map(prev);
      copy.delete(type);
      return copy;
    });
    setDeleteConfirmFor(null);
  }

  const overrideSheetRow = overrideSheetFor ? staging.get(overrideSheetFor) : null;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-end">
        <Button onClick={handleSave} disabled={!dirty || upsert.isPending} size="sm">
          {upsert.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
          {t("notifications.admin.routing.save")}
        </Button>
      </div>

      {isError ? (
        <Alert className="mb-4">
          <AlertDescription>{t("common.error_loading")}</AlertDescription>
        </Alert>
      ) : null}

      <Card>
        <CardContent className="space-y-4 p-4">
          <div className="flex items-end gap-2">
            <div className="max-w-md flex-1">
              <label className="mb-1 block text-xs text-muted-foreground">
                {t("notifications.admin.routing.new_type_label")}
              </label>
              <Input
                placeholder={t("notifications.admin.routing.new_type_placeholder")}
                value={newType}
                onChange={(e) => setNewType(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.nativeEvent.isComposing) {
                    e.preventDefault();
                    addType();
                  }
                }}
              />
            </div>
            <Button variant="outline" onClick={addType} disabled={!newType.trim()}>
              {t("notifications.admin.routing.add_type")}
            </Button>
          </div>

          {isLoading ? (
            <Skeleton className="h-48" />
          ) : orderedTypes.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              {t("notifications.admin.routing.empty")}
            </p>
          ) : (
            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/30">
                    <TableHead className="min-w-[220px]">
                      {t("notifications.admin.routing.column_type")}
                    </TableHead>
                    {CHANNELS.map((c) => {
                      const Icon = CHANNEL_ICONS[c];
                      return (
                        <TableHead key={c} className="text-center">
                          <div className="flex flex-col items-center gap-1">
                            <Icon className="size-3.5 text-muted-foreground" />
                            <span className="text-xs">{t(`notifications.channel.${c}`)}</span>
                          </div>
                        </TableHead>
                      );
                    })}
                    <TableHead className="w-28 text-center">
                      {t("notifications.admin.routing.column_actions")}
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {orderedTypes.map((type) => {
                    const row = staging.get(type);
                    if (!row) return null;
                    const overrideCount = Object.keys(row.priorityOverrides).length;
                    return (
                      <TableRow key={type}>
                        <TableCell className="font-mono text-xs">
                          <div className="flex items-center gap-2">
                            <span>{type}</span>
                            {overrideCount > 0 ? (
                              <Badge variant="secondary" className="text-[10px]">
                                {t("notifications.admin.routing.overrides_count", {
                                  count: overrideCount,
                                })}
                              </Badge>
                            ) : null}
                          </div>
                        </TableCell>
                        {CHANNELS.map((c) => (
                          <TableCell key={c} className="text-center">
                            <Switch
                              checked={row.channels.has(c)}
                              onCheckedChange={(next) => toggleCell(type, c, next)}
                            />
                          </TableCell>
                        ))}
                        <TableCell className="text-center">
                          <div className="flex items-center justify-center gap-1">
                            <Button
                              variant="ghost"
                              size="icon"
                              title={t("notifications.admin.routing.edit_overrides")}
                              onClick={() => setOverrideSheetFor(type)}
                            >
                              <Sliders className="size-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              title={t("notifications.admin.routing.delete_row")}
                              onClick={() => setDeleteConfirmFor(type)}
                            >
                              <Trash2 className="size-4 text-destructive" />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      <Sheet
        open={overrideSheetFor !== null}
        onOpenChange={(open) => !open && setOverrideSheetFor(null)}
      >
        <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
          <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
            <div className="rounded-lg bg-primary/10 p-2.5">
              <Sliders className="size-5 text-primary" />
            </div>
            <div className="flex-1">
              <SheetHeader className="space-y-1 p-0 text-left">
                <SheetTitle className="text-base font-semibold">
                  {t("notifications.admin.routing.overrides_title")}
                </SheetTitle>
                <SheetDescription className="text-xs">
                  {t("notifications.admin.routing.overrides_description", {
                    type: overrideSheetFor ?? "",
                  })}
                </SheetDescription>
              </SheetHeader>
            </div>
          </div>

          <div className="flex-1 space-y-3 overflow-y-auto p-6">
            {PRIORITIES.map((priority) => {
              const overrides = overrideSheetRow?.priorityOverrides ?? {};
              const current = overrides[priority];
              const enabled = current !== undefined;
              const priorityTone =
                priority === "urgent"
                  ? "text-red-600 dark:text-red-400"
                  : priority === "high"
                    ? "text-amber-600 dark:text-amber-400"
                    : priority === "normal"
                      ? "text-blue-600 dark:text-blue-400"
                      : "text-muted-foreground";
              return (
                <div
                  key={priority}
                  className={
                    enabled
                      ? "rounded-lg border border-primary/30 bg-primary/5 p-4 transition"
                      : "rounded-lg border p-4 transition hover:bg-muted/20"
                  }
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span className={`text-sm font-medium capitalize ${priorityTone}`}>
                        {t(`notifications.priority.${priority}`)}
                      </span>
                      {enabled ? (
                        <Badge variant="outline" className="text-[10px]">
                          {current?.length ?? 0}
                        </Badge>
                      ) : null}
                    </div>
                    <Switch
                      checked={enabled}
                      onCheckedChange={(next) => {
                        if (!overrideSheetFor) return;
                        const copy = { ...overrides };
                        if (next) {
                          copy[priority] = Array.from(overrideSheetRow?.channels ?? []);
                        } else {
                          delete copy[priority];
                        }
                        updateOverrides(overrideSheetFor, copy);
                      }}
                    />
                  </div>
                  {enabled ? (
                    <div className="mt-3 flex flex-wrap gap-1.5">
                      {CHANNELS.map((c) => {
                        const has = current?.includes(c);
                        const Icon = CHANNEL_ICONS[c];
                        return (
                          <button
                            key={c}
                            type="button"
                            onClick={() => {
                              if (!overrideSheetFor) return;
                              const next = new Set(current ?? []);
                              if (has) next.delete(c);
                              else next.add(c);
                              const copy = { ...overrides, [priority]: Array.from(next) };
                              updateOverrides(overrideSheetFor, copy);
                            }}
                            className={
                              has
                                ? "inline-flex items-center gap-1 rounded-full border border-primary bg-primary px-2.5 py-1 text-xs text-primary-foreground"
                                : "inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted/30"
                            }
                          >
                            <Icon className="size-3" />
                            {t(`notifications.channel.${c}`)}
                          </button>
                        );
                      })}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>

          <div className="flex items-center justify-end gap-2 border-t bg-background px-6 py-3">
            <Button variant="outline" size="sm" onClick={() => setOverrideSheetFor(null)}>
              {t("common.close")}
            </Button>
          </div>
        </SheetContent>
      </Sheet>

      <Dialog
        open={deleteConfirmFor !== null}
        onOpenChange={(open) => !open && setDeleteConfirmFor(null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("notifications.admin.routing.delete_title")}</DialogTitle>
            <DialogDescription>
              {t("notifications.admin.routing.delete_description", {
                type: deleteConfirmFor ?? "",
              })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteConfirmFor(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteConfirmFor && handleDelete(deleteConfirmFor)}
            >
              {t("notifications.admin.routing.delete_row")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
