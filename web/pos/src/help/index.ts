/**
 * In-app operator guide — lookup.
 *
 * Resolution is locale → fallback locale → the Japanese catalogue, matching the
 * chain `AppProvider.t()` uses for the i18n JSON. All three catalogues are typed
 * `Record<HelpTopicId, HelpTopic>`, so the fallbacks can only ever fire for a
 * locale that does not exist at all — never for a missing topic.
 */

import { FALLBACK_LOCALE, type LocaleCode } from "@/i18n";
import { helpEn } from "./content/en";
import { helpJa } from "./content/ja";
import { helpVi } from "./content/vi";
import type { HelpCatalogue, HelpCatalogues, HelpTopic, HelpTopicId } from "./types";

export const HELP_CATALOGUES: HelpCatalogues = {
  ja: helpJa,
  en: helpEn,
  vi: helpVi,
};

export function getHelpCatalogue(locale: LocaleCode): HelpCatalogue {
  return HELP_CATALOGUES[locale] ?? HELP_CATALOGUES[FALLBACK_LOCALE];
}

export function getHelpTopic(id: HelpTopicId, locale: LocaleCode): HelpTopic {
  return getHelpCatalogue(locale)[id];
}

export { HELP_TOPIC_IDS } from "./types";
export type { HelpGlossaryEntry, HelpTopic, HelpTopicId } from "./types";
