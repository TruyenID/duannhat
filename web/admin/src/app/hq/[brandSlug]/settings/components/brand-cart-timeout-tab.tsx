"use client";

import { useState, useEffect, useRef } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
  RadioGroup,
  RadioGroupItem,
  Spinner,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface BrandSettingsData {
  cart_timeout_minutes: number | null;
}

interface BrandSettingsResponse {
  data: BrandSettingsData;
}

interface BrandSettingsPatchBody {
  cart_timeout_minutes: number | null;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandSettingsKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandCartTimeoutTabProps {
  brandSlug: string;
}

const MIN_MINUTES = 1;
const MAX_MINUTES = 1440;

export function BrandCartTimeoutTab({ brandSlug }: BrandCartTimeoutTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: brandSettingsKeys.get(brandSlug),
    queryFn: () => apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  // "no_default" | "custom"
  const [mode, setMode] = useState<"no_default" | "custom">("no_default");
  // Raw string so user can type/clear freely; parsed on validation.
  const [minutesRaw, setMinutesRaw] = useState<string>("15");

  useEffect(() => {
    if (settings !== undefined) {
      const mins = settings.cart_timeout_minutes;
      if (mins !== null && mins >= 1) {
        setMode("custom");
        setMinutesRaw(String(mins));
      } else {
        setMode("no_default");
      }
    }
  }, [settings]);

  const parsedMinutes = (() => {
    const trimmed = minutesRaw.trim();
    if (trimmed === "") return null;
    const n = Number(trimmed);
    return Number.isInteger(n) ? n : null;
  })();

  const minutesError =
    mode === "custom" &&
    (parsedMinutes === null || parsedMinutes < MIN_MINUTES || parsedMinutes > MAX_MINUTES)
      ? t("hq.brand.settings.timeout.range_error")
      : undefined;

  const isDirty =
    settings !== undefined &&
    (() => {
      const current = settings.cart_timeout_minutes;
      if (mode === "no_default") {
        return current !== null;
      }

      return current !== parsedMinutes;
    })();

  const saveMutation = useMutation({
    mutationFn: (body: BrandSettingsPatchBody) =>
      apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: brandSettingsKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.timeout.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.timeout.toast_error")
      );
    },
  });

  // Unsaved-changes guard ----------------------------------------------------
  const justSavedRef = useRef(false);
  const [pendingHref, setPendingHref] = useState<string | null>(null);
  const rootRef = useRef<HTMLDivElement | null>(null);

  // Browser-level guard (full reload / close tab).
  useEffect(() => {
    if (!isDirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      if (justSavedRef.current) return;
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty]);

  // Intercept in-page link clicks (tab nav etc.) while dirty.
  useEffect(() => {
    if (!isDirty) return;
    const handler = (e: MouseEvent) => {
      if (justSavedRef.current) return;
      if (e.defaultPrevented) return;
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const target = e.target;
      if (!(target instanceof Element)) return;
      const anchor = target.closest("a");
      if (!(anchor instanceof HTMLAnchorElement)) return;
      // Same-tab links only.
      if (anchor.target && anchor.target !== "" && anchor.target !== "_self") return;
      const href = anchor.getAttribute("href");
      if (!href || href.startsWith("#")) return;
      // Ignore clicks inside our own subtree (Save / Continue buttons etc.).
      if (rootRef.current?.contains(anchor)) return;
      e.preventDefault();
      e.stopPropagation();
      setPendingHref(anchor.href);
    };
    document.addEventListener("click", handler, true);
    return () => document.removeEventListener("click", handler, true);
  }, [isDirty]);

  if (error) {
    throw error;
  }

  const handleSave = () => {
    if (minutesError) return;
    saveMutation.mutate({
      cart_timeout_minutes: mode === "custom" ? parsedMinutes : null,
    });
  };

  const handleDiscardAndLeave = () => {
    const href = pendingHref;
    setPendingHref(null);
    if (!href) return;
    justSavedRef.current = true;
    window.location.href = href;
  };

  return (
    <div ref={rootRef} data-slot="brand-cart-timeout-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.timeout.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.timeout.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Spinner className="size-4" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <RadioGroup
                value={mode}
                onValueChange={(val) => setMode(val as "no_default" | "custom")}
                className="space-y-3"
              >
                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="no_default"
                    id="brand-timeout-no-default"
                    className="mt-0.5 shrink-0"
                  />
                  <Label htmlFor="brand-timeout-no-default" className="cursor-pointer leading-snug">
                    {t("hq.brand.settings.timeout.no_default_radio")}
                  </Label>
                </div>

                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="custom"
                    id="brand-timeout-custom"
                    className="mt-0.5 shrink-0"
                  />
                  <Label htmlFor="brand-timeout-custom" className="cursor-pointer leading-snug">
                    {t("hq.brand.settings.timeout.custom_radio")}
                  </Label>
                </div>
              </RadioGroup>

              {mode === "custom" && (
                <div className="ml-6 space-y-1">
                  <Input
                    id="brand-timeout-minutes"
                    type="number"
                    min={MIN_MINUTES}
                    max={MAX_MINUTES}
                    step={1}
                    value={minutesRaw}
                    onChange={(e) => setMinutesRaw(e.target.value)}
                    error={minutesError}
                    className="w-24"
                  />
                  {!minutesError && (
                    <p className="text-xs text-muted-foreground">
                      {t("hq.brand.settings.timeout.minutes_hint")}
                    </p>
                  )}
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-end gap-3">
        {isDirty && (
          <span className="text-xs text-muted-foreground">
            {t("settings.order.unsaved_changes")}
          </span>
        )}
        <Button
          size="sm"
          className="h-8 gap-1.5 text-xs"
          disabled={saveMutation.isPending || isLoading || !isDirty || Boolean(minutesError)}
          onClick={handleSave}
        >
          {saveMutation.isPending && <Spinner className="size-3.5" />}
          {t("common.save_changes")}
        </Button>
      </div>

      <AlertDialog
        open={pendingHref !== null}
        onOpenChange={(open) => {
          if (!open) setPendingHref(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("common.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("common.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.unsaved.continue_editing")}</AlertDialogCancel>
            <AlertDialogAction onClick={handleDiscardAndLeave}>
              {t("common.unsaved.discard")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
