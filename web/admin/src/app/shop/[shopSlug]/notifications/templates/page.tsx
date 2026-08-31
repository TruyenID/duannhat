"use client";

/**
 * Plan-023 M6 T6.11 — shop template overrides page (list + create + delete).
 */

import {
  Alert,
  AlertDescription,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { AlertCircle, FileText, Plus, Trash2 } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import {
  useShopTemplateCreate,
  useShopTemplateDelete,
  useShopTemplates,
} from "@/hooks/api/use-shop-notifications";
import { useTranslation } from "@/providers/app-provider";
import type { ShopTemplateRow } from "@/services/shop-notification-service";

const DEFAULT_CONTENT_JSON = JSON.stringify(
  {
    ja: { title: "（タイトル）", body: "（本文）" },
    en: { title: "(title)", body: "(body)" },
    vi: { title: "(tiêu đề)", body: "(nội dung)" },
  },
  null,
  2
);

export default function ShopTemplatesPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const [editor, setEditor] = useState<{ key: string; contentJson: string } | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<ShopTemplateRow | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useShopTemplates(shopSlug);
  const createMutation = useShopTemplateCreate(shopSlug);
  const deleteMutation = useShopTemplateDelete(shopSlug);

  const rows = data?.data ?? [];

  async function onSave() {
    if (!editor) return;
    setSaveError(null);
    let content: Record<string, unknown>;
    try {
      content = JSON.parse(editor.contentJson);
    } catch (e) {
      setSaveError(`Invalid JSON: ${(e as Error).message}`);
      return;
    }
    try {
      await createMutation.mutateAsync({ key: editor.key, content });
      setEditor(null);
    } catch (e) {
      setSaveError((e as Error).message);
    }
  }

  return (
    <>
      <PageHeader
        title={t("notifications.shop.templates.title")}
        description={t("notifications.shop.templates.subtitle")}
      >
        <Button size="sm" onClick={() => setEditor({ key: "", contentJson: DEFAULT_CONTENT_JSON })}>
          <Plus className="mr-1 size-3.5" />
          {t("notifications.shop.templates.create")}
        </Button>
        <HelpPanel
          title={t("notifications.shop.templates.title")}
          subtitle={t("help.panel.shop_notifications_templates.subtitle")}
          purpose={t("help.panel.shop_notifications_templates.purpose")}
          usage={[
            t("help.panel.shop_notifications_templates.usage.1"),
            t("help.panel.shop_notifications_templates.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_notifications_templates.checks.1"),
            t("help.panel.shop_notifications_templates.checks.2"),
            t("help.panel.shop_notifications_templates.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_notifications_templates.glossary.key.term"),
              description: t("help.panel.shop_notifications_templates.glossary.key.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        {isError ? (
          <Alert variant="destructive" className="mb-4">
            <AlertCircle className="size-4" />
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {isLoading ? (
          <div className="space-y-2">
            {[0, 1].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <Card>
            <CardContent className="p-8 text-center text-sm text-muted-foreground">
              <FileText className="mx-auto mb-3 size-8 text-muted-foreground/60" />
              {t("notifications.shop.templates.empty")}
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-2">
            {rows.map((row) => (
              <Card key={row.id}>
                <CardContent className="flex items-center gap-3 p-3">
                  <p className="flex-1 truncate font-mono text-sm">{row.key}</p>
                  <Button variant="ghost" size="icon" onClick={() => setConfirmDelete(row)}>
                    <Trash2 className="size-4" />
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </PageContent>

      <Sheet open={editor !== null} onOpenChange={(o) => !o && setEditor(null)}>
        <SheetContent className="w-full sm:max-w-xl">
          <SheetHeader>
            <SheetTitle>{t("notifications.shop.templates.create")}</SheetTitle>
          </SheetHeader>
          {editor ? (
            <div className="mt-4 space-y-3">
              <Input
                placeholder="stock.alert.low"
                value={editor.key}
                onChange={(e) => setEditor({ ...editor, key: e.target.value })}
              />
              <Textarea
                rows={10}
                className="font-mono text-xs"
                value={editor.contentJson}
                onChange={(e) => setEditor({ ...editor, contentJson: e.target.value })}
              />
              {saveError ? (
                <Alert variant="destructive">
                  <AlertCircle className="size-4" />
                  <AlertDescription>{saveError}</AlertDescription>
                </Alert>
              ) : null}
              <div className="flex justify-end gap-2">
                <Button variant="outline" onClick={() => setEditor(null)}>
                  {t("common.cancel")}
                </Button>
                <Button onClick={onSave} disabled={createMutation.isPending}>
                  {createMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
                  {t("common.save")}
                </Button>
              </div>
            </div>
          ) : null}
        </SheetContent>
      </Sheet>

      <Dialog open={confirmDelete !== null} onOpenChange={(o) => !o && setConfirmDelete(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("notifications.shop.templates.delete_title")}</DialogTitle>
          </DialogHeader>
          <p className="font-mono text-sm">{confirmDelete?.key}</p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmDelete(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              disabled={deleteMutation.isPending}
              onClick={async () => {
                if (confirmDelete) {
                  await deleteMutation.mutateAsync(confirmDelete.id);
                  setConfirmDelete(null);
                }
              }}
            >
              {t("common.delete")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
