"use client";

import { useState } from "react";
import { notFound, useParams, useRouter } from "next/navigation";
import { ArrowLeft, PauseCircle, PlayCircle, Trash2 } from "lucide-react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Badge,
  Button,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import type { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import {
  useFloatingSection,
  useUpdateFloatingSection,
  useDeleteFloatingSection,
} from "@/hooks/api/use-floating-sections";
import { FloatingSectionSchedulesSection } from "./components/schedules-section";
import { FloatingSectionProductsSection } from "./components/products-section";

// Header mirrors Menu's items page (custom sticky header, no PageHeader
// wrapper): icon back button + name + StatusBadge + stat on one line, actions
// on the right. Name/date-range editing lives in FloatingSectionFormDialog,
// opened from the list page only — same split as Menu (list page owns the
// edit dialog, detail page does not).
export default function FloatingSectionDetailPage() {
  const params = useParams<{ brandSlug: string; sectionId: string }>();
  const { brandSlug, sectionId } = params;
  const router = useRouter();
  const { t } = useTranslation();

  const sectionQuery = useFloatingSection(brandSlug, sectionId);
  const section = sectionQuery.data?.data;

  const updateMutation = useUpdateFloatingSection(brandSlug);
  const deleteMutation = useDeleteFloatingSection(brandSlug);

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  // Reported by FloatingSectionProductsSection — its product-list draft owns
  // the Save/Cancel/unsaved state, but those controls render up here in the
  // page header, same position as Menu's items page.
  const [productsState, setProductsState] = useState<{
    hasChanges: boolean;
    isSaving: boolean;
    onSave: () => void;
    onCancel: () => void;
  } | null>(null);
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);

  const disabledAll = updateMutation.isPending || deleteMutation.isPending;

  async function handleToggleActive() {
    if (!section) return;
    await updateMutation.mutateAsync({ id: sectionId, data: { is_active: !section.is_active } });
  }

  async function handleDelete() {
    await deleteMutation.mutateAsync(sectionId);
    router.push(`/hq/${brandSlug}/floating-sections`);
  }

  function handleBack() {
    if (productsState?.hasChanges) {
      setConfirmExitOpen(true);
      return;
    }
    router.push(`/hq/${brandSlug}/floating-sections`);
  }

  if (sectionQuery.isLoading || !section) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    );
  }

  if (sectionQuery.isError) {
    const status = (sectionQuery.error as ApiError)?.status;
    if (status === 404 || status === 403) notFound();

    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
        <p>{t("common.error_loading")}</p>
        <Button variant="outline" size="sm" onClick={() => sectionQuery.refetch()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col">
      {/* Header — mirrors Menu's items page: icon back + name + status + stat, actions on the right */}
      <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center border-b bg-background px-4">
        <div className="flex flex-1 items-center gap-2">
          <button
            type="button"
            onClick={handleBack}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <ArrowLeft className="size-4" />
          </button>
          <h1 className="text-lg font-semibold">{section.name}</h1>
          <StatusBadge status={section.is_active ? "active" : "inactive"} />
          <span className="text-xs text-muted-foreground">
            — {t("hq.floating_sections.col.products", { count: section.products_count ?? 0 })}
          </span>
          {productsState?.hasChanges && (
            <Badge
              variant="outline"
              className="h-5 border-amber-300 bg-amber-50 text-[10px] text-amber-600"
            >
              {t("hq.menus.items.unsaved")}
            </Badge>
          )}
        </div>
        <div className="flex items-center gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-xs"
            onClick={productsState?.onCancel}
            disabled={!productsState?.hasChanges || productsState?.isSaving}
          >
            {t("common.cancel")}
          </Button>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-xs"
            onClick={handleToggleActive}
            disabled={disabledAll}
          >
            {updateMutation.isPending ? (
              <Spinner className="size-3.5" />
            ) : section.is_active ? (
              <PauseCircle className="size-3.5" />
            ) : (
              <PlayCircle className="size-3.5" />
            )}
            {section.is_active ? t("common.deactivate") : t("common.activate")}
          </Button>

          <Button
            type="button"
            variant="destructive"
            size="sm"
            className="h-8 gap-1.5 text-xs"
            onClick={() => setDeleteDialogOpen(true)}
            disabled={disabledAll}
          >
            <Trash2 className="size-3.5" />
            {t("common.delete")}
          </Button>

          <Button
            type="button"
            size="sm"
            className="h-8 gap-1.5 text-xs"
            onClick={productsState?.onSave}
            disabled={!productsState?.hasChanges || productsState?.isSaving}
          >
            {productsState?.isSaving && <Spinner className="size-3.5" />}
            {t("common.save")}
          </Button>
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col">
        {/* Schedules — shaded band, matches menus/[menuId]/items SchedulesSection wrapper */}
        <div className="shrink-0 border-b bg-muted/30">
          <FloatingSectionSchedulesSection brandSlug={brandSlug} sectionId={sectionId} />
        </div>

        {/* Products — two-panel catalog builder, fills remaining viewport height */}
        <div className="flex min-h-0 flex-1">
          <FloatingSectionProductsSection
            brandSlug={brandSlug}
            sectionId={sectionId}
            onStateChange={setProductsState}
          />
        </div>
      </div>

      {/* Unsaved-changes confirmation — mirrors Menu's items page guard */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.menus.items.unsaved_title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.menus.items.unsaved_desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                setConfirmExitOpen(false);
                router.push(`/hq/${brandSlug}/floating-sections`);
              }}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t("hq.menus.items.discard_and_leave")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.floating_sections.delete_confirm_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.floating_sections.delete_confirm", { name: section.name })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMutation.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                void handleDelete();
              }}
              disabled={deleteMutation.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
