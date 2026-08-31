import { useCallback } from "react";
import { toast } from "sonner";
import { useTranslation } from "@/i18n";
import { ApiError } from "@/lib/api";

/**
 * UX intent derived from a thrown error. `silent` means we intentionally do
 * not surface the failure (KDS_E005 throttle = anti-double-tap noise; KDS_E008
 * idempotency replay = a 200 success, not an error path).
 */
export type ToastVariant = "error" | "warning" | "info" | "silent";

export interface ToastIntent {
  variant: ToastVariant;
  title: string;
  description?: string;
}

interface CodeMapping {
  i18nKey: string;
  variant: ToastVariant;
}

/**
 * RFC 7807 code → toast variant + i18n key. Mirrors DESIGN.md §5 mapping.
 * Cloud emits these codes; workstation does not (LAN envelope is `{message}`
 * only, falls through to the generic-error branch in intentForError).
 */
const CODE_MAP: Record<string, CodeMapping> = {
  KDS_E001: { i18nKey: "errors.kds.order_finalized", variant: "error" },
  KDS_E002: { i18nKey: "errors.kds.invalid_transition", variant: "error" },
  KDS_E003: { i18nKey: "errors.kds.anti_misclick", variant: "warning" },
  KDS_E004: { i18nKey: "errors.kds.toppings_not_ready", variant: "info" },
  KDS_E005: { i18nKey: "", variant: "silent" },
  KDS_E006: { i18nKey: "errors.kds.branch_forbidden", variant: "error" },
  KDS_E007: { i18nKey: "errors.kds.item_not_found", variant: "error" },
  KDS_E008: { i18nKey: "", variant: "silent" },
};

/**
 * Pure mapper from a thrown error to a toast intent. Exposed for unit tests
 * so we can lock the mapping without touching the sonner side-effect.
 */
export function intentForError(
  err: unknown,
  t: (key: string) => string,
): ToastIntent {
  if (!(err instanceof ApiError)) {
    const msg = err instanceof Error ? err.message : t("errors.kds.unknown");
    return { variant: "error", title: msg };
  }

  // Cloud envelope: RFC 7807 with a recognized code.
  if (err.code && CODE_MAP[err.code]) {
    const m = CODE_MAP[err.code];
    if (m.variant === "silent") {
      return { variant: "silent", title: "" };
    }
    return {
      variant: m.variant,
      title: t(m.i18nKey),
      description: err.detail ?? undefined,
    };
  }

  // Workstation `{message}` envelope, or an unrecognized cloud code. Fall back
  // to a generic error toast showing whatever text the server provided.
  return {
    variant: "error",
    title: err.detail ?? err.message ?? t("errors.kds.unknown"),
  };
}

/**
 * Hook returning a `notify` callback that components / mutation hooks call
 * from their onError handler. Wraps useTranslation so the consumer doesn't
 * need to thread `t` through every callsite.
 */
export function useErrorToast() {
  const { t } = useTranslation();
  return useCallback(
    (err: unknown) => {
      const intent = intentForError(err, t);
      switch (intent.variant) {
        case "silent":
          return;
        case "error":
          toast.error(intent.title, { description: intent.description });
          return;
        case "warning":
          toast.warning(intent.title, { description: intent.description });
          return;
        case "info":
          toast.info(intent.title, { description: intent.description });
          return;
      }
    },
    [t],
  );
}
