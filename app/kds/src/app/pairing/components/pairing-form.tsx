import { useState } from "react";
import { pairDevice, PairError } from "@/services/auth/pairing";
import { setDeviceToken } from "@/services/auth/device-token";
import { useAuth } from "@/providers/use-auth";
import { useTranslation } from "@/i18n";
import { useAudioChime } from "@/hooks/use-audio-chime";

export default function PairingForm() {
  const { t } = useTranslation();
  const { setPaired } = useAuth();
  const { unlock } = useAudioChime();
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const res = await pairDevice({
        pairing_code: code.trim().toUpperCase(),
        expected_type: "kds", // #935 — mirror device.auth:kds
        device_info: {
          user_agent: navigator.userAgent,
          app_version: import.meta.env.VITE_APP_VERSION ?? "0.1.0",
        },
      });
      const info = {
        id: res.device.id,
        name: res.device.name,
        type: "kds" as const,
        branch_id: res.device.branch_id,
        branch_name: res.device.branch?.name ?? "",
        organization_id: res.device.organization_id,
        paired_at: res.device.paired_at,
      };
      setDeviceToken(res.device_token, info);
      setPaired(res.device_token, info);
    } catch (err) {
      if (err instanceof PairError) {
        if (err.message.startsWith("wrong_device_type:")) {
          setError(t("pairing.error_wrong_device_type"));
        } else if (err.status === 422) {
          // The expected_type gate returns a localized per-field message
          // (e.g. "this code belongs to a kiosk device") — show it verbatim;
          // fall back to the generic invalid-code copy.
          const fieldMsg = (err.body as { errors?: Record<string, string[]> })
            ?.errors?.pairing_code?.[0];
          setError(fieldMsg ?? t("pairing.error_code_invalid"));
        } else if (
          err.status === 410 ||
          ((err.body as { message?: string })?.message ?? "").includes("expired")
        ) {
          setError(t("pairing.error_code_expired"));
        } else {
          setError(err.message);
        }
      } else {
        setError((err as Error).message);
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" aria-label={t("pairing.title")}>
      <input
        autoFocus
        type="text"
        inputMode="text"
        autoCapitalize="characters"
        spellCheck={false}
        maxLength={6}
        value={code}
        onChange={(e) => setCode(e.target.value.toUpperCase())}
        placeholder={t("pairing.input_placeholder")}
        aria-label={t("pairing.input_placeholder")}
        className="w-full text-center text-4xl tracking-[0.5em] font-mono p-6 border-2 rounded-lg bg-card text-card-foreground uppercase"
      />
      {error && (
        <p role="alert" className="text-sm text-destructive text-center">
          {error}
        </p>
      )}
      <button
        type="submit"
        disabled={submitting || code.length !== 6}
        className="w-full bg-primary text-primary-foreground p-4 rounded-lg text-lg font-medium min-h-14 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {submitting ? t("pairing.submitting") : t("pairing.submit")}
      </button>
      <button
        type="button"
        onClick={() => void unlock()}
        className="w-full text-sm text-muted-foreground py-2 underline"
      >
        {t("pairing.test_sound")}
      </button>
    </form>
  );
}
