"use client";

/**
 * HQ template editor — plan-053 M4 (#1171), T4.1.
 *
 * Three tabs over one draft: the block FORM, the structural preview, and the
 * version history with its diff. The lifecycle rules the screen has to make
 * visible rather than merely obey:
 *
 *   TR-01  nothing published yet → the system default is what prints, said out
 *          loud instead of shown as an empty editor.
 *   TR-09  the draft carries an optimistic-lock token derived from its CONTENT.
 *          A 409 is NOT auto-merged — the loser reloads, because merging two
 *          layouts produces a slip neither author designed.
 *   TR-10  publishing a draft whose parent is no longer live is a 409 too.
 *   TR-38  rollback republishes an old definition as a NEW version.
 */

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Spinner,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import { RotateCcw, Save, TriangleAlert, Upload } from "lucide-react";
import { toast } from "sonner";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { TemplateBlockEditor } from "@/components/shared/print-template/template-block-editor";
import { TemplatePreview } from "@/components/shared/print-template/template-preview";
import { useTranslation } from "@/providers/app-provider";
import {
  conflictCodeOf,
  useBrandPrintTemplate,
  usePublishBrandPrintTemplate,
  useRetireBrandPrintTemplate,
  useRollbackBrandPrintTemplate,
  useSaveBrandPrintTemplateDraft,
  violationsOf,
} from "@/hooks/api/use-print-templates";
import type {
  PaperSize,
  PrintLocale,
  PrintTemplateDefinition,
  PrintTemplateViolation,
} from "@/types/models/PrintTemplate";
import { PublishDialog } from "@/components/shared/print-template/publish-dialog";
import { VersionHistory } from "./components/version-history";

export default function BrandPrintTemplateEditorPage() {
  const { brandSlug, kind } = useParams<{ brandSlug: string; kind: string }>();
  const { t } = useTranslation();

  const { data, isLoading, isFetching, refetch } = useBrandPrintTemplate(brandSlug, kind);
  const detail = data?.data;

  const [definition, setDefinition] = useState<PrintTemplateDefinition | null>(null);
  const [shopEditable, setShopEditable] = useState<string[]>([]);
  const [lockToken, setLockToken] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [violations, setViolations] = useState<PrintTemplateViolation[]>([]);
  const [conflict, setConflict] = useState<string | null>(null);
  const [publishOpen, setPublishOpen] = useState(false);

  const [paper, setPaper] = useState<PaperSize>("80mm");
  const [locale, setLocale] = useState<PrintLocale>("ja");

  const saveDraft = useSaveBrandPrintTemplateDraft(brandSlug, kind);
  const publish = usePublishBrandPrintTemplate(brandSlug, kind);
  const retire = useRetireBrandPrintTemplate(brandSlug, kind);
  const rollback = useRollbackBrandPrintTemplate(brandSlug, kind);

  // Server state seeds the editor: the open draft first, then the live version,
  // then the system default (TR-01 — a brand with nothing published still edits
  // a complete, publishable document).
  const serverSignature = detail
    ? `${detail.draft?.lock_token ?? "no-draft"}:${detail.published?.version ?? 0}`
    : "";

  useEffect(() => {
    if (!detail || dirty) return;
    const source =
      detail.draft?.definition ?? detail.published?.definition ?? detail.system_default;
    setDefinition(structuredClone(source));
    setShopEditable([...(detail.draft?.shop_editable ?? detail.published?.shop_editable ?? [])]);
    setLockToken(detail.draft?.lock_token ?? null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverSignature]);

  const isMutating =
    saveDraft.isPending || publish.isPending || retire.isPending || rollback.isPending;

  const handleError = (error: unknown, fallback: string) => {
    const found = violationsOf(error);
    if (found) {
      setViolations(found);
      setConflict(null);
      toast.error(t("print_templates.toast.invalid", { count: found.length }));
      return;
    }
    const code = conflictCodeOf(error);
    if (code) {
      setViolations([]);
      setConflict(code);
      toast.error(t(`print_templates.conflict.${code}`));
      return;
    }
    toast.error(error instanceof Error ? error.message : fallback);
  };

  const onSaveDraft = async () => {
    if (!definition) return;
    try {
      const result = await saveDraft.mutateAsync({
        definition,
        shop_editable: shopEditable,
        lock_token: lockToken,
      });
      setLockToken(result.data.lock_token);
      setDirty(false);
      setViolations([]);
      setConflict(null);
      toast.success(t("print_templates.toast.draft_saved"));
    } catch (error) {
      handleError(error, t("print_templates.toast.draft_failed"));
    }
  };

  const onPublish = async (input: { effective_from: string | null; notes: string | null }) => {
    try {
      const result = await publish.mutateAsync(input);
      setPublishOpen(false);
      setDirty(false);
      setViolations([]);
      setConflict(null);
      setLockToken(null);
      toast.success(t("print_templates.toast.published", { version: result.data.version }));
      void refetch();
    } catch (error) {
      handleError(error, t("print_templates.toast.publish_failed"));
    }
  };

  const resetToServer = () => {
    if (!detail) return;
    const source =
      detail.draft?.definition ?? detail.published?.definition ?? detail.system_default;
    setDefinition(structuredClone(source));
    setShopEditable([...(detail.draft?.shop_editable ?? detail.published?.shop_editable ?? [])]);
    setDirty(false);
    setViolations([]);
    setConflict(null);
  };

  const kindLabel = t(`print_templates.kind.${kind}`);

  const statusBadge = useMemo(() => {
    if (!detail) return null;
    if (!detail.published) {
      return (
        <Badge
          color="info"
          variant="soft"
          className="text-[10px]"
          data-testid="using-system-default"
        >
          {t("print_templates.badge.system_default")}
        </Badge>
      );
    }
    return (
      <Badge color="success" variant="soft" className="text-[10px]">
        {t("print_templates.badge.brand_version", { version: detail.published.version })}
      </Badge>
    );
  }, [detail, t]);

  return (
    <>
      <PageHeader
        title={kindLabel}
        description={t("print_templates.hq.editor_subtitle")}
        backHref={`/hq/${brandSlug}/print-templates`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          onClick={resetToServer}
          disabled={isMutating || !detail}
        >
          <RotateCcw className="size-3.5" />
          {t("print_templates.action.reset")}
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          onClick={onSaveDraft}
          disabled={isMutating || !definition}
          data-testid="save-draft"
        >
          {saveDraft.isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
          {t("print_templates.action.save_draft")}
        </Button>
        <Button
          size="sm"
          className="h-7 gap-1 text-xs"
          onClick={() => setPublishOpen(true)}
          disabled={isMutating || !detail?.draft}
          data-testid="open-publish"
        >
          <Upload className="size-3.5" />
          {t("print_templates.action.publish")}
        </Button>
      </PageHeader>

      <PageContent className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2">
          {statusBadge}
          {detail?.draft && (
            <Badge color="warning" variant="soft" className="text-[10px]">
              {t("print_templates.badge.draft_open")}
            </Badge>
          )}
          {dirty && (
            <span className="text-[11px] text-amber-600">
              {t("print_templates.unsaved_changes")}
            </span>
          )}
        </div>

        {conflict && (
          <Alert color="destructive" data-testid="conflict-alert">
            <TriangleAlert className="size-4" />
            <AlertTitle>{t(`print_templates.conflict.${conflict}`)}</AlertTitle>
            <AlertDescription>
              <div className="flex items-center gap-2">
                <span>{t("print_templates.conflict.hint")}</span>
                <Button
                  size="sm"
                  variant="outline"
                  className="h-7 text-xs"
                  onClick={() => {
                    setDirty(false);
                    setConflict(null);
                    void refetch();
                  }}
                >
                  {t("print_templates.action.reload")}
                </Button>
              </div>
            </AlertDescription>
          </Alert>
        )}

        {violations.length > 0 && (
          <Alert color="destructive" data-testid="violations-alert">
            <TriangleAlert className="size-4" />
            <AlertTitle>
              {t("print_templates.violations.title", { count: violations.length })}
            </AlertTitle>
            <AlertDescription>
              <ul className="mt-1 flex flex-col gap-1">
                {violations.map((violation, index) => (
                  <li key={`${violation.path}-${index}`} className="text-xs">
                    <span className="font-mono">{violation.path}</span> — {violation.message}
                    <span className="ml-1 text-muted-foreground">[{violation.code}]</span>
                  </li>
                ))}
              </ul>
            </AlertDescription>
          </Alert>
        )}

        {isLoading || !definition || !detail ? (
          <div className="flex items-center gap-2 p-6 text-xs text-muted-foreground">
            <Spinner className="size-3.5" />
            {t("common.loading")}
          </div>
        ) : (
          <Tabs defaultValue="editor">
            <TabsList className="h-8">
              <TabsTrigger value="editor" className="text-xs">
                {t("print_templates.tab.editor")}
              </TabsTrigger>
              <TabsTrigger value="preview" className="text-xs" data-testid="tab-preview">
                {t("print_templates.tab.preview")}
              </TabsTrigger>
              <TabsTrigger value="history" className="text-xs" data-testid="tab-history">
                {t("print_templates.tab.history")}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="editor" className="mt-3">
              <TemplateBlockEditor
                definition={definition}
                catalog={detail.catalog}
                mode="brand"
                shopEditable={shopEditable}
                onShopEditableChange={(paths) => {
                  setShopEditable(paths);
                  setDirty(true);
                }}
                onChange={(next) => {
                  setDefinition(next);
                  setDirty(true);
                }}
                disabled={isMutating}
              />
            </TabsContent>

            <TabsContent value="preview" className="mt-3">
              <TemplatePreview
                scope="brand"
                slug={brandSlug}
                kind={kind}
                definition={definition}
                paper={paper}
                onPaperChange={setPaper}
                locale={locale}
                onLocaleChange={setLocale}
              />
            </TabsContent>

            <TabsContent value="history" className="mt-3">
              <VersionHistory
                brandSlug={brandSlug}
                kind={kind}
                isMutating={isMutating}
                onRollback={async (version) => {
                  try {
                    const result = await rollback.mutateAsync({ versionId: version.id });
                    setDirty(false);
                    toast.success(
                      t("print_templates.toast.rolled_back", {
                        from: version.version,
                        version: result.data.version,
                      })
                    );
                    void refetch();
                  } catch (error) {
                    handleError(error, t("print_templates.toast.rollback_failed"));
                  }
                }}
                onRetire={async (version) => {
                  try {
                    await retire.mutateAsync(version.id);
                    toast.success(t("print_templates.toast.retired", { version: version.version }));
                    void refetch();
                  } catch (error) {
                    handleError(error, t("print_templates.toast.retire_failed"));
                  }
                }}
              />
            </TabsContent>
          </Tabs>
        )}
      </PageContent>

      <PublishDialog
        open={publishOpen}
        onOpenChange={setPublishOpen}
        isPending={publish.isPending}
        onConfirm={onPublish}
      />
    </>
  );
}
