import { useEffect, useState } from "react";
import { Button } from "@godxjp/ui";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import { useAuth } from "@/providers/use-auth";
import { getApiErrorDetail } from "@/lib/api-error";
import { PairError } from "@/services/auth/pairing";
import { pairDevice } from "@/services/auth/pairing";
import {
  getWorkstationUrl,
  isServedByWorkstation,
} from "@/services/workstation/base-url-resolver";

/**
 * The one-sentence, translated reason. Keyed off HTTP status only — never off
 * message text, which is server-language and server-worded.
 */
function localizedPairHeadline(
  err: unknown,
  t: (key: string) => string,
): string | null {
  if (!(err instanceof PairError)) return null;
  if (err.status === 422) return t("pairing.error_code_invalid");
  if (err.status === 410) return t("pairing.error_code_expired");
  return null;
}

export function PairingPage() {
  const { t } = useTranslation();
  const { setPaired } = useAuth();
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [wsUnpaired, setWsUnpaired] = useState(false);

  useEffect(() => {
    if (!isServedByWorkstation()) return;
    const base = getWorkstationUrl();
    if (!base) return;
    let cancelled = false;
    // eslint-disable-next-line no-restricted-globals -- pre-auth health probe; no device token yet
    fetch(`${base}/api/lan/health`, { headers: { Accept: "application/json" } })
      .then(async (res) => {
        if (!res.ok) return;
        const body = (await res.json()) as { branch_id?: string };
        if (!cancelled) setWsUnpaired(!body.branch_id);
      })
      .catch(() => {
        /* health probe is advisory only */
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const res = await pairDevice({
        pairing_code: code.trim().toUpperCase(),
        device_info: {
          user_agent: navigator.userAgent,
          // Baked in by vite.config's define. The old "0.1.0" fallback is
          // exactly what every device row carried forever — a literal is not
          // a version, an empty string is at least honest.
          app_version: import.meta.env.VITE_APP_VERSION ?? "",
        },
      });
      setPaired(res.device_token, {
        id: res.device.id,
        name: res.device.name,
        type: res.device.type,
        branch_id: res.device.branch_id,
        branch_name: res.device.branch?.name ?? "",
        branch_slug: res.device.branch?.slug ?? "",
        organization_id: res.device.organization_id,
        paired_at: res.device.paired_at,
      });
    } catch (err) {
      // Two audiences, one line. The cashier needs a sentence in THEIR
      // language telling them the code is wrong or stale — that is what
      // pairing.error_code_{invalid,expired} say, and dropping them for the
      // raw Cloud string left a Japanese shop reading English. The installer
      // needs the exact Cloud reason (device-type mismatch, HTTP status,
      // field errors) — generic i18n alone hid that. So: localized headline,
      // then the diagnostic detail underneath.
      const detail = getApiErrorDetail(err, t("pairing.error_generic"));
      const headline = localizedPairHeadline(err, t);
      setError(headline && headline !== detail ? `${headline}\n${detail}` : detail);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/40 px-4">
      <div className="relative w-full max-w-sm rounded-2xl border bg-card p-8 shadow-xl shadow-black/4">
        {/* Top-right of the card: this screen has no PosHeader to host it, and
            it is the one screen an operator reaches before anything is set up. */}
        <div className="absolute right-3 top-3">
          <HelpButton topic="pairing" />
        </div>
        <div className="flex flex-col items-center text-center mb-6">
          <div className="mb-5 flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground text-base font-bold shadow-sm">
            POS
          </div>
          <h1 className="text-xl font-semibold tracking-tight text-foreground">
            {t("pairing.title")}
          </h1>
          <p className="mt-1.5 text-xs text-muted-foreground">
            {t("pairing.subtitle")}
          </p>
        </div>

        {wsUnpaired && (
          <div
            role="status"
            className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-left text-xs text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
          >
            <p className="font-semibold">{t("pairing.ws_unpaired_title")}</p>
            <p className="mt-1 opacity-90">{t("pairing.ws_unpaired_desc")}</p>
          </div>
        )}

        <form onSubmit={onSubmit} className="space-y-4">
          <input
            autoFocus
            type="text"
            inputMode="text"
            autoCapitalize="characters"
            spellCheck={false}
            maxLength={6}
            value={code}
            onChange={(e) => {
              setError(null);
              setCode(e.target.value.replace(/[^A-Za-z0-9]/g, "").toUpperCase());
            }}
            placeholder={t("pairing.input_placeholder")}
            aria-label={t("pairing.input_placeholder")}
            className="w-full text-center text-4xl tracking-[0.5em] font-mono p-6 border-2 rounded-lg bg-background text-foreground uppercase focus:outline-none focus:border-primary"
          />
          {error && (
            <p
              role="alert"
              className="whitespace-pre-wrap break-words text-left text-sm text-destructive"
            >
              {error}
            </p>
          )}
          <Button
            type="submit"
            disabled={submitting || code.length !== 6}
            className="h-11 w-full text-sm font-medium"
          >
            {submitting ? t("pairing.submitting") : t("pairing.submit")}
          </Button>
        </form>

        <p className="mt-4 text-center text-[11px] leading-relaxed text-muted-foreground">
          {t("pairing.hint")}
        </p>
      </div>
    </div>
  );
}
