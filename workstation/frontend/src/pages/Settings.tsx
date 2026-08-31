import { Flex } from "@godxjp/ui";
import {
  Alert,
  AlertDialog,
  EmptyState,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  toast,
  FormField,
} from "@godxjp/ui/admin";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@godxjp/ui/data-display";
import { Tabs } from "@godxjp/ui/navigation";
import { Form, Input, Switch } from "@godxjp/ui/data-entry";
import { Button, Text } from "@godxjp/ui/general";
import { useEffect, useState } from "react";
import { useSearchParams } from "react-router";
import {
  Save,
  Unlink,
  Loader2,
  ChefHat,
  Printer,
  AlertTriangle,
  ShieldCheck,
  Download,
  RefreshCw,
} from "lucide-react";
import {
  getConfig,
  getSetting,
  setSetting,
  updateServerPort,
  getDeviceStatus,
  unpairDevice,
  getUpdateStatus,
  startUpdateDownload,
  applyUpdate,
  ApiError,
  type DeviceStatus,
  type UnpairBlocked,
  type UpdateStatus,
} from "../lib/api";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";
import { settingTruthy } from "../lib/setting-truthy";

// JPY is minor-unit == major-unit, so no /100 — matches the app-wide
// Intl.NumberFormat("ja-JP") pattern (Reports/Dashboard/Orders).
const formatYen = (amount: number) =>
  "¥" + new Intl.NumberFormat("ja-JP").format(amount || 0);

export function Settings() {
  const { t } = useTranslation();
  const [searchParams] = useSearchParams();
  const initialTab = searchParams.get("tab") === "update" ? "update" : "store";
  const [storeName, setStoreName] = useState("");
  const [storeAddress, setStoreAddress] = useState("");
  const [serverPort, setServerPort] = useState(6969);
  // Last value confirmed by the server (GET /api/config or a prior successful
  // PATCH) — compared against `serverPort` on save so an unchanged port
  // never fires a pointless PATCH + restart prompt.
  const [savedServerPort, setSavedServerPort] = useState(6969);
  const [restartRequired, setRestartRequired] = useState(false);
  const [saved, setSaved] = useState(false);
  const [device, setDevice] = useState<DeviceStatus | null>(null);
  const [unpairing, setUnpairing] = useState(false);
  const [unpairError, setUnpairError] = useState<string | null>(null);
  const [unpairOpen, setUnpairOpen] = useState(false);
  // When the server blocks the unpair (409), this holds the unsynced counts +
  // amount so the dialog can show the operator exactly what's at risk before it
  // reveals the force path. null → the safe/first-confirm view. plan-818.
  const [unpairBlocked, setUnpairBlocked] = useState<UnpairBlocked | null>(
    null,
  );
  const [unpairAck, setUnpairAck] = useState(false);
  const [unpairDone, setUnpairDone] = useState<{ kept: boolean } | null>(null);

  // Plan-038 T9.5 — kds_show_only_fired toggle. The operator opts in
  // once their staff is trained; flipping mid-shift would hide existing
  // KDS items without warning, so the toggle prompts a confirmation.
  const [kdsShowOnlyFired, setKdsShowOnlyFired] = useState(false);
  const [kdsToggleSaving, setKdsToggleSaving] = useState(false);
  const [kdsConfirmOpen, setKdsConfirmOpen] = useState(false);

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
  // #2017 — in bằng template brand/shop đã publish thay vì bản mặc định nhúng
  // trong binary. Trước bài này công tắc này không có bề mặt nào để bấm.
  const [usePublishedTemplates, setUsePublishedTemplates] = useState(false);
  const [usePublishedSaving, setUsePublishedSaving] = useState(false);

  // This workstation's own print language (settings.print_locale_override).
  // Empty = follow the Cloud-synced shop language (HQ default ?? shop override),
  // which is the default and keeps every station in step. A non-empty pick
  // OVERRULES Cloud on this machine — the last layer of
  // HQ → shop → workstation. cloudPrintLocale (shop_settings.print_label_locale)
  // shows what Cloud resolved, so the operator can see what they are overruling.
  const [printLocale, setPrintLocale] = useState("");
  const [cloudPrintLocale, setCloudPrintLocale] = useState("");
  const [printLocaleSaving, setPrintLocaleSaving] = useState(false);
  const [printLocaleError, setPrintLocaleError] = useState(false);

  const [updateStatus, setUpdateStatus] = useState<UpdateStatus | null>(null);
  const [updateBusy, setUpdateBusy] = useState(false);

  useEffect(() => {
    loadSettings();
  }, []);

  useEffect(() => {
    let cancelled = false;
    async function refresh() {
      try {
        const st = await getUpdateStatus();
        if (!cancelled) setUpdateStatus(st);
      } catch {
        /* keep last known */
      }
    }
    void refresh();
    const id = setInterval(() => void refresh(), 2000);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, []);

  async function loadSettings() {
    try {
      const config = await getConfig();
      if (config) {
        setStoreName(config.store_name || "");
        setStoreAddress(config.store_address || "");
        setServerPort(config.server_port || 6969);
        setSavedServerPort(config.server_port || 6969);
      }
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
        // KHÔNG phải `v === "true"` như ba toggle trên: Go đọc riêng khoá này
        // bằng `settingTruthy` (1 · true · yes). Xem `lib/setting-truthy.ts`.
        const v = await getSetting("print_template_use_published_templates");
        setUsePublishedTemplates(settingTruthy(v));
      } catch {}
      // Not silently swallowed: both keys were once missing from the server's
      // read allowlist, so both calls 403'd and this panel rendered blank while
      // a saved override went on deciding every slip's language. A read that
      // fails must say so — an empty selector otherwise reads as "nothing is
      // set", which is exactly the wrong thing to believe here.
      try {
        const v = await getSetting("print_locale_override");
        setPrintLocale(v ?? "");
      } catch (err) {
        console.error("read print_locale_override failed:", err);
        setPrintLocaleError(true);
      }
      try {
        const v = await getSetting("print_label_locale");
        setCloudPrintLocale(v ?? "");
      } catch (err) {
        console.error("read print_label_locale failed:", err);
        setPrintLocaleError(true);
      }
    } catch {}
  }

  async function handleSetPrintLocale(next: string) {
    setPrintLocaleSaving(true);
    try {
      await setSetting("print_locale_override", next);
      setPrintLocale(next);
      setPrintLocaleError(false);
      toast.success(t("toast.saved"));
    } catch (err) {
      console.error("print locale save failed:", err);
      toast.error(t("toast.save_failed"));
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
      toast.success(t("toast.saved"));
    } catch (err) {
      console.error("auto-print toggle failed:", err);
      toast.error(t("toast.save_failed"));
    } finally {
      setAutoPrintSaving(false);
    }
  }

  async function handleToggleUsePublishedTemplates() {
    const next = !usePublishedTemplates;
    setUsePublishedSaving(true);
    try {
      await setSetting(
        "print_template_use_published_templates",
        next ? "true" : "false",
      );
      setUsePublishedTemplates(next);
      toast.success(t("toast.saved"));
    } catch (err) {
      console.error("use-published-templates toggle failed:", err);
      toast.error(t("toast.save_failed"));
    } finally {
      setUsePublishedSaving(false);
    }
  }

  async function handleToggleAutoPrintKitchen() {
    const next = !autoPrintKitchen;
    setAutoPrintKitchenSaving(true);
    try {
      await setSetting("auto_print_kitchen", next ? "true" : "false");
      setAutoPrintKitchen(next);
      toast.success(t("toast.saved"));
    } catch (err) {
      console.error("auto-print kitchen toggle failed:", err);
      toast.error(t("toast.save_failed"));
    } finally {
      setAutoPrintKitchenSaving(false);
    }
  }

  // `window.confirm` blocks the whole webview in the Wails shell (and looks
  // nothing like the rest of the app) — the confirmation runs through godx's
  // <AlertDialog> instead.
  async function handleToggleKdsShowOnlyFired() {
    setKdsConfirmOpen(true);
  }

  async function applyKdsShowOnlyFired() {
    const next = !kdsShowOnlyFired;
    setKdsConfirmOpen(false);
    setKdsToggleSaving(true);
    try {
      await setSetting("kds_show_only_fired", next ? "true" : "false");
      setKdsShowOnlyFired(next);
      toast.success(t("toast.saved"));
    } catch (err) {
      console.error("kds toggle failed:", err);
      toast.error(t("toast.save_failed"));
    } finally {
      setKdsToggleSaving(false);
    }
  }

  async function save() {
    try {
      await setSetting("store_name", storeName);
      await setSetting("store_address", storeAddress);
      // Only PATCH when the port actually changed — an unmodified value
      // shouldn't fire a request or surface the restart prompt.
      if (serverPort !== savedServerPort) {
        const result = await updateServerPort(serverPort);
        setSavedServerPort(result.server_port);
        setRestartRequired(result.restart_required);
      }
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
      toast.success(t("toast.saved"));
    } catch (err) {
      console.error("save failed:", err);
      toast.error(t("toast.save_failed"));
    }
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
  function updateStateLabel(st: UpdateStatus): string {
    switch (st.state) {
      case "up_to_date":
        return t("settings.update_up_to_date");
      case "ready":
        return t("settings.update_ready");
      case "downloading":
        return t("settings.update_downloading");
      case "error":
        return t("settings.update_error");
      case "needs_manual":
        return t("settings.update_manual");
      default:
        return st.state || "—";
    }
  }

  async function handleRetryDownload() {
    setUpdateBusy(true);
    try {
      setUpdateStatus(await startUpdateDownload());
    } catch (err) {
      console.error("update download failed:", err);
      toast.error(t("settings.update_error"));
    } finally {
      setUpdateBusy(false);
    }
  }

  async function handleApplyUpdate() {
    setUpdateBusy(true);
    try {
      await applyUpdate();
      toast.success(t("settings.update_apply_started"));
    } catch (err) {
      console.error("update apply failed:", err);
      toast.error(
        err instanceof ApiError
          ? err.message
          : t("settings.update_apply_failed"),
      );
      try {
        setUpdateStatus(await getUpdateStatus());
      } catch {}
    } finally {
      setUpdateBusy(false);
    }
  }

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

  const tabItems = [
    {
      value: "store",
      label: t("settings.tab_store"),
      content: (
        <Card>
          <CardHeader>
            <CardTitle>{t("settings.store_title")}</CardTitle>
          </CardHeader>
          <CardContent>
            <Form layout="horizontal" labelWidth={220}>
              <FormField label={t("settings.store_name")}>
                <Input
                  value={storeName}
                  onChange={(e) => setStoreName(e.target.value)}
                  placeholder={t("settings.store_name_placeholder")}
                />
              </FormField>
              <FormField label={t("settings.store_address")}>
                <Input
                  value={storeAddress}
                  onChange={(e) => setStoreAddress(e.target.value)}
                  placeholder={t("settings.store_address_placeholder")}
                />
              </FormField>
              {/* plan-043 (T3.7) — the legacy "Tax Rate (%)" box was removed. The
                        consumption-tax rate is no longer a machine-local config value;
                        it is the SYNCED per-branch tax_rate + per-line tax_types
                        snapshots pulled from Cloud (軽減税率 / インボイス). */}
              <FormField
                label={t("settings.server_port")}
                helper={t("settings.server_port_help")}
              >
                <Input
                  type="number"
                  value={String(serverPort)}
                  onChange={(e) => setServerPort(Number(e.target.value))}
                />
              </FormField>
              {restartRequired && (
                <Alert tone="warning">{t("settings.server_port_restart_required")}</Alert>
              )}
              <Flex justify="end">
                <Button color={saved ? "success" : "primary"} onClick={save}>
                  <Save size={16} />
                  {saved ? t("settings.saved") : t("common.save")}
                </Button>
              </Flex>
            </Form>
          </CardContent>
        </Card>
      ),
    },
    {
      value: "device",
      label: t("settings.tab_device"),
      content: device?.paired ? (
        <Card>
          <CardHeader>
            <CardTitle>{t("device.section_title")}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-1 text-sm">
              <div>
                <span className="text-muted-foreground">
                  {t("common.name")}:{" "}
                </span>
                <span className="font-medium">{device.device_name || "—"}</span>
              </div>
              <div>
                <span className="text-muted-foreground">
                  {t("settings.device_type")}:{" "}
                </span>
                <span className="font-medium">{device.device_type || "—"}</span>
              </div>
              <div>
                <span className="text-muted-foreground">
                  {t("common.status")}:{" "}
                </span>
                <span className="font-medium text-success">
                  {t("device.status_paired")}
                </span>
              </div>
            </div>

            {unpairError && (
              <div className="text-sm text-destructive">
                {t("device.unpair_failed")}: {unpairError}
              </div>
            )}

            <Button
              color="destructive"
              variant="outline"
              disabled={unpairing}
              onClick={openUnpair}
            >
              {unpairing ? (
                <Loader2 size={16} className="animate-spin" />
              ) : (
                <Unlink size={16} />
              )}
              {t("device.unpair")}
            </Button>

            <Dialog
              open={unpairOpen}
              onOpenChange={(open) => {
                if (!unpairing && !unpairDone) setUnpairOpen(open);
              }}
            >
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
                        {unpairDone.kept
                          ? t("device.unpair_kept_notice")
                          : t("device.unpair_done_clean")}
                      </DialogDescription>
                    </DialogHeader>
                    <div className="flex justify-center py-2">
                      <Loader2
                        size={18}
                        className="animate-spin text-muted-foreground"
                      />
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
                        <span className="text-muted-foreground">
                          {t("device.unpair_amount_label")}
                        </span>
                        <span className="font-semibold text-destructive">
                          {formatYen(unpairBlocked.unsynced_amount)}
                        </span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">
                          {t("device.unpair_orders_label")}
                        </span>
                        <span className="font-medium">
                          {unpairBlocked.unsynced_orders}
                        </span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">
                          {t("device.unpair_payments_label")}
                        </span>
                        <span className="font-medium">
                          {unpairBlocked.unsynced_payments}
                        </span>
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
                      <div className="text-sm text-destructive">
                        {unpairError}
                      </div>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                      <Button
                        variant="outline"
                        disabled={unpairing}
                        onClick={() => setUnpairOpen(false)}
                      >
                        {t("common.cancel")}
                      </Button>
                      <Button
                        color="destructive"
                        disabled={unpairing || !unpairAck}
                        onClick={() => handleUnpair(true)}
                      >
                        {unpairing ? (
                          <Loader2 size={16} className="animate-spin" />
                        ) : (
                          <Unlink size={16} />
                        )}
                        {t("device.unpair_force_confirm")}
                      </Button>
                    </div>
                  </>
                ) : (
                  // First-confirm view — static description (safe path / no unsynced data).
                  <>
                    <DialogHeader>
                      <DialogTitle>{t("device.unpair_title")}</DialogTitle>
                      <DialogDescription>
                        {t("device.unpair_description")}
                      </DialogDescription>
                    </DialogHeader>
                    {unpairError && (
                      <div className="text-sm text-destructive">
                        {unpairError}
                      </div>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                      <Button
                        variant="outline"
                        disabled={unpairing}
                        onClick={() => setUnpairOpen(false)}
                      >
                        {t("common.cancel")}
                      </Button>
                      <Button
                        color="destructive"
                        disabled={unpairing}
                        onClick={() => handleUnpair(false)}
                      >
                        {unpairing ? (
                          <Loader2 size={16} className="animate-spin" />
                        ) : (
                          <Unlink size={16} />
                        )}
                        {t("device.unpair_confirm")}
                      </Button>
                    </div>
                  </>
                )}
              </DialogContent>
            </Dialog>
          </CardContent>
        </Card>
      ) : (
        <EmptyState
          icon={ShieldCheck}
          title={t("device.not_paired_title")}
          description={t("device.not_paired_desc")}
        />
      ),
    },
    {
      value: "kds",
      label: t("settings.tab_kds"),
      content: (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ChefHat size={18} />
              {t("settings.kds_title")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Form layout="horizontal" labelWidth={220}>
              <FormField
                label={t("settings.kds_only_fired_label")}
                helper={t("settings.kds_only_fired_helper")}
              >
                <Flex align="center" gap="sm">
                  {kdsToggleSaving && (
                    <Loader2 size={14} className="animate-spin" />
                  )}
                  <Switch
                    checked={kdsShowOnlyFired}
                    onCheckedChange={handleToggleKdsShowOnlyFired}
                    disabled={kdsToggleSaving}
                  />
                </Flex>
              </FormField>
            </Form>
            <AlertDialog
              open={kdsConfirmOpen}
              onOpenChange={setKdsConfirmOpen}
              title={t("settings.kds_only_fired_label")}
              description={
                kdsShowOnlyFired
                  ? t("settings.kds_confirm_off")
                  : t("settings.kds_confirm_on")
              }
              confirmLabel={t("common.save")}
              cancelLabel={t("common.cancel")}
              onConfirm={applyKdsShowOnlyFired}
              pending={kdsToggleSaving}
            />
          </CardContent>
        </Card>
      ),
    },
    {
      value: "print",
      label: t("settings.tab_print"),
      content: (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Printer size={18} />
              {t("settings.print_title")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Form layout="horizontal" labelWidth={220}>
              <FormField
                label={t("settings.auto_print_label")}
                helper={t("settings.auto_print_desc")}
              >
                <Flex align="center" gap="sm">
                  {autoPrintSaving && (
                    <Loader2 size={14} className="animate-spin" />
                  )}
                  <Switch
                    checked={autoPrintBill}
                    onCheckedChange={handleToggleAutoPrintBill}
                    disabled={autoPrintSaving}
                  />
                </Flex>
              </FormField>

              <FormField
                label={t("settings.auto_print_kitchen_label")}
                helper={t("settings.auto_print_kitchen_desc")}
              >
                <Flex align="center" gap="sm">
                  {autoPrintKitchenSaving && (
                    <Loader2 size={14} className="animate-spin" />
                  )}
                  <Switch
                    checked={autoPrintKitchen}
                    onCheckedChange={handleToggleAutoPrintKitchen}
                    disabled={autoPrintKitchenSaving}
                  />
                </Flex>
              </FormField>

              {/* #2017 — công tắc này CỐ Ý ở máy, không phải cờ Cloud: nó đòi một
                  người đứng cạnh máy in xem tờ giấy đầu tiên. Bật hàng loạt từ HQ
                  sẽ đổi giấy ở mọi quán mà không ai nhìn. */}
              <FormField
                label={t("settings.use_published_templates_label")}
                helper={t("settings.use_published_templates_desc")}
              >
                <Flex align="center" gap="sm">
                  {usePublishedSaving && (
                    <Loader2 size={14} className="animate-spin" />
                  )}
                  <Switch
                    checked={usePublishedTemplates}
                    onCheckedChange={handleToggleUsePublishedTemplates}
                    disabled={usePublishedSaving}
                  />
                </Flex>
              </FormField>
            </Form>

            {/* Print language — last layer of HQ → shop → workstation. Empty
                follows the Cloud-resolved value; a pick here overrules it on
                this machine only. */}
            <div className="mt-4 border-t pt-3 text-xs text-muted-foreground">
              {t("settings.print_locale_desc")}
            </div>
            <div className="mt-2 space-y-2 rounded-md border p-3">
              <div className="text-sm font-medium">
                {t("settings.print_locale_label")}
              </div>
              <Flex gap="sm" className="flex-wrap">
                {[
                  { v: "", label: t("settings.print_locale_follow_cloud") },
                  { v: "ja", label: "日本語" },
                  { v: "en", label: "English" },
                  { v: "vi", label: "Tiếng Việt" },
                ].map((opt) => (
                  <Button
                    key={opt.v || "cloud"}
                    size="sm"
                    variant={printLocale === opt.v ? "default" : "outline"}
                    onClick={() => handleSetPrintLocale(opt.v)}
                    disabled={printLocaleSaving}
                  >
                    {opt.label}
                  </Button>
                ))}
              </Flex>
              {printLocaleError && (
                <Text size="xs" tone="destructive">
                  {t("settings.print_locale_read_failed")}
                </Text>
              )}
              {cloudPrintLocale && printLocale && cloudPrintLocale !== printLocale && (
                <Text size="xs" tone="warning">
                  {t("settings.print_locale_overrides_cloud", { locale: cloudPrintLocale })}
                </Text>
              )}
              <Text size="xs" tone="muted">
                {t("settings.print_locale_hint")}
              </Text>
            </div>
          </CardContent>
        </Card>
      ),
    },
    {
      value: "update",
      label: t("settings.tab_update"),
      content: (
        <Card>
          <CardHeader>
            <CardTitle>{t("settings.update_title")}</CardTitle>
          </CardHeader>
          <CardContent>
            {!updateStatus ? (
              <Flex align="center" gap="sm">
                <Loader2 className="h-4 w-4 animate-spin" />
                <Text size="sm" tone="muted">
                  …
                </Text>
              </Flex>
            ) : (
              <div className="space-y-4">
                <Form layout="horizontal" labelWidth={220}>
                  <FormField label={t("settings.update_current")}>
                    <Text size="sm">{updateStatus.current_version || "—"}</Text>
                  </FormField>
                  <FormField label={t("settings.update_expected")}>
                    <Text size="sm">
                      {updateStatus.expected_version || "—"}
                    </Text>
                  </FormField>
                  {updateStatus.reason ? (
                    <FormField label={t("settings.update_reason")}>
                      <Text size="sm">{updateStatus.reason}</Text>
                    </FormField>
                  ) : null}
                  <FormField label={t("settings.update_state")}>
                    <Text size="sm">{updateStateLabel(updateStatus)}</Text>
                  </FormField>
                  {updateStatus.state === "downloading" && (
                    <FormField label={t("settings.update_progress")}>
                      <Text size="sm">{updateStatus.progress_percent}%</Text>
                    </FormField>
                  )}
                </Form>

                {updateStatus.error && (
                  <Alert tone="warning">{updateStatus.error}</Alert>
                )}

                {updateStatus.shift_open && (
                  <Alert tone="warning">{t("settings.update_shift_open")}</Alert>
                )}

                {(updateStatus.state === "needs_manual" ||
                  !updateStatus.package_available) &&
                  updateStatus.expected_version &&
                  updateStatus.current_version !==
                    updateStatus.expected_version && (
                    <Alert tone="info">
                      <div className="space-y-2">
                        <p>{t("settings.update_manual")}</p>
                        {updateStatus.manual_download_url && (
                          <a
                            href={updateStatus.manual_download_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sm underline"
                          >
                            <Download className="h-3.5 w-3.5" />
                            {t("settings.update_manual_link")}
                          </a>
                        )}
                      </div>
                    </Alert>
                  )}

                <Flex gap="sm" justify="end">
                  {updateStatus.package_available &&
                    updateStatus.expected_version &&
                    updateStatus.current_version !==
                      updateStatus.expected_version && (
                      <Button
                        variant="outline"
                        disabled={updateBusy || updateStatus.state === "downloading"}
                        onClick={() => void handleRetryDownload()}
                      >
                        <RefreshCw className="mr-1 h-4 w-4" />
                        {t("settings.update_retry")}
                      </Button>
                    )}
                  <Button
                    color="primary"
                    disabled={
                      updateBusy ||
                      !updateStatus.can_apply ||
                      updateStatus.shift_open
                    }
                    onClick={() => void handleApplyUpdate()}
                  >
                    {updateBusy ? (
                      <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                    ) : null}
                    {t("settings.update_apply")}
                  </Button>
                </Flex>
              </div>
            )}
          </CardContent>
        </Card>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={t("nav.settings")} />
      <PageContent>
        <Tabs items={tabItems} defaultValue={initialTab} />
      </PageContent>
    </>
  );
}
