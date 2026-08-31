"use client";

import { useRef, useState } from "react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
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
} from "@godxjp/ui";
import {
  ArrowUpDown,
  EllipsisVertical,
  GripVertical,
  Pencil,
  Plus,
  Power,
  Trash2,
  X,
} from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import {
  useFloatingSectionSchedules,
  useCreateFloatingSectionSchedule,
  useUpdateFloatingSectionSchedule,
  useDeleteFloatingSectionSchedule,
  useReorderFloatingSectionSchedules,
} from "@/hooks/api/use-floating-section-schedules";
import type { FloatingSectionSchedule } from "@/types/models/FloatingSectionSchedule";
import {
  formValuesToPayload,
  findOverlappingSchedules,
  type ScheduleSubmitPayload,
} from "@/lib/menuSchedule";
import {
  FloatingSectionScheduleFormDialog,
  type FormValues as ScheduleFormValues,
} from "@/components/shared/floating-section-schedule-form-dialog";

// Mirrors menus/[menuId]/items/page.tsx's SchedulesSection, parented to a
// FloatingSection instead of a Menu.
export function FloatingSectionSchedulesSection({
  brandSlug,
  sectionId,
}: {
  brandSlug: string;
  sectionId: string;
}) {
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useFloatingSectionSchedules(brandSlug, sectionId);
  const schedules: FloatingSectionSchedule[] = data?.data ?? [];

  const createMut = useCreateFloatingSectionSchedule(brandSlug, sectionId);
  const updateMut = useUpdateFloatingSectionSchedule(brandSlug, sectionId);
  const deleteMut = useDeleteFloatingSectionSchedule(brandSlug, sectionId);
  const reorderMut = useReorderFloatingSectionSchedules(brandSlug, sectionId);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<FloatingSectionSchedule | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<FloatingSectionSchedule | null>(null);
  const [overlapPending, setOverlapPending] = useState<{
    payload: ScheduleSubmitPayload;
    overlaps: FloatingSectionSchedule[];
  } | null>(null);

  const [isReordering, setIsReordering] = useState(false);
  const [reorderItems, setReorderItems] = useState<FloatingSectionSchedule[]>([]);
  const [hasReorderChanged, setHasReorderChanged] = useState(false);
  const [draggingIndex, setDraggingIndex] = useState<number | null>(null);
  const dragItemIndex = useRef<number | null>(null);
  const dragOverItemIndex = useRef<number | null>(null);

  function enterReorderMode() {
    setReorderItems([...schedules].sort((a, b) => a.priority - b.priority));
    setHasReorderChanged(false);
    setIsReordering(true);
  }

  function exitReorderMode() {
    setIsReordering(false);
    setHasReorderChanged(false);
    setDraggingIndex(null);
  }

  function onDragStart(e: React.DragEvent<HTMLDivElement>, index: number) {
    dragItemIndex.current = index;
    setDraggingIndex(index);
    e.dataTransfer.effectAllowed = "move";
    setTimeout(() => {
      (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]")!.style.opacity = "0.4";
    }, 0);
  }

  function onDragEnter(_e: React.DragEvent<HTMLDivElement>, index: number) {
    dragOverItemIndex.current = index;
  }

  function onDragOver(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
  }

  function onDrop(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    const from = dragItemIndex.current;
    const to = dragOverItemIndex.current;
    if (from !== null && to !== null && from !== to) {
      const updated = [...reorderItems];
      const [removed] = updated.splice(from, 1);
      updated.splice(to, 0, removed);
      setReorderItems(updated);
      setHasReorderChanged(true);
    }
    const row = (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]");
    if (row) row.style.opacity = "1";
    dragItemIndex.current = null;
    dragOverItemIndex.current = null;
    setDraggingIndex(null);
  }

  function onDragEnd(e: React.DragEvent<HTMLDivElement>) {
    const row = (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]");
    if (row) row.style.opacity = "1";
    dragItemIndex.current = null;
    dragOverItemIndex.current = null;
    setDraggingIndex(null);
  }

  async function handleSaveOrder() {
    await reorderMut.mutateAsync(reorderItems.map((s) => s.id));
    setHasReorderChanged(false);
    setIsReordering(false);
  }

  async function commitSubmit(payload: ScheduleSubmitPayload) {
    if (editTarget) {
      await updateMut.mutateAsync({ scheduleId: editTarget.id, data: payload });
    } else {
      await createMut.mutateAsync(payload);
    }
    setDialogOpen(false);
    setEditTarget(null);
    setOverlapPending(null);
  }

  async function handleSubmit(values: ScheduleFormValues) {
    const payload = formValuesToPayload(values);

    const overlaps = findOverlappingSchedules(payload, schedules, editTarget?.id);
    if (overlaps.length > 0) {
      setOverlapPending({ payload, overlaps });
      return;
    }

    await commitSubmit(payload);
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    await deleteMut.mutateAsync(deleteTarget.id);
    setDeleteTarget(null);
  }

  const isSubmitting = createMut.isPending || updateMut.isPending;

  return (
    <div className="px-4 py-3">
      <div className="mb-2.5 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold">{t("hq.floating_sections.schedules.tab")}</span>
          {!isLoading && schedules.length > 0 && (
            <Badge variant="outline" className="h-5 text-[10px]">
              {schedules.length}
            </Badge>
          )}
          {!isLoading && schedules.length === 0 && (
            <span className="text-xs text-muted-foreground">
              {t("hq.floating_sections.schedules.empty_title")}
            </span>
          )}
        </div>
        {isReordering ? (
          <div className="flex items-center gap-1.5">
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={exitReorderMode}
              disabled={reorderMut.isPending}
            >
              <X className="size-3.5" />
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={handleSaveOrder}
              disabled={!hasReorderChanged || reorderMut.isPending}
            >
              {reorderMut.isPending && <Spinner className="mr-1 size-3.5" />}
              {t("common.save_changes")}
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-1.5">
            {schedules.length > 1 && (
              <Button
                variant="outline"
                size="sm"
                className="h-7 gap-1 text-xs"
                onClick={enterReorderMode}
              >
                <ArrowUpDown className="size-3.5" />
                {t("hq.menus.actions.reorder")}
              </Button>
            )}
            <Button
              size="sm"
              className="h-7 gap-1.5 text-xs"
              onClick={() => {
                setEditTarget(null);
                setDialogOpen(true);
              }}
            >
              <Plus className="size-3.5" />
              {t("hq.menus.schedules.add")}
            </Button>
          </div>
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
      ) : isReordering ? (
        <div className="flex flex-col gap-1">
          {reorderItems.map((s, index) => (
            <div
              key={s.id}
              data-drag-row
              draggable
              onDragStart={(e) => onDragStart(e, index)}
              onDragEnter={(e) => onDragEnter(e, index)}
              onDragOver={onDragOver}
              onDrop={onDrop}
              onDragEnd={onDragEnd}
              className={[
                "flex cursor-grab items-center gap-2.5 rounded-md border bg-card px-2.5 py-2 select-none",
                "transition-all active:cursor-grabbing",
                draggingIndex === index
                  ? "border-primary/40 bg-primary/5 shadow-md"
                  : "hover:border-border/80 hover:shadow-sm",
              ].join(" ")}
            >
              <GripVertical className="size-3.5 shrink-0 text-muted-foreground/40" />
              <span className="inline-flex size-5 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold text-muted-foreground tabular-nums">
                {index + 1}
              </span>
              <span className="flex-1 truncate text-xs tabular-nums">
                {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
              </span>
              <div className="flex shrink-0 flex-wrap gap-1">
                {s.days_of_week_labels.map((day) => (
                  <Badge key={day} variant="outline" className="h-4 text-[9px]">
                    {day}
                  </Badge>
                ))}
              </div>
              <StatusBadge status={s.is_active ? "active" : "inactive"} />
            </div>
          ))}
        </div>
      ) : schedules.length === 0 ? (
        <div className="rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">
          {t("hq.floating_sections.schedules.empty_desc")}
        </div>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="h-8 w-10 text-xs">{t("hq.products.col.stt")}</TableHead>
                <TableHead className="h-8 text-xs">
                  {t("hq.floating_sections.schedules.col_time")}
                </TableHead>
                <TableHead className="h-8 text-xs">
                  {t("hq.floating_sections.schedules.col_days")}
                </TableHead>
                <TableHead className="h-8 w-20 text-center text-xs">
                  {t("hq.floating_sections.schedules.col_priority")}
                </TableHead>
                <TableHead className="h-8 w-24 text-xs">{t("common.status")}</TableHead>
                <TableHead className="h-8 w-14 text-right text-xs">{t("common.action")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {schedules.map((s, index) => (
                <TableRow key={s.id}>
                  <TableCell className="py-2 text-xs text-muted-foreground">{index + 1}</TableCell>
                  <TableCell className="py-2 text-sm tabular-nums">
                    {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
                    {s.start_date || s.end_date ? (
                      <span className="mt-0.5 block text-[10px] text-muted-foreground">
                        {(s.start_date ?? "…") + " → " + (s.end_date ?? "…")}
                      </span>
                    ) : null}
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
                  <TableCell className="py-2 text-center">
                    <span className="inline-flex h-5 items-center rounded bg-blue-50 px-1.5 text-xs font-medium text-blue-700">
                      {s.priority}
                    </span>
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
                        <DropdownMenuItem
                          onClick={() => {
                            setEditTarget(s);
                            setDialogOpen(true);
                          }}
                        >
                          <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          disabled={updateMut.isPending}
                          onClick={() =>
                            updateMut.mutate({
                              scheduleId: s.id,
                              data: { is_active: !s.is_active },
                            })
                          }
                        >
                          <Power className="mr-2 size-3.5" />
                          {s.is_active ? t("common.deactivate") : t("common.activate")}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          className="text-destructive"
                          onClick={() => setDeleteTarget(s)}
                        >
                          <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
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

      <FloatingSectionScheduleFormDialog
        open={dialogOpen}
        onOpenChange={(o) => {
          setDialogOpen(o);
          if (!o) setEditTarget(null);
        }}
        schedule={editTarget}
        onSubmit={handleSubmit}
        isSubmitting={isSubmitting}
      />

      <AlertDialog
        open={!!deleteTarget}
        onOpenChange={(o) => {
          if (!o) setDeleteTarget(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("hq.floating_sections.schedules.delete_confirm_title")}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.floating_sections.schedules.delete_confirm_desc")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMut.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                void handleDelete();
              }}
              disabled={deleteMut.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog
        open={!!overlapPending}
        onOpenChange={(o) => {
          if (!o) setOverlapPending(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.floating_sections.schedules.overlap_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.floating_sections.schedules.overlap_desc")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          {overlapPending && (
            <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
              {overlapPending.overlaps.map((s) => (
                <li key={s.id} className="flex items-center gap-2">
                  <span className="tabular-nums">
                    {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
                  </span>
                  <span className="flex flex-wrap gap-1">
                    {s.days_of_week_labels.map((day) => (
                      <Badge key={day} variant="outline" className="h-4 text-[9px]">
                        {day}
                      </Badge>
                    ))}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isSubmitting}>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                if (overlapPending) void commitSubmit(overlapPending.payload);
              }}
              disabled={isSubmitting}
            >
              {isSubmitting && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.floating_sections.schedules.overlap_proceed")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
