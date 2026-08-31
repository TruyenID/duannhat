"use client";

/**
 * Shop override editor — plan-053 M4 (#1171), T4.2.
 *
 * The shop edits the RESOLVED slip (system → brand → shop, already merged), but
 * only the paths the brand delegated are live; everything else is disabled with
 * the reason on the tooltip (TR-03). What is saved is not the whole document —
 * it is a partial OVERLAY containing exactly the delegated paths, so a later
 * brand change to the rest of the slip still reaches this shop (TR-02).
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
import { Lock, RotateCcw, Save, TriangleAlert, Upload } from "lucide-react";
import { toast } from "sonner";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { TemplateBlockEditor } from "@/components/shared/print-template/template-block-editor";
import { TemplatePreview } from "@/components/shared/print-template/template-preview";
import { useTranslation } from "@/providers/app-provider";
import {
  conflictCodeOf,
  usePublishShopPrintTemplate,
  useSaveShopPrintTemplateDraft,
  useShopPrintTemplate,
  violationsOf,
} from "@/hooks/api/use-print-templates";
import type {
  PaperSize,
  PrintTemplateDefinition,
  PrintLocale,
  PrintTemplateViolation,
} from "@/types/models/PrintTemplate";
import { PublishDialog } from "@/components/shared/print-template/publish-dialog";
import { applyOverlay, buildOverlay } from "@/components/shared/print-template/overlay";

export default function ShopPrintTemplateEditorPage() {
  const { shopSlug, kind } = useParams<{ shopSlug: string; kind: string }>();
  const { t } = useTranslation();

  const { data, isLoading, isFetching, refetch } = useShopPrintTemplate(shopSlug, kind);
  const detail = data?.data;

  const [definition, setDefinition] = useState<PrintTemplateDefinition | null>(null);
  const [lockToken, setLockToken] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);
  const [violations, setViolations] = useState<PrintTemplateViolation[]>([]);
  const [conflict, setConflict] = useState<string | null>(null);
  const [publishOpen, setPublishOpen] = useState(false);

  const [paper, setPaper] = useState<PaperSize>("80mm");
  const [locale, setLocale] = useState<PrintLocale>("ja");

  const saveDraft = useSaveShopPrintTemplateDraft(shopSlug, kind);
  const publish = usePublishShopPrintTemplate(shopSlug, kind);

  const allowedPaths = useMemo(() => detail?.shop_editable ?? [], [detail]);
  const lockedByBrand = allowedPaths.length === 0;

  const serverSignature = detail
    ? `${detail.draft?.lock_token ?? "no-draft"}:${detail.resolved.checksum}`
    : "";

  useEffect(() => {
    if (!detail || dirty) return;
    // The editor works on the RESOLVED slip so the shop sees what actually
    // prints; the draft overlay (if any) is merged on top of it by block id.
    setDefinition(applyOverlay(structuredClone(detail.resolved.definition), detail.draft?.definition));
    setLockToken(detail.draft?.lock_token ?? null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverSignature]);

  /*
   * #2043 — the SERVER's catalog, not a client rebuild.
   *
   * This screen used to derive it from hand-copied constants
   * (`catalogFromDefinition`) because the catalog was an HQ-only read. Four
   * silent drifts later the shop endpoint serves it, so the shop editor and the
   * HQ editor now describe the same slip from the same bytes.
   */
  const catalog = detail?.catalog ?? null;

  const isMutating = saveDraft.isPending || publish.isPending;

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
    if (!definition || !catalog) return;
    try {
      const result = await saveDraft.mutateAsync({
        definition: buildOverlay(definition, catalog, allowedPaths),
        lock_token: lockToken,
      });
      setLockToken(result.data?.lock_token ?? null);
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

  const overriddenPaths = detail?.resolved.shop_overridden_paths ?? [];
  const draftOutsideAllowList = useMemo(() => {
    const draftBlocks = detail?.draft?.definition?.blocks ?? [];
    return draftBlocks
      .map((block) => block.id)
      .filter((id) => !allowedPaths.some((path) => path === id || path.startsWith(`${id}.`)));
  }, [detail?.draft, allowedPaths]);

  return (
    <>
      <PageHeader
        title={t(`print_templates.kind.${kind}`)}
        description={t("print_templates.shop.editor_subtitle")}
        backHref={`/shop/${shopSlug}/print-templates`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          disabled={isMutating || !detail}
          onClick={() => {
            setDirty(false);
            void refetch();
          }}
        >
          <RotateCcw className="size-3.5" />
          {t("print_templates.action.reset")}
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          disabled={isMutating || !definition || lockedByBrand}
          onClick={onSaveDraft}
          data-testid="save-draft"
        >
          {saveDraft.isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
          {t("print_templates.action.save_draft")}
        </Button>
        <Button
          size="sm"
          className="h-7 gap-1 text-xs"
          disabled={isMutating || !detail?.draft || lockedByBrand}
          onClick={() => setPublishOpen(true)}
          data-testid="open-publish"
        >
          <Upload className="size-3.5" />
          {t("print_templates.action.publish")}
        </Button>
      </PageHeader>

      <PageContent className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <Badge
            color={
              detail?.resolved.scope === "shop"
                ? "success"
                : detail?.resolved.scope === "brand"
                  ? "info"
                  : "warning"
            }
            variant="soft"
            className="text-[10px]"
          >
            {t(`print_templates.scope.${detail?.resolved.scope ?? "system"}`)}
          </Badge>
          {dirty && (
            <span className="text-[11px] text-amber-600">
              {t("print_templates.unsaved_changes")}
            </span>
          )}
        </div>

        {/* TR-02 — "this shop overrides N things", named. */}
        <div
          className="rounded-md border bg-muted/40 px-3 py-2 text-xs"
          data-testid="override-summary"
        >
          {overriddenPaths.length === 0 ? (
            t("print_templates.shop.no_overrides")
          ) : (
            <>
              {t("print_templates.shop.overriding", { count: overriddenPaths.length })}:{" "}
              <span className="font-mono text-[11px]">{overriddenPaths.join(", ")}</span>
            </>
          )}
        </div>

        {lockedByBrand && (
          <Alert color="warning" data-testid="locked-by-brand-alert">
            <Lock className="size-4" />
            <AlertTitle>{t("print_templates.shop.locked_by_brand")}</AlertTitle>
            <AlertDescription>{t("print_templates.shop.locked_by_brand_hint")}</AlertDescription>
          </Alert>
        )}

        {/* TR-04 — the brand narrowed the allow-list under a stored override. */}
        {draftOutsideAllowList.length > 0 && (
          <Alert color="warning" data-testid="narrowed-allow-list-alert">
            <TriangleAlert className="size-4" />
            <AlertTitle>{t("print_templates.shop.narrowed_title")}</AlertTitle>
            <AlertDescription>
              {t("print_templates.shop.narrowed_hint", {
                paths: draftOutsideAllowList.join(", "),
              })}
            </AlertDescription>
          </Alert>
        )}

        {conflict && (
          <Alert color="destructive" data-testid="conflict-alert">
            <TriangleAlert className="size-4" />
            <AlertTitle>{t(`print_templates.conflict.${conflict}`)}</AlertTitle>
            <AlertDescription>{t("print_templates.conflict.hint")}</AlertDescription>
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

        {isLoading || !definition || !catalog ? (
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
            </TabsList>

            <TabsContent value="editor" className="mt-3">
              <TemplateBlockEditor
                definition={definition}
                catalog={catalog}
                mode="shop"
                allowedPaths={allowedPaths}
                onChange={(next) => {
                  setDefinition(next);
                  setDirty(true);
                }}
                disabled={isMutating || lockedByBrand}
              />
            </TabsContent>

            <TabsContent value="preview" className="mt-3">
              <TemplatePreview
                scope="shop"
                slug={shopSlug}
                kind={kind}
                definition={definition}
                paper={paper}
                onPaperChange={setPaper}
                locale={locale}
                onLocaleChange={setLocale}
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
