import { useEffect, useState } from "react";
import {
  Card, CardContent, CardHeader, CardTitle, Button, Input,
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from "@godxjp/ui";
import { Save, Unlink, Loader2, ChefHat, Printer, AlertTriangle, ShieldCheck } from "lucide-react";
import {
  getConfig, getSetting, getVersion, setSetting, getDeviceStatus, unpairDevice,
  ApiError, type DeviceStatus, type UnpairBlocked,
} from "../lib/api";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

// JPY is minor-unit == major-unit, so no /100 — matches the app-wide
// Intl.NumberFormat("ja-JP") pattern (Reports/Dashboard/Orders).
const formatYen = (amount: number) => "¥" + new Intl.NumberFormat("ja-JP").format(amount || 0);

export function Settings() {
  const { t } = useTranslation();
  const [storeName, setStoreName] = useState("");
  const [storeAddress, setStoreAddress] = useState("");
  const [serverPort, setServerPort] = useState(8080);
  const [version, setVersion] = useState("dev");
  const [saved, setSaved] = useState(false);
  const [device, setDevice] = useState<DeviceStatus | null>(null);
  const [unpairing, setUnpairing] = useState(false);
  const [unpairError, setUnpairError] = useState<string | null>(null);
  const [unpairOpen, setUnpairOpen] = useState(false);
  // When the server blocks the unpair (409), this holds the unsynced counts +
  // amount so the dialog can show the operator exactly what's at risk before it
  // reveals the force path. null → the safe/first-confirm view. plan-818.
  const [unpairBlocked, setUnpairBlocked] = useState<UnpairBlocked | null>(null);
  const [unpairAck, setUnpairAck] = useState(false);
  const [unpairDone, setUnpairDone] = useState<{ kept: boolean } | null>(null);

  // Plan-038 T9.5 — kds_show_only_fired toggle. The operator opts in
  // once their staff is trained; flipping mid-shift would hide existing
  // KDS items without warning, so the toggle prompts a confirmation.
  const [kdsShowOnlyFired, setKdsShowOnlyFired] = useState(false);
  const [kdsToggleSaving, setKdsToggleSaving] = useState(false);

  // Auto-print bill toggle. OFF by default: a bill/receipt prints only when a
  // user presses a print button (POS "Print Receipt", pos-web print). When ON,
  // it also prints automatically on payment and on paid-order sync-down.
  const [autoPrintBill, setAutoPrintBill] = useState(false);
  const [autoPrintSaving, setAutoPrintSaving] = useState(false);

  // Auto-print kitchen toggle. OFF by default: dine-in / spot orders that arrive
  // from customer-web (QR-table) auto-fire their kitchen + hold slip on arrival
  // and on later "add more" rounds. POS-created orders keep manual fire.
  const [autoPrintKitchen, setAutoPrintKitchen] = useState(false);
  const [autoPrintKitchenSaving, setAutoPrintKitchenSaving] = useState(false);

  // Local print-language override (settings.print_locale_override). Empty =
  // follow the Cloud-synced shop language. Cloud always wins when it has a
  // value; this is the offline escape hatch. cloudPrintLocale shows what Cloud
  // sent so the operator can see whether their local pick is actually in effect.
  const [printLocale, setPrintLocale] = useState("");
  const [cloudPrintLocale, setCloudPrintLocale] = useState("");
  const [printLocaleSaving, setPrintLocaleSaving] = useState(false);

  useEffect(() => { loadSettings(); }, []);

  async function loadSettings() {
    try {
      const config = await getConfig();
      if (config) {
        setStoreName(config.store_name || "");
        setStoreAddress(config.store_address || "");
        setServerPort(config.server_port || 8080);
      }
      const v = await getVersion();
      if (v) setVersion(v);
      const d = await getDeviceStatus();
      setDevice(d);
      try {
        const v = await getSetting("kds_show_only_fired");
        setKdsShowOnlyFired(v === "true");
      } catch {}
      try {
        const v = await getSetting("auto_print_bill");
        setAutoPrintBill(v === "true");
      } catch {}
      try {
        const v = await getSetting("auto_print_kitchen");
        setAutoPrintKitchen(v === "true");
      } catch {}
      try {
        const v = await getSetting("print_locale_override");
        setPrintLocale(v ?? "");
      } catch {}
      try {
        const v = await getSetting("print_label_locale");
        setCloudPrintLocale(v ?? "");
      } catch {}
    } catch {}
  }

  async function handleSetPrintLocale(next: string) {
    setPrintLocaleSaving(true);
    try {
      await setSetting("print_locale_override", next);
      setPrintLocale(next);
    } catch (err) {
      console.error("print locale save failed:", err);
    } finally {
      setPrintLocaleSaving(false);
    }
  }

  async function handleToggleAutoPrintBill() {
    const next = !autoPrintBill;
    setAutoPrintSaving(true);
    try {
      await setSetting("auto_print_bill", next ? "true" : "false");
      setAutoPrintBill(next);
    } catch (err) {
      console.error("auto-print toggle failed:", err);
    } finally {
      setAutoPrintSaving(false);
    }
  }

  async function handleToggleAutoPrintKitchen() {
    const next = !autoPrintKitchen;
    setAutoPrintKitchenSaving(true);
    try {
      await setSetting("auto_print_kitchen", next ? "true" : "false");
      setAutoPrintKitchen(next);
    } catch (err) {
      console.error("auto-print kitchen toggle failed:", err);
    } finally {
      setAutoPrintKitchenSaving(false);
    }
  }

  async function handleToggleKdsShowOnlyFired() {
    const next = !kdsShowOnlyFired;
    const confirmMsg = next
      ? "Bật chế độ này sẽ ẨN tạm thời các món chưa fire trên KDS. Tiếp tục?"
      : "Tắt chế độ này sẽ hiện lại MỌI món của các đơn đang mở. Tiếp tục?";
    if (!window.confirm(confirmMsg)) return;
    setKdsToggleSaving(true);
    try {
      await setSetting("kds_show_only_fired", next ? "true" : "false");
      setKdsShowOnlyFired(next);
    } catch (err) {
      console.error("kds toggle failed:", err);
    } finally {
      setKdsToggleSaving(false);
    }
  }

  async function save() {
    try {
      await setSetting("store_name", storeName);
      await setSetting("store_address", storeAddress);
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } catch (err) { console.error("save failed:", err); }
  }

  function openUnpair() {
    setUnpairBlocked(null);
    setUnpairAck(false);
    setUnpairError(null);
    setUnpairDone(null);
    setUnpairOpen(true);
  }

  // plan-818: the server-side 409 guard is the real safety net, so the frontend
  // doesn't pre-fetch a preview — it attempts the unpair, and if blocked, renders
  // the unsynced summary from the error body + reveals a checkbox-gated Force
  // path. A forced unpair KEEPS the transaction data on disk (recoverable after
  // re-pair), so the success notice tells the operator that.
  async function handleUnpair(force: boolean) {
    setUnpairing(true);
    setUnpairError(null);
    try {
      const res = await unpairDevice(force);
      setUnpairDone({ kept: !!res.data_kept });
      // Give the operator a beat to read the "data kept" notice, then reload so
      // App.checkPairing() re-runs and shows <Pairing>.
      setTimeout(() => window.location.reload(), res.data_kept ? 2600 : 900);
    } catch (err) {
      setUnpairing(false);
      if (err instanceof ApiError && err.status === 409) {
        setUnpairBlocked(err.data as UnpairBlocked);
        setUnpairAck(false);
        return;
      }
      setUnpairError(err instanceof Error ? err.message : String(err));
    }
  }

  return (
    <>
      <PageHeader title={t("nav.settings")} />
      <PageContent>
        <div className="max-w-xl">
          <Card className="mb-4">
            <CardHeader>
              <CardTitle>Store Information</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <label className="text-xs text-muted-foreground">{t("common.name")}</label>
                <Input value={storeName} onChange={(e) => setStoreName(e.target.value)} placeholder="Your Store Name" className="mt-1" />
              </div>
              <div>
                <label className="text-xs text-muted-foreground">Store Address</label>
                <Input value={storeAddress} onChange={(e) => setStoreAddress(e.target.value)} placeholder="123 Main St" className="mt-1" />
              </div>
              {/* plan-043 (T3.7) — the legacy "Tax Rate (%)" box was removed. The
                  consumption-tax rate is no longer a machine-local config value;
                  it is the SYNCED per-branch tax_rate + per-line tax_types
                  snapshots pulled from Cloud (軽減税率 / インボイス). */}
              <div>
                <label className="text-xs text-muted-foreground">Local Server Port</label>
                <Input type="number" value={String(serverPort)} onChange={(e) => setServerPort(Number(e.target.value))} className="mt-1" />
              </div>
              <Button color={saved ? "success" : "primary"} onClick={save}>
                <Save size={16} />
                {saved ? "Saved!" : t("common.save")}
              </Button>
            </CardContent>
          </Card>

          {device?.paired && (
            <Card className="mb-4">
              <CardHeader>
                <CardTitle>{t("device.section_title")}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-1 text-sm">
                  <div>
                    <span className="text-muted-foreground">{t("common.name")}: </span>
                    <span className="font-medium">{device.device_name || "—"}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Type: </span>
                    <span className="font-medium">{device.device_type || "—"}</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground">{t("common.status")}: </span>
                    <span className="font-medium text-success">{t("device.status_paired")}</span>
                  </div>
                </div>

                {unpairError && (
                  <div className="text-sm text-destructive">
                    {t("device.unpair_failed")}: {unpairError}
                  </div>
                )}

                <Button color="destructive" variant="outline" disabled={unpairing} onClick={openUnpair}>
                  {unpairing ? <Loader2 size={16} className="animate-spin" /> : <Unlink size={16} />}
                  {t("device.unpair")}
                </Button>

                <Dialog open={unpairOpen} onOpenChange={(open) => { if (!unpairing && !unpairDone) setUnpairOpen(open); }}>
                  <DialogContent>
                    {unpairDone ? (
                      // Success view — held briefly before the reload.
                      <>
                        <DialogHeader>
                          <DialogTitle className="flex items-center gap-2">
                            <ShieldCheck size={18} className="text-success" />
                            {t("device.unpair_done_title")}
                          </DialogTitle>
                          <DialogDescription>
                            {unpairDone.kept ? t("device.unpair_kept_notice") : t("device.unpair_done_clean")}
                          </DialogDescription>
                        </DialogHeader>
                        <div className="flex justify-center py-2">
                          <Loader2 size={18} className="animate-spin text-muted-foreground" />
                        </div>
                      </>
                    ) : unpairBlocked ? (
                      // 409 view — show unsynced money + gate the force button on ack.
                      <>
                        <DialogHeader>
                          <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertTriangle size={18} />
                            {t("device.unpair_blocked_title")}
                          </DialogTitle>
                          <DialogDescription>
                            {t("device.unpair_blocked_desc")}
                          </DialogDescription>
                        </DialogHeader>
                        <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm space-y-1">
                          <div className="flex justify-between">
                            <span className="text-muted-foreground">{t("device.unpair_amount_label")}</span>
                            <span className="font-semibold text-destructive">{formatYen(unpairBlocked.unsynced_amount)}</span>
                          </div>
                          <div className="flex justify-between">
                            <span className="text-muted-foreground">{t("device.unpair_orders_label")}</span>
                            <span className="font-medium">{unpairBlocked.unsynced_orders}</span>
                          </div>
                          <div className="flex justify-between">
                            <span className="text-muted-foreground">{t("device.unpair_payments_label")}</span>
                            <span className="font-medium">{unpairBlocked.unsynced_payments}</span>
                          </div>
                        </div>
                        <label className="flex items-start gap-2 text-sm cursor-pointer select-none">
                          <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={unpairAck}
                            onChange={(e) => setUnpairAck(e.target.checked)}
                          />
                          <span>{t("device.unpair_ack_checkbox")}</span>
                        </label>
                        {unpairError && (
                          <div className="text-sm text-destructive">{unpairError}</div>
                        )}
                        <div className="flex justify-end gap-2 pt-2">
                          <Button variant="outline" disabled={unpairing} onClick={() => setUnpairOpen(false)}>
                            {t("common.cancel")}
                          </Button>
                          <Button
                            color="destructive"
                            disabled={unpairing || !unpairAck}
                            onClick={() => handleUnpair(true)}
                          >
                            {unpairing ? <Loader2 size={16} className="animate-spin" /> : <Unlink size={16} />}
                            {t("device.unpair_force_confirm")}
                          </Button>
                        </div>
                      </>
                    ) : (
                      // First-confirm view — static description (safe path / no unsynced data).
                      <>
                        <DialogHeader>
                          <DialogTitle>{t("device.unpair_title")}</DialogTitle>
                          <DialogDescription>{t("device.unpair_description")}</DialogDescription>
                        </DialogHeader>
                        {unpairError && (
                          <div className="text-sm text-destructive">{unpairError}</div>
                        )}
                        <div className="flex justify-end gap-2 pt-2">
                          <Button variant="outline" disabled={unpairing} onClick={() => setUnpairOpen(false)}>
                            {t("common.cancel")}
                          </Button>
                          <Button color="destructive" disabled={unpairing} onClick={() => handleUnpair(false)}>
                            {unpairing ? <Loader2 size={16} className="animate-spin" /> : <Unlink size={16} />}
                            {t("device.unpair_confirm")}
                          </Button>
                        </div>
                      </>
                    )}
                  </DialogContent>
                </Dialog>
              </CardContent>
            </Card>
          )}

          <Card className="mb-4">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ChefHat size={18} />
                KDS — Kitchen Display
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="text-xs text-muted-foreground">
                Khi BẬT: KDS chỉ hiện món đã được "Gửi bếp" (pos-web /
                handy). Khi TẮT: KDS hiện ngay khi nhân viên thêm món vào
                đơn. Plan-038 T9.4.
              </div>
              <div className="flex items-center justify-between rounded-md border p-3">
                <div>
                  <div className="text-sm font-medium">
                    Chỉ hiện món đã gửi bếp
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {kdsShowOnlyFired ? "Đang BẬT" : "Đang TẮT"}
                  </div>
                </div>
                <Button
                  variant={kdsShowOnlyFired ? "default" : "outline"}
                  onClick={handleToggleKdsShowOnlyFired}
                  disabled={kdsToggleSaving}
                >
                  {kdsToggleSaving ? (
                    <Loader2 size={16} className="animate-spin" />
                  ) : kdsShowOnlyFired ? (
                    "Tắt"
                  ) : (
                    "Bật"
                  )}
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card className="mb-4">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Printer size={18} />
                {t("settings.print_title")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="text-xs text-muted-foreground">
                {t("settings.auto_print_desc")}
              </div>
              <div className="flex items-center justify-between rounded-md border p-3">
                <div>
                  <div className="text-sm font-medium">
                    {t("settings.auto_print_label")}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {autoPrintBill ? t("settings.state_on") : t("settings.state_off")}
                  </div>
                </div>
                <Button
                  variant={autoPrintBill ? "default" : "outline"}
                  onClick={handleToggleAutoPrintBill}
                  disabled={autoPrintSaving}
                >
                  {autoPrintSaving ? (
                    <Loader2 size={16} className="animate-spin" />
                  ) : autoPrintBill ? (
                    t("settings.turn_off")
                  ) : (
                    t("settings.turn_on")
                  )}
                </Button>
              </div>

              <div className="text-xs text-muted-foreground pt-2 border-t">
                {t("settings.auto_print_kitchen_desc")}
              </div>
              <div className="flex items-center justify-between rounded-md border p-3">
                <div>
                  <div className="text-sm font-medium">
                    {t("settings.auto_print_kitchen_label")}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {autoPrintKitchen ? t("settings.state_on") : t("settings.state_off")}
                  </div>
                </div>
                <Button
                  variant={autoPrintKitchen ? "default" : "outline"}
                  onClick={handleToggleAutoPrintKitchen}
                  disabled={autoPrintKitchenSaving}
                >
                  {autoPrintKitchenSaving ? (
                    <Loader2 size={16} className="animate-spin" />
                  ) : autoPrintKitchen ? (
                    t("settings.turn_off")
                  ) : (
                    t("settings.turn_on")
                  )}
                </Button>
              </div>

              {/* Print language — Cloud is source of truth; this is the local
                  override for when Cloud config hasn't synced. */}
              <div className="text-xs text-muted-foreground pt-2 border-t">
                {t("settings.print_locale_desc")}
              </div>
              <div className="rounded-md border p-3 space-y-2">
                <div className="text-sm font-medium">
                  {t("settings.print_locale_label")}
                </div>
                <div className="flex flex-wrap gap-2">
                  {[
                    { v: "", label: t("settings.print_locale_follow_cloud") },
                    { v: "ja", label: "日本語" },
                    { v: "en", label: "English" },
                    { v: "vi", label: "Tiếng Việt" },
                  ].map((opt) => (
                    <Button
                      key={opt.v || "cloud"}
                      variant={printLocale === opt.v ? "default" : "outline"}
                      onClick={() => handleSetPrintLocale(opt.v)}
                      disabled={printLocaleSaving}
                    >
                      {opt.label}
                    </Button>
                  ))}
                </div>
                {/* When Cloud has a value it overrides any local pick, so tell
                    the operator their local choice is currently inactive. */}
                {cloudPrintLocale && printLocale && cloudPrintLocale !== printLocale && (
                  <div className="text-xs text-warning">
                    {t("settings.print_locale_cloud_overrides", { locale: cloudPrintLocale })}
                  </div>
                )}
                <div className="text-xs text-muted-foreground">
                  {t("settings.print_locale_hint")}
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>About</CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm text-muted-foreground">
              <div>WS App v{version}</div>
              <div>Go + React 19 + @godxjp/ui</div>
              <div>DXS Platform / Omnify Ecosystem</div>
            </CardContent>
          </Card>
        </div>
      </PageContent>
    </>
  );
}
