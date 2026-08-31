"use client";

import { useState } from "react";
import { Check, Copy } from "lucide-react";
import { Button, Card, CardContent, CardHeader, CardTitle, Input } from "@godxjp/ui";

import { useTranslation } from "@/providers/app-provider";

export interface WebhookEndpointCardProps {
  /** Server-built registration URL (`webhook_url` on the connection detail). */
  webhookUrl: string | null | undefined;
}

/**
 * Plan-048 T3.6 — read-only webhook registration URL with a copy button.
 * The URL is built by the backend (it alone knows the public API host);
 * this card never composes URLs client-side.
 */
export function WebhookEndpointCard({ webhookUrl }: WebhookEndpointCardProps) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);

  if (!webhookUrl) return null;

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(webhookUrl as string);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard unavailable (permissions / non-secure context) — the URL
      // stays selectable in the read-only input, so no further fallback.
    }
  }

  return (
    <Card data-slot="webhook-endpoint-card">
      <CardHeader className="pb-2">
        <CardTitle className="text-base">{t("hq.payments.webhook.title")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        <p className="text-sm text-muted-foreground">{t("hq.payments.webhook.description")}</p>
        <div className="flex gap-2">
          <Input
            readOnly
            value={webhookUrl}
            onFocus={(e) => e.currentTarget.select()}
            aria-label={t("hq.payments.webhook.title")}
            className="font-mono text-xs"
          />
          <Button
            variant="outline"
            size="sm"
            className="h-element shrink-0 gap-1.5 text-xs"
            onClick={handleCopy}
          >
            {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
            {copied ? t("hq.payments.webhook.copied") : t("hq.payments.webhook.copy")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
