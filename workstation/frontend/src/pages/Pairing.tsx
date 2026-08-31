import { Card, CardContent, CardHeader, CardTitle } from "@godxjp/ui/data-display";
import { Input } from "@godxjp/ui/data-entry";
import { Button } from "@godxjp/ui/general";
import { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuTrigger } from "@godxjp/ui/navigation";
import { useState } from "react";
import { Smartphone, CheckCircle2, AlertCircle, Languages } from "lucide-react";
import { pairDevice } from "../lib/api";
import { useLocale, useTranslation } from "../providers/app-provider";
import type { LocaleCode } from "../i18n";

interface PairingProps {
  onPaired: () => void;
  needsRepair?: boolean;
}

export function Pairing({ onPaired, needsRepair }: PairingProps) {
  const { t } = useTranslation();
  const { locale, setLocale, locales } = useLocale();
  const [code, setCode] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);
  const [deviceName, setDeviceName] = useState("");

  async function handlePair() {
    if (code.length === 0) return;
    setLoading(true);
    setError("");
    try {
      const result = await pairDevice(code.toUpperCase());
      setDeviceName(result.device?.name ?? "");
      setSuccess(true);
      setTimeout(() => {
        onPaired();
      }, 1500);
    } catch (err: any) {
      // The workstation rejects a non-workstation pairing code with
      // {error:"device_type_mismatch", got:"<type>"} (HTTP 422). Map that to a
      // localized, human message instead of leaking the raw error code.
      if (err?.data?.error === "device_type_mismatch") {
        setError(
          t("device.pair_error_type_mismatch", {
            type: String(err.data.got ?? "").toUpperCase(),
          }),
        );
      } else if (err?.status === 403) {
        // #3235 — /api/device/pair is loopback-only, but the app itself
        // advertises its LAN URL (endpoint.json, mDNS), so opening the UI
        // there makes everything work EXCEPT this button, which used to show
        // a bare "Forbidden". Say what to actually do.
        setError(
          t("device.pair_error_loopback_only", {
            url: `http://localhost:${window.location.port || "6969"}`,
          }),
        );
      } else {
        setError(err?.message || t("device.pair_error_generic"));
      }
    } finally {
      setLoading(false);
    }
  }

  function handleCodeChange(e: React.ChangeEvent<HTMLInputElement>) {
    const val = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
    setCode(val);
    setError("");
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter" && code.length > 0 && !loading) {
      handlePair();
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4 relative">
      {/* Language switcher — top right */}
      <div className="absolute top-4 right-4">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="size-8 p-0">
              <Languages className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuGroup>
              {(Object.keys(locales) as LocaleCode[]).map((loc) => (
                <DropdownMenuItem
                  key={loc}
                  onClick={() => setLocale(loc)}
                  className="h-7 text-sm"
                >
                  <span className={locale === loc ? "font-medium" : ""}>
                    {locales[loc]}
                  </span>
                  {locale === loc && (
                    <span className="ml-auto text-xs text-muted-foreground">
                      {loc.toUpperCase()}
                    </span>
                  )}
                </DropdownMenuItem>
              ))}
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <Card className="w-full max-w-md">
        <CardHeader className="text-center space-y-2">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
            <Smartphone className="h-6 w-6 text-primary" />
          </div>
          <CardTitle className="text-xl">{t("device.pairing_title")}</CardTitle>
          <p className="text-sm text-muted-foreground">
            {t("device.pairing_help")}
          </p>
        </CardHeader>
        <CardContent className="space-y-4">
          {needsRepair && !success && (
            <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{t("device.repair_help")}</span>
            </div>
          )}
          {success ? (
            <div className="flex flex-col items-center gap-3 py-4">
              <CheckCircle2 className="h-12 w-12 text-green-500" />
              <p className="text-lg font-medium text-green-600">
                {t("device.paired_success")}
              </p>
              {deviceName && (
                <p className="text-sm text-muted-foreground">{deviceName}</p>
              )}
            </div>
          ) : (
            <>
              <div className="space-y-2">
                <label className="text-sm font-medium">
                  {t("device.pairing_code")}
                </label>
                <Input
                  value={code}
                  onChange={handleCodeChange}
                  onKeyDown={handleKeyDown}
                  placeholder="ABC123"
                  className="text-center text-2xl tracking-[0.3em] font-mono uppercase"
                  maxLength={6}
                  autoFocus
                  disabled={loading}
                />
              </div>

              {error && (
                <div className="flex items-center gap-2 text-sm text-destructive">
                  <AlertCircle className="h-4 w-4 shrink-0" />
                  <span>{error}</span>
                </div>
              )}

              <Button
                color="primary"
                className="w-full"
                onClick={handlePair}
                disabled={code.length === 0 || loading}
              >
                {loading ? t("common.loading") : t("device.pair_button")}
              </Button>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
