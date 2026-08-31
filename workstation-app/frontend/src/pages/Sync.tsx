import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router";
import { Card, CardContent, CardHeader, CardTitle, Button, Badge, Dialog, DialogContent, DialogHeader, DialogTitle } from "@godxjp/ui";
import { RefreshCw, Wifi, WifiOff, AlertTriangle, CheckCircle, ArrowUp, ArrowDown, ChefHat, Monitor, Activity } from "lucide-react";
import { getSyncInfo, getLANInfo, retrySyncFailed } from "../lib/api";
import type { SyncInfo, LANInfo, SyncTraceEvent, QueueItem } from "../lib/api";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

// Flow → accent color + icon for the sync activity feed. Labels are resolved
// via i18n (sync.flow.<flow>), not hardcoded here.
const FLOW_STYLE: Record<string, string> = {
  up: "text-sky-500 bg-sky-500/10",
  down: "text-emerald-500 bg-emerald-500/10",
  kds: "text-amber-500 bg-amber-500/10",
  lan: "text-violet-500 bg-violet-500/10",
  conn: "text-muted-foreground bg-muted",
};

const FLOW_ICON: Record<string, typeof ArrowUp> = {
  up: ArrowUp,
  down: ArrowDown,
  kds: ChefHat,
  lan: Monitor,
  conn: Wifi,
};

// Filter tabs: "" = all flows; "lan" uses a fuller label to hint kiosk/pos.
const FLOW_TAB_KEYS = ["", "up", "down", "kds", "lan", "conn"] as const;

function TraceRow({ ev, onClick }: { ev: SyncTraceEvent; onClick: () => void }) {
  const { t } = useTranslation();
  const style = FLOW_STYLE[ev.flow] ?? "text-muted-foreground bg-muted";
  const Icon = FLOW_ICON[ev.flow] ?? Activity;
  const isError = ev.status === "error";
  const target = ev.entity_id ? `${ev.entity_type}/${ev.entity_id.slice(0, 8)}` : ev.entity_type;
  return (
    <button
      type="button"
      onClick={onClick}
      className={`w-full text-left flex items-center justify-between py-1.5 px-2 rounded-md text-xs cursor-pointer transition-colors hover:ring-1 hover:ring-primary/40 ${isError ? "bg-destructive/10" : "bg-muted"}`}
      title={t("sync.click_details")}
    >
      <div className="flex items-center gap-2 min-w-0">
        <span className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded font-medium shrink-0 ${style}`}>
          <Icon size={11} />
          {t(`sync.flow.${ev.flow}`)}
        </span>
        <span className="font-medium shrink-0">{ev.operation}</span>
        {target && <span className="text-muted-foreground truncate">{target}</span>}
      </div>
      <div className="flex items-center gap-2 shrink-0">
        {ev.count ? <span className="text-muted-foreground">{ev.count} {t("sync.rows")}</span> : null}
        {ev.status_code ? <span className={isError ? "text-destructive" : "text-muted-foreground"}>{ev.status_code}</span> : null}
        {ev.attempt ? <span className="text-warning">#{ev.attempt}</span> : null}
        {typeof ev.latency_ms === "number" && ev.latency_ms > 0 && (
          <span className="text-muted-foreground font-mono">{ev.latency_ms}ms</span>
        )}
        {isError ? (
          <AlertTriangle size={12} className="text-destructive" />
        ) : ev.status === "ok" ? (
          <CheckCircle size={12} className="text-success" />
        ) : (
          <RefreshCw size={12} className="text-muted-foreground" />
        )}
        <span className="text-muted-foreground">{new Date(ev.at).toLocaleTimeString()}</span>
      </div>
    </button>
  );
}

// TraceDetail renders one field row in the details dialog; hidden when empty.
function DetailRow({ label, value, mono }: { label: string; value?: string | number; mono?: boolean }) {
  if (value === undefined || value === "" || value === 0) return null;
  return (
    <div className="flex justify-between gap-4 py-1 border-b border-border/50 last:border-0">
      <span className="text-muted-foreground shrink-0">{label}</span>
      <span className={`text-right break-all ${mono ? "font-mono text-xs" : ""}`}>{value}</span>
    </div>
  );
}

export function Sync() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [syncInfo, setSyncInfo] = useState<SyncInfo | null>(null);
  const [lanInfo, setLanInfo] = useState<LANInfo | null>(null);
  const [traceFlow, setTraceFlow] = useState<string>("");
  const [selectedEvent, setSelectedEvent] = useState<SyncTraceEvent | null>(null);
  const [selectedQueue, setSelectedQueue] = useState<QueueItem | null>(null);

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 3000);
    return () => clearInterval(interval);
  }, []);

  async function loadData() {
    try {
      const si = await getSyncInfo();
      if (si) setSyncInfo(si);
      const li = await getLANInfo();
      if (li) setLanInfo(li);
    } catch {}
  }

  const filteredTrace = useMemo(() => {
    const trace = syncInfo?.trace ?? [];
    return traceFlow ? trace.filter((e) => e.flow === traceFlow) : trace;
  }, [syncInfo?.trace, traceFlow]);

  async function retryFailed() {
    try {
      const result = await retrySyncFailed();
      alert(t("sync.retry_queued", { count: result.count }));
      loadData();
    } catch (err) { console.error(err); }
  }

  return (
    <>
      <PageHeader title={t("nav.sync")} />
      <PageContent>
        <div className="grid grid-cols-2 gap-4 mb-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                {syncInfo?.status === "online" ? (
                  <Wifi size={16} className="text-success" />
                ) : (
                  <WifiOff size={16} className="text-muted-foreground" />
                )}
                {t("sync.cloud_sync")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("common.status")}</span>
                <Badge color={syncInfo?.status === "online" ? "success" : undefined} variant="soft">
                  {syncInfo?.status || "offline"}
                </Badge>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("sync.pending_operations")}</span>
                <span className="font-medium">{syncInfo?.pending_count ?? 0}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("sync.failed_operations")}</span>
                <span className="font-medium text-destructive">{syncInfo?.failed_count ?? 0}</span>
              </div>
              {(syncInfo?.failed_count ?? 0) > 0 && (
                <Button color="warning" size="sm" className="w-full mt-2" onClick={retryFailed}>
                  {t("sync.retry_failed")}
                </Button>
              )}
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("sync.dead_lettered")}</span>
                <span className="font-medium text-destructive">{syncInfo?.dead_letter_count ?? 0}</span>
              </div>
              {syncInfo?.throttled && (
                <div className="flex justify-between">
                  <span className="text-muted-foreground">{t("sync.throttled")}</span>
                  <span className="font-medium text-warning">{t("sync.paused")}</span>
                </div>
              )}
              {(syncInfo?.dead_letter_count ?? 0) > 0 && (
                <Button color="destructive" size="sm" className="w-full mt-2" onClick={() => navigate("/sync-recovery")}>
                  {t("sync_recovery.view")}
                </Button>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Wifi size={16} className="text-primary" />
                {t("ws.lan_server")}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("sync.ip_address")}</span>
                <span className="font-mono font-medium">{lanInfo?.ip || "-"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("sync.port")}</span>
                <span className="font-mono font-medium">{lanInfo?.port || "-"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("sync.url")}</span>
                <span className="font-mono text-xs text-primary">{lanInfo?.url || "-"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("ws.connected_clients")}</span>
                <span className="font-medium">{lanInfo?.ws_clients ?? 0}</span>
              </div>
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>{t("sync.queue")}</CardTitle>
          </CardHeader>
          <CardContent>
            {(!syncInfo?.history || syncInfo.history.length === 0) ? (
              <p className="text-sm text-muted-foreground">{t("common.no_data")}</p>
            ) : (
              <div className="space-y-1 max-h-96 overflow-y-auto">
                {syncInfo.history.map((item) => (
                  <button
                    type="button"
                    key={item.id}
                    onClick={() => setSelectedQueue(item)}
                    className="w-full text-left flex items-center justify-between py-1.5 px-2 rounded-md bg-muted text-xs cursor-pointer transition-colors hover:ring-1 hover:ring-primary/40"
                    title={t("sync.click_details")}
                  >
                    <div className="flex items-center gap-2">
                      {item.synced_at ? (
                        <CheckCircle size={12} className="text-success" />
                      ) : item.last_error ? (
                        <AlertTriangle size={12} className="text-destructive" />
                      ) : (
                        <RefreshCw size={12} className="text-muted-foreground" />
                      )}
                      <span className="font-medium">{item.operation}</span>
                      <span className="text-muted-foreground">
                        {item.entity_type}/{item.entity_id.slice(0, 8)}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      {item.last_error && (
                        <span className="text-destructive">
                          error ({item.attempts}x)
                        </span>
                      )}
                      <span className="text-muted-foreground">
                        {new Date(item.created_at).toLocaleTimeString()}
                      </span>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card className="mt-4">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Activity size={16} className="text-primary" />
              {t("sync.activity_log")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-1.5 mb-3">
              {FLOW_TAB_KEYS.map((key) => (
                <button
                  key={key || "all"}
                  onClick={() => setTraceFlow(key)}
                  className={`px-2.5 py-1 rounded-md text-xs font-medium transition-colors ${
                    traceFlow === key
                      ? "bg-primary text-primary-foreground"
                      : "bg-muted text-muted-foreground hover:bg-muted/70"
                  }`}
                >
                  {key === "" ? t("sync.flow.all") : key === "lan" ? t("sync.tab_lan") : t(`sync.flow.${key}`)}
                </button>
              ))}
            </div>
            {filteredTrace.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("common.no_data")}</p>
            ) : (
              <div className="space-y-1 max-h-[28rem] overflow-y-auto">
                {filteredTrace.map((ev) => (
                  <TraceRow key={ev.seq} ev={ev} onClick={() => setSelectedEvent(ev)} />
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Dialog open={!!selectedEvent} onOpenChange={(open) => !open && setSelectedEvent(null)}>
          <DialogContent className="max-w-lg max-h-[85vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Activity size={16} className="text-primary" />
                {t("sync.event_detail")}
              </DialogTitle>
            </DialogHeader>
            {selectedEvent && (
              <div className="text-sm">
                <DetailRow label={t("sync.flow_label")} value={t(`sync.flow.${selectedEvent.flow}`)} />
                <DetailRow label={t("sync.phase")} value={selectedEvent.phase} />
                <DetailRow label={t("sync.operation")} value={selectedEvent.operation} />
                <DetailRow label={t("sync.entity_type")} value={selectedEvent.entity_type} />
                <DetailRow label={t("sync.entity_id")} value={selectedEvent.entity_id} mono />
                <DetailRow label={t("sync.trace_id")} value={selectedEvent.trace_id} mono />
                <DetailRow label={t("sync.result")} value={selectedEvent.status} />
                <DetailRow label={t("sync.latency")} value={selectedEvent.latency_ms ? `${selectedEvent.latency_ms} ms` : undefined} />
                <DetailRow label={t("sync.attempt")} value={selectedEvent.attempt} />
                <DetailRow label={t("sync.rows")} value={selectedEvent.count} />
                <DetailRow label={t("sync.status_code")} value={selectedEvent.status_code} />
                <DetailRow label={t("sync.time")} value={new Date(selectedEvent.at).toLocaleString()} />
                {selectedEvent.error && (
                  <div className="mt-3">
                    <div className="text-muted-foreground mb-1">{t("sync.error")}</div>
                    <pre className="whitespace-pre-wrap break-all rounded-md bg-destructive/10 text-destructive p-2 text-xs max-h-72 overflow-y-auto">
                      {selectedEvent.error}
                    </pre>
                  </div>
                )}
              </div>
            )}
          </DialogContent>
        </Dialog>

        <Dialog open={!!selectedQueue} onOpenChange={(open) => !open && setSelectedQueue(null)}>
          <DialogContent className="max-w-lg max-h-[85vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <RefreshCw size={16} className="text-primary" />
                {t("sync.event_detail")}
              </DialogTitle>
            </DialogHeader>
            {selectedQueue && (
              <div className="text-sm">
                <DetailRow label={t("sync.operation")} value={selectedQueue.operation} />
                <DetailRow label={t("sync.entity_type")} value={selectedQueue.entity_type} />
                <DetailRow label={t("sync.entity_id")} value={selectedQueue.entity_id} mono />
                <DetailRow label={t("sync.attempt")} value={selectedQueue.attempts} />
                <DetailRow label={t("sync.time")} value={new Date(selectedQueue.created_at).toLocaleString()} />
                {selectedQueue.synced_at && (
                  <DetailRow label={t("sync.result")} value={new Date(selectedQueue.synced_at).toLocaleString()} />
                )}
                {selectedQueue.last_error && (
                  <div className="mt-3">
                    <div className="text-muted-foreground mb-1">{t("sync.error")}</div>
                    <pre className="whitespace-pre-wrap break-all rounded-md bg-destructive/10 text-destructive p-2 text-xs max-h-72 overflow-y-auto">
                      {selectedQueue.last_error}
                    </pre>
                  </div>
                )}
              </div>
            )}
          </DialogContent>
        </Dialog>
      </PageContent>
    </>
  );
}
