"use client";

import { useTranslations } from "next-intl";

/**
 * #1185 — marks a section header as a khung giờ ưu đãi (floating section)
 * rather than an ordinary menu section.
 *
 * Extracted because the dine-in view renders its own section headers instead
 * of going through MenuGrid, so the chip existed twice, byte for byte: same
 * classes, same colour, same i18n key. One of the two would eventually drift.
 */
export function PromoSectionChip() {
  const t = useTranslations("menu");

  return (
    <span
      className="rounded px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white"
      style={{ backgroundColor: "#7C3AED" }}
    >
      {t("floatingSectionBadge")}
    </span>
  );
}
