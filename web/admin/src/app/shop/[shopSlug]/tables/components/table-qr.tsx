"use client";

/**
 * TableQr — renders a QR code client-side from a table's `qr_token`.
 *
 * The backend does NOT render QR images; it only exposes the raw token.
 * The frontend encodes the full customer-facing dine-in URL
 * `${NEXT_PUBLIC_CUSTOMER_WEB_URL}/dine-in/{shopSlug}/table/{qrToken}` into
 * an SVG QR code using `qrcode.react` (~3 KB, MIT). Scanning with a phone
 * camera opens the customer-web dine-in page directly.
 *
 * Historical note: Plan-001 originally encoded the raw token only (the URL
 * shape was undecided at that time). Plan-011 introduced customer-web with
 * route `/dine-in/{shop}/table/{qrToken}`, so the QR now carries the full
 * URL. Existing tokens remain valid — only the QR payload changed.
 */

import { QRCodeSVG } from "qrcode.react";
import { useTranslation } from "@/providers/app-provider";
import { useRuntimeConfig } from "@/providers/runtime-config-provider";
import { buildDineInUrl } from "./dine-in-url";

export interface TableQrProps {
  /** The raw, opaque QR token stored on the Table record. */
  qrToken: string;
  /** The shop slug — used to build the customer-web dine-in URL. */
  shopSlug: string;
  /** Pixel size of the rendered square QR. Defaults to 64 (card preview). */
  size?: number;
  /** Optional className passed through to the wrapping element. */
  className?: string;
  /** Optional accessible label. */
  title?: string;
}

export function TableQr({ qrToken, shopSlug, size = 64, className, title }: TableQrProps) {
  const { t } = useTranslation();
  const { customerWebUrl } = useRuntimeConfig();
  if (!qrToken) {
    return (
      <div
        data-slot="table-qr"
        className={className}
        style={{ width: size, height: size }}
        aria-label={t("shop.tables.qr.no_token")}
      />
    );
  }

  return (
    <div
      data-slot="table-qr"
      className={className}
      title={title ?? t("shop.tables.qr.table_qr")}
      style={{ lineHeight: 0 }}
    >
      <QRCodeSVG
        value={buildDineInUrl(shopSlug, qrToken, customerWebUrl)}
        size={size}
        level="M"
        marginSize={1}
        // Keep colors tied to the theme-agnostic card so the QR stays legible
        // in both light and dark mode; a white quiet zone is required for
        // reliable scanning regardless of the surrounding surface.
        bgColor="#ffffff"
        fgColor="#111827"
      />
    </div>
  );
}
