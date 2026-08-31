"use client";

import { Badge } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import {
  PaymentConnectionHealth,
  getPaymentConnectionHealthLabel,
} from "@/types/models/enum/PaymentConnectionHealth";

export interface ConnectionHealthBadgeProps {
  health: PaymentConnectionHealth | string;
}

const HEALTH_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  [PaymentConnectionHealth.Ready]: "default",
  [PaymentConnectionHealth.PendingVerification]: "secondary",
  [PaymentConnectionHealth.Degraded]: "outline",
  [PaymentConnectionHealth.Unavailable]: "destructive",
  [PaymentConnectionHealth.Restricted]: "outline",
  [PaymentConnectionHealth.Revoked]: "destructive",
};

export function ConnectionHealthBadge({ health }: ConnectionHealthBadgeProps) {
  const { locale } = useTranslation();
  const label = getPaymentConnectionHealthLabel(health as PaymentConnectionHealth, locale);
  const variant = HEALTH_VARIANT[health] ?? "secondary";

  return (
    <Badge variant={variant} data-slot="connection-health-badge" className="text-[10px] font-normal">
      {label}
    </Badge>
  );
}
