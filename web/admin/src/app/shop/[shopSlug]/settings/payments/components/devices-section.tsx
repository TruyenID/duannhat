"use client";

import Link from "next/link";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@godxjp/ui";
import { ChevronRight } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import type { Device } from "@/types/models/Device";
import { getDeviceTypeLabel } from "@/types/models/enum/DeviceType";
import { getDeviceStatusLabel } from "@/types/models/enum/DeviceStatus";

export interface DevicesSectionProps {
  shopSlug: string;
  devices: Device[];
}

/**
 * Registered devices of this shop (plan-047 T5.4). Picking one opens its
 * per-device payment-option overrides at
 * /shop/{slug}/settings/payments/devices/{deviceId}.
 */
export function DevicesSection({ shopSlug, devices }: DevicesSectionProps) {
  const { t, locale } = useTranslation();

  if (devices.length === 0) {
    return (
      <Card data-slot="payments-devices-section">
        <CardHeader>
          <CardTitle>{t("shop.payments.devices.title")}</CardTitle>
          <CardDescription>{t("shop.payments.devices.empty_desc")}</CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild variant="outline" size="sm">
            <Link href={`/shop/${shopSlug}/devices`}>{t("shop.payments.devices.manage_devices")}</Link>
          </Button>
        </CardContent>
      </Card>
    );
  }

  return (
    <div data-slot="payments-devices-section" className="space-y-4">
      <div>
        <h2 className="text-base font-semibold">{t("shop.payments.devices.title")}</h2>
        <p className="text-sm text-muted-foreground">{t("shop.payments.devices.description")}</p>
      </div>

      <ul className="divide-y rounded-lg border" role="list">
        {devices.map((device) => (
          <li key={device.id}>
            <Link
              href={`/shop/${shopSlug}/settings/payments/devices/${device.id}`}
              className="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-muted/40 focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
            >
              <div className="min-w-0">
                <p className="truncate font-medium">{device.name}</p>
                <p className="text-xs text-muted-foreground">
                  {getDeviceTypeLabel(device.type, locale)}
                </p>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                <Badge variant="outline" className="text-[10px]">
                  {getDeviceStatusLabel(device.status, locale)}
                </Badge>
                <ChevronRight className="size-4 text-muted-foreground" aria-hidden />
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
