"use client";

import { useState } from "react";
import { Check, Copy } from "lucide-react";
import { toast } from "sonner";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { Device } from "@/types/models/Device";

/**
 * Pairing-code dialog shared by the HQ and Shop device pages (#433). Shows the
 * 6-char pairing code plus its expiry, with a copy-to-clipboard button so staff
 * no longer have to hand-type the code into a device. Works for every device
 * type (kiosk / POS / workstation / TMS / KDS) since they all reuse this dialog.
 */
export function PairingCodeDialog({
  device,
  onClose,
}: {
  device: Device | null;
  onClose: () => void;
}) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const [copied, setCopied] = useState(false);

  if (!device) return null;

  const code = device.pairing_code ?? "";

  const handleCopy = async () => {
    if (!code) return;
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      toast.success(t("shop.devices.pairing_code_copied"));
      setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard API can be unavailable (insecure context) — fail silently;
      // admin-web is served over HTTPS so this path is not expected in practice.
    }
  };

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="text-center sm:max-w-xs">
        <DialogHeader>
          <DialogTitle>{t("shop.devices.pairing_code_title")}</DialogTitle>
          <DialogDescription>{device.name}</DialogDescription>
        </DialogHeader>
        <div className="py-6">
          <div className="flex items-center justify-center gap-2">
            <p className="font-mono text-4xl font-bold tracking-[0.3em]">{code}</p>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={handleCopy}
              disabled={!code}
              aria-label={t("shop.devices.copy_pairing_code")}
              title={t("shop.devices.copy_pairing_code")}
            >
              {copied ? (
                <Check className="size-4 text-success" />
              ) : (
                <Copy className="size-4" />
              )}
            </Button>
          </div>
          {device.pairing_expires_at && (
            <p className="mt-2 text-xs text-muted-foreground">
              {t("shop.devices.expires")}{" "}
              {formatDateTime(device.pairing_expires_at, locale, timezone)}
            </p>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
