"use client";

/**
 * Plan-023 M6 T6.11 — shop audiences page (list + create + delete).
 *
 * Audience rules authored as JSON for v1 (mirrors the HQ rule editor
 * pattern). Visual rule builder defers to the shared HQ component
 * adoption pass.
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
import { AlertCircle, Plus, Trash2, Users } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import {
  useShopAudienceCreate,
  useShopAudienceDelete,
  useShopAudiences,
} from "@/hooks/api/use-shop-notifications";
import { useTranslation } from "@/providers/app-provider";
import type { AudienceRow } from "@/services/notification-audience-service";

const DEFAULT_RULE_JSON = JSON.stringify(
  { version: 1, combinator: "or", rules: [{ type: "role", role: "shop_manager" }] },
  null,
  2
);

export default function ShopAudiencesPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const [editor, setEditor] = useState<{
    name: string;
    description: string;
    ruleJson: string;
  } | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<AudienceRow | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useShopAudiences(shopSlug);
  const createMutation = useShopAudienceCreate(shopSlug);
  const deleteMutation = useShopAudienceDelete(shopSlug);

  const rows = data?.data ?? [];

  async function onSave() {
    if (!editor) return;
    setSaveError(null);
    let rule: unknown;
    try {
      rule = JSON.parse(editor.ruleJson);
    } catch (e) {
      setSaveError(`Invalid JSON: ${(e as Error).message}`);
      return;
    }
    try {
      await createMutation.mutateAsync({
        name: editor.name,
        description: editor.description || undefined,
        rule: rule as never,
      });
      setEditor(null);
    } catch (e) {
      setSaveError((e as Error).message);
    }
  }

  return (
    <>
      <PageHeader
        title={t("notifications.shop.audiences.title")}
        description={t("notifications.shop.audiences.subtitle")}
      >
        <Button
          size="sm"
          onClick={() => setEditor({ name: "", description: "", ruleJson: DEFAULT_RULE_JSON })}
        >
          <Plus className="mr-1 size-3.5" />
          {t("notifications.shop.audiences.create")}
        </Button>
        <HelpPanel
          title={t("notifications.shop.audiences.title")}
          subtitle={t("help.panel.shop_notifications_audiences.subtitle")}
          purpose={t("help.panel.shop_notifications_audiences.purpose")}
          usage={[
            t("help.panel.shop_notifications_audiences.usage.1"),
            t("help.panel.shop_notifications_audiences.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_notifications_audiences.checks.1"),
            t("help.panel.shop_notifications_audiences.checks.2"),
            t("help.panel.shop_notifications_audiences.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_notifications_audiences.glossary.combinator.term"),
              description: t("help.panel.shop_notifications_audiences.glossary.combinator.desc"),
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
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <Card>
            <CardContent className="p-8 text-center text-sm text-muted-foreground">
              <Users className="mx-auto mb-3 size-8 text-muted-foreground/60" />
              {t("notifications.shop.audiences.empty")}
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-2">
            {rows.map((row) => (
              <Card key={row.id}>
                <CardContent className="flex items-center gap-3 p-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{row.name}</p>
                    {row.description ? (
                      <p className="truncate text-xs text-muted-foreground">{row.description}</p>
                    ) : null}
                  </div>
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
            <SheetTitle>{t("notifications.shop.audiences.create")}</SheetTitle>
          </SheetHeader>
          {editor ? (
            <div className="mt-4 space-y-3">
              <Input
                placeholder={t("notifications.shop.audiences.name_placeholder")}
                value={editor.name}
                onChange={(e) => setEditor({ ...editor, name: e.target.value })}
              />
              <Textarea
                rows={2}
                placeholder={t("notifications.shop.audiences.description_placeholder")}
                value={editor.description}
                onChange={(e) => setEditor({ ...editor, description: e.target.value })}
                maxLength={1000}
                className="field-sizing-fixed"
              />
              <Textarea
                rows={8}
                className="font-mono text-xs"
                value={editor.ruleJson}
                onChange={(e) => setEditor({ ...editor, ruleJson: e.target.value })}
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
            <DialogTitle>{t("notifications.shop.audiences.delete_title")}</DialogTitle>
          </DialogHeader>
          <p className="text-sm">{confirmDelete?.name}</p>
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
              {deleteMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("common.delete")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
