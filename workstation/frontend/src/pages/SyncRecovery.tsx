import { AlertDialog } from "@godxjp/ui";
import { Badge, Alert } from "@godxjp/ui/admin";
import { Card, CardContent, CardHeader, CardTitle } from "@godxjp/ui/data-display";
import { Button } from "@godxjp/ui/general";
import { useCallback, useEffect, useState } from "react";
import { CheckCircle, AlertTriangle, Banknote } from "lucide-react";
import { getSyncInfo, discardSync, reResolveSync, recoverOrderSync } from "../lib/api";
import type { DeadLetterItem } from "../lib/api";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

export function SyncRecovery() {
  const { t } = useTranslation();
  const [rows, setRows] = useState<DeadLetterItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      const si = await getSyncInfo();
      setRows(si.dead_letters ?? []);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const act = useCallback(
    async (id: number, fn: () => Promise<unknown>) => {
      setBusyId(id);
      setError(null);
      try {
        await fn();
        await load();
      } catch (e) {
        setError(e instanceof Error ? e.message : String(e));
      } finally {
        setBusyId(null);
      }
    },
    [load],
  );

  return (
    <>
      <PageHeader title={t("sync_recovery.title")} description={t("sync_recovery.subtitle")} />
      <PageContent>
        {error && (
          <Alert color="destructive" className="mb-4 flex items-center gap-2">
            <AlertTriangle size={16} />
            <span className="text-sm">{error}</span>
          </Alert>
        )}

        {!loading && rows.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
              <CheckCircle size={32} className="text-success" />
              <p className="text-sm text-muted-foreground">{t("sync_recovery.empty")}</p>
            </CardContent>
          </Card>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>{t("sync_recovery.stuck_ops")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {rows.map((row) => {
                const isOrderCreate = row.entity_type === "order" && row.operation === "create";
                const busy = busyId === row.id;
                return (
                  <div
                    key={row.id}
                    className={`flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between ${
                      row.is_payment ? "border-destructive/40 bg-destructive/5" : "bg-muted/40"
                    }`}
                  >
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        {row.is_payment && (
                          <Badge color="destructive" className="gap-1">
                            <Banknote size={11} />
                            {t("sync_recovery.payment")}
                          </Badge>
                        )}
                        <span className="text-sm font-medium">
                          {row.entity_type}.{row.operation}
                        </span>
                        <Badge color="warning">{row.dead_letter_reason}</Badge>
                      </div>
                      <p className="mt-1 truncate text-xs text-muted-foreground" title={row.last_error}>
                        {row.entity_id} — {row.last_error}
                      </p>
                    </div>
                    <div className="flex shrink-0 gap-2">
                      {isOrderCreate && (
                        <ConfirmButton
                          label={t("sync_recovery.recreate")}
                          title={t("sync_recovery.recreate_confirm_title")}
                          description={t("sync_recovery.recreate_confirm_desc")}
                          color="primary"
                          disabled={busy}
                          onConfirm={() => act(row.id, () => recoverOrderSync(row.entity_id))}
                        />
                      )}
                      <ConfirmButton
                        label={t("sync_recovery.re_resolve")}
                        title={t("sync_recovery.re_resolve_confirm_title")}
                        description={t("sync_recovery.re_resolve_confirm_desc")}
                        color="warning"
                        disabled={busy}
                        onConfirm={() => act(row.id, () => reResolveSync(row.id))}
                      />
                      <ConfirmButton
                        label={t("sync_recovery.discard")}
                        title={t("sync_recovery.discard_confirm_title")}
                        description={t("sync_recovery.discard_confirm_desc")}
                        color="destructive"
                        disabled={busy}
                        onConfirm={() => act(row.id, () => discardSync(row.id))}
                      />
                    </div>
                  </div>
                );
              })}
            </CardContent>
          </Card>
        )}
      </PageContent>
    </>
  );
}

function ConfirmButton({
  label,
  title,
  description,
  color,
  disabled,
  onConfirm,
}: {
  label: string;
  title: string;
  description: string;
  color: "primary" | "warning" | "destructive";
  disabled?: boolean;
  onConfirm: () => void;
}) {
  const { t } = useTranslation();
  // @godxjp/ui 18.x AlertDialog is controlled + self-contained (no composed
  // Trigger/Content children) — the caller owns the open state and the trigger.
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button size="sm" color={color} disabled={disabled} onClick={() => setOpen(true)}>
        {label}
      </Button>
      <AlertDialog
        open={open}
        onOpenChange={setOpen}
        title={title}
        description={description}
        confirmLabel={label}
        cancelLabel={t("common.cancel")}
        onConfirm={() => {
          setOpen(false);
          onConfirm();
        }}
      />
    </>
  );
}
