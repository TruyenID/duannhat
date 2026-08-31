"use client";

/**
 * Reverb credentials rotation tab (plan-012 T4.7).
 *
 * Calls POST /hq/{brand}/settings/reverb/rotate, surfaces the new app_key
 * + app_secret once (with copy-to-clipboard). Dialog confirm because
 * rotation disconnects every live WebSocket on the brand.
 */

import {
  Alert,
  AlertDescription,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
} from "@godxjp/ui";
import { useMutation } from "@tanstack/react-query";
import { AlertTriangle, Copy, Key, RefreshCw, ShieldCheck } from "lucide-react";
import { useState } from "react";

import { apiFetch } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

interface RotateResponse {
  data: {
    app_id: string;
    app_key: string;
    app_secret: string;
    rotated_at: string | null;
  };
}

interface ReverbRotationTabProps {
  brandSlug: string;
}

export function ReverbRotationTab({ brandSlug }: ReverbRotationTabProps) {
  const { t } = useTranslation();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [revealed, setRevealed] = useState<RotateResponse["data"] | null>(null);
  const [copied, setCopied] = useState<string | null>(null);

  const rotate = useMutation({
    mutationFn: async () => {
      const res = await apiFetch<RotateResponse>(`/api/v1/hq/${brandSlug}/settings/reverb/rotate`, {
        method: "POST",
      });
      return res.data;
    },
    onSuccess: (data) => {
      setRevealed(data);
      setConfirmOpen(false);
    },
  });

  async function copyToClipboard(label: string, value: string) {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(label);
      setTimeout(() => setCopied(null), 1500);
    } catch {
      // Clipboard API unavailable (http://localhost without HTTPS in some browsers).
    }
  }

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex items-start gap-3">
          <div className="rounded-full bg-muted p-2">
            <ShieldCheck className="size-4" />
          </div>
          <div className="flex-1">
            <p className="text-sm font-medium">{t("hq.reverb.title")}</p>
            <p className="text-xs text-muted-foreground">{t("hq.reverb.description")}</p>
          </div>
        </div>

        <Button onClick={() => setConfirmOpen(true)} variant="outline" className="ml-10">
          <RefreshCw className="mr-2 size-3.5" />
          {t("hq.reverb.rotate")}
        </Button>

        {revealed ? (
          <Alert className="ml-10">
            <AlertDescription>
              <div className="mb-2 flex items-center gap-2 text-xs font-medium text-amber-600 dark:text-amber-400">
                <AlertTriangle className="size-3.5" />
                {t("hq.reverb.warning")}
              </div>
              <div className="space-y-2 font-mono text-xs">
                {[
                  { label: "app_id", value: revealed.app_id },
                  { label: "app_key", value: revealed.app_key },
                  { label: "app_secret", value: revealed.app_secret },
                ].map(({ label, value }) => (
                  <div
                    key={label}
                    className="flex items-center gap-2 rounded-md border bg-background p-2"
                  >
                    <Key className="size-3.5 text-muted-foreground" />
                    <span className="text-muted-foreground">{label}:</span>
                    <code className="flex-1 truncate">{value}</code>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-7"
                      onClick={() => copyToClipboard(label, value)}
                    >
                      <Copy className="size-3.5" />
                    </Button>
                    {copied === label ? (
                      <span className="text-[10px] text-primary">{t("common.copied")}</span>
                    ) : null}
                  </div>
                ))}
              </div>
            </AlertDescription>
          </Alert>
        ) : null}

        <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <AlertTriangle className="size-4 text-amber-500" />
                {t("hq.reverb.confirm_title")}
              </DialogTitle>
              <DialogDescription>{t("hq.reverb.confirm_description")}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setConfirmOpen(false)}>
                {t("common.cancel")}
              </Button>
              <Button
                variant="destructive"
                onClick={() => rotate.mutate()}
                disabled={rotate.isPending}
              >
                {rotate.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
                {t("hq.reverb.confirm_rotate")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </CardContent>
    </Card>
  );
}
