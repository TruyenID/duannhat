"use client";

import {
  Alert,
  AlertDescription,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
} from "@godxjp/ui";
import { AlertTriangle } from "lucide-react";

import type { DisconnectImpact } from "@/services/payment-gateway-service";
import { useTranslation } from "@/providers/app-provider";

export interface DisconnectImpactDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  impact: DisconnectImpact | undefined;
  isLoading: boolean;
  isPending: boolean;
  onConfirm: () => void;
}

/** G5 — lists affected shops/devices/options; blocks unsafe disconnect. */
export function DisconnectImpactDialog({
  open,
  onOpenChange,
  impact,
  isLoading,
  isPending,
  onConfirm,
}: DisconnectImpactDialogProps) {
  const { t } = useTranslation();

  const canDisconnect = impact?.can_disconnect ?? false;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-slot="disconnect-impact-dialog" className="max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <AlertTriangle className="size-4 text-amber-500" aria-hidden />
            {t("hq.payments.disconnect.title")}
          </DialogTitle>
          <DialogDescription>{t("hq.payments.disconnect.description")}</DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5" />
          </div>
        ) : impact ? (
          <div className="space-y-4 text-sm">
            {!canDisconnect && impact.blocked_reason ? (
              <Alert variant="destructive">
                <AlertDescription>{impact.blocked_reason}</AlertDescription>
              </Alert>
            ) : null}

            {impact.affected_shops.length > 0 ? (
              <ImpactSection
                title={t("hq.payments.disconnect.shops", { n: impact.affected_shops.length })}
                items={impact.affected_shops.map((s) => s.name)}
              />
            ) : null}

            {impact.affected_devices.length > 0 ? (
              <ImpactSection
                title={t("hq.payments.disconnect.devices", { n: impact.affected_devices.length })}
                items={impact.affected_devices.map((d) => `${d.name} (${d.shop_name})`)}
              />
            ) : null}

            {impact.affected_options.length > 0 ? (
              <ImpactSection
                title={t("hq.payments.disconnect.options", { n: impact.affected_options.length })}
                items={impact.affected_options.map((o) => `${o.name} · ${o.rail}`)}
              />
            ) : null}

            {impact.affected_shops.length === 0 &&
            impact.affected_devices.length === 0 &&
            impact.affected_options.length === 0 ? (
              <p className="text-muted-foreground">{t("hq.payments.disconnect.no_impact")}</p>
            ) : null}
          </div>
        ) : null}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.cancel")}
          </Button>
          <Button
            variant="destructive"
            disabled={!canDisconnect || isPending || isLoading}
            onClick={onConfirm}
          >
            {isPending ? <Spinner className="mr-2 size-3.5" /> : null}
            {t("hq.payments.disconnect.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ImpactSection({ title, items }: { title: string; items: string[] }) {
  return (
    <div>
      <p className="mb-1.5 text-xs font-medium text-muted-foreground">{title}</p>
      <ul className="max-h-28 space-y-1 overflow-y-auto rounded-md border bg-muted/30 p-2 text-xs">
        {items.map((item) => (
          <li key={item}>{item}</li>
        ))}
      </ul>
    </div>
  );
}
