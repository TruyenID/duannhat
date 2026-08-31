import { SUPPORTED_LOCALES } from "@/types/models/payload-helpers";
import type {
  ProductOption,
  ProductOptionTranslations,
  ProductOptionValue,
  ProductOptionValueTranslations,
} from "@/services/product-service";

function pickFirstNonEmpty(
  translations: ProductOptionTranslations | ProductOptionValueTranslations | undefined,
  field: "name" | "label"
): string | null {
  if (!translations) return null;
  for (const locale of SUPPORTED_LOCALES) {
    const entry = (translations as Record<string, Record<string, string | null | undefined>>)[
      locale
    ];
    const v = entry?.[field];
    if (typeof v === "string" && v.trim()) return v;
  }
  return null;
}

export function resolveOptionName(
  option: Pick<ProductOption, "name" | "key" | "translations">
): string {
  if (option.name && option.name.trim()) return option.name;
  return pickFirstNonEmpty(option.translations, "name") ?? option.key;
}

export function resolveOptionValueLabel(
  value: Pick<ProductOptionValue, "label" | "value" | "translations">
): string {
  if (value.label && value.label.trim()) return value.label;
  return pickFirstNonEmpty(value.translations, "label") ?? value.value;
}
