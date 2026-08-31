"use client";

import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { AlertTriangle } from "lucide-react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useHqMaterialLots } from "@/hooks/api/use-material-lots";
import { useInitiateRecall, useRecallPreview } from "@/hooks/api/use-recalls";
import { useTranslation } from "@/providers/app-provider";
import type { RecallScopeType } from "@/services/recall-service";
import {
  Alert,
  AlertDescription,
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertTitle,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Combobox,
  type ComboboxOption,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Textarea,
} from "@godxjp/ui";

export default function HqRecallsNewPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string }>();
  const router = useRouter();

  const [rootLotId, setRootLotId] = useState("");
  const [scopeType, setScopeType] = useState<RecallScopeType>("lot");
  const [reason, setReason] = useState("");
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);

  // Dirty when the user has touched any input away from its default.
  const isDirty = rootLotId !== "" || reason.trim() !== "" || scopeType !== "lot";

  // Cancel guards unsaved changes: confirm before discarding a dirty form,
  // otherwise navigate straight back.
  function handleCancel() {
    if (isDirty) {
      setConfirmExitOpen(true);
    } else {
      router.back();
    }
  }

  const { data: lotList } = useHqMaterialLots(params.brandSlug, { per_page: 200 });
  const lotOptions: ComboboxOption[] = (lotList?.data ?? []).map((l) => ({
    value: l.id,
    label: `${l.lot_code} (${l.material?.sku ?? l.material_id})`,
  }));

  const { data: preview, isLoading: previewLoading } = useRecallPreview(
    params.brandSlug,
    rootLotId || undefined
  );
  const initiate = useInitiateRecall(params.brandSlug);

  const canInitiate = rootLotId !== "" && reason.trim().length >= 3 && !initiate.isPending;

  return (
    <>
      <PageHeader title={t("recall.new_title")} description={t("recall.new_subtitle")}>
        <Button variant="outline" onClick={handleCancel} disabled={initiate.isPending}>
          {t("common.cancel")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>{t("recall.section_input")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="space-y-1">
                <Label>{t("recall.field_root_lot")}</Label>
                <Combobox
                  options={lotOptions}
                  value={rootLotId}
                  onChange={setRootLotId}
                  placeholder={t("recall.field_root_lot_placeholder")}
                />
              </div>
              <div className="space-y-1">
                <Label>{t("recall.field_scope_type")}</Label>
                <Select value={scopeType} onValueChange={(v) => setScopeType(v as RecallScopeType)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="lot">{t("recall.scope.lot")}</SelectItem>
                    <SelectItem value="supplier_lot">{t("recall.scope.supplier_lot")}</SelectItem>
                    <SelectItem value="material">{t("recall.scope.material")}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1">
                <Label htmlFor="reason">{t("recall.field_reason")}</Label>
                <Textarea
                  id="reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  rows={4}
                  maxLength={2000}
                  placeholder={t("recall.field_reason_placeholder")}
                  className="field-sizing-fixed"
                />
                <p className="text-right text-xs text-muted-foreground">{reason.length}/2000</p>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t("recall.section_preview")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {!rootLotId ? (
                <p className="text-sm text-muted-foreground">{t("recall.preview_empty")}</p>
              ) : previewLoading ? (
                <Spinner />
              ) : preview ? (
                <>
                  <Alert variant="default" className="border-amber-300 bg-amber-50">
                    <AlertTriangle className="size-4 text-amber-700" />
                    <AlertTitle>{t("recall.preview_title")}</AlertTitle>
                    <AlertDescription>
                      {t("recall.preview_warning_body", {
                        lots: preview.affected_lots_count,
                        orders: preview.affected_orders_count,
                      })}
                    </AlertDescription>
                  </Alert>
                  <div className="grid grid-cols-2 gap-3 text-sm">
                    <div>
                      <div className="text-muted-foreground">{t("recall.preview_root_lot")}</div>
                      <div className="font-medium">{preview.root_lot.lot_code}</div>
                      {preview.root_lot.supplier_name ? (
                        <div className="text-xs text-muted-foreground">
                          {preview.root_lot.supplier_name}
                        </div>
                      ) : null}
                    </div>
                    <div>
                      <div className="text-muted-foreground">{t("recall.preview_lots")}</div>
                      <div className="text-2xl font-bold tabular-nums">
                        {preview.affected_lots_count}
                      </div>
                    </div>
                    <div>
                      <div className="text-muted-foreground">{t("recall.preview_orders")}</div>
                      <div className="text-2xl font-bold tabular-nums">
                        {preview.affected_orders_count}
                      </div>
                    </div>
                  </div>
                </>
              ) : null}

              <Button
                className="w-full"
                variant="destructive"
                disabled={!canInitiate}
                onClick={() =>
                  initiate.mutate(
                    { root_lot_id: rootLotId, scope_type: scopeType, reason },
                    {
                      onSuccess: (response) => {
                        router.push(`/hq/${params.brandSlug}/recalls/${response.data.id}`);
                      },
                    }
                  )
                }
              >
                {initiate.isPending ? <Spinner className="mr-2" /> : null}
                {t("recall.initiate_button")}
              </Button>
            </CardContent>
          </Card>
        </div>
      </PageContent>

      {/* Unsaved-changes guard — confirm before discarding a dirty new form. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={initiate.isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                router.back();
              }}
              disabled={initiate.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
