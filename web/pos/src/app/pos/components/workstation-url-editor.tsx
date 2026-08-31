import { useState } from "react";
import { PencilIcon } from "lucide-react";
import { toast } from "sonner";
import { Button, Input } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useWorkstation } from "@/providers/workstation-provider";

/**
 * Inline editor for the per-device workstation URL (IP:port). Each shop's
 * workstation runs on its own operator-chosen port, so this can't be a
 * build-time constant (see base-url-resolver.ts::getWorkstationUrl) — the
 * cashier pairs/repairs it here. Shared by ConnectionStatusBadge (routine
 * Settings access) and ShiftGate's blocking error screen (recovery when the
 * paired address is wrong and there's no other way to reach Settings).
 */
export function WorkstationUrlEditor() {
  const { t } = useTranslation();
  const { workstationUrl, setWorkstationUrl, clearWorkstationUrl } =
    useWorkstation();
  const [editingUrl, setEditingUrl] = useState(false);
  const [urlDraft, setUrlDraft] = useState(workstationUrl);

  function startEditingUrl() {
    setUrlDraft(workstationUrl);
    setEditingUrl(true);
  }

  function saveUrl() {
    const trimmed = urlDraft.trim();
    try {
      new URL(trimmed);
    } catch {
      toast.error(t("connection.workstation_url.invalid"));
      return;
    }
    setWorkstationUrl(trimmed);
    setEditingUrl(false);
    toast.success(t("connection.workstation_url.saved"));
  }

  return (
    <div className="px-2 py-1.5">
      <div className="mb-1 flex items-center justify-between">
        <span className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          {t("connection.workstation_url.label")}
        </span>
        {!editingUrl && (
          <Button
            variant="ghost"
            size="sm"
            className="h-6 gap-1 px-1.5 text-[11px]"
            onClick={(e) => {
              e.preventDefault();
              startEditingUrl();
            }}
          >
            <PencilIcon className="size-3" />
            {t("connection.workstation_url.edit")}
          </Button>
        )}
      </div>
      {editingUrl ? (
        <div className="space-y-1.5">
          <Input
            autoFocus
            value={urlDraft}
            onChange={(e) => setUrlDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                saveUrl();
              } else if (e.key === "Escape") {
                e.preventDefault();
                setEditingUrl(false);
              }
            }}
            placeholder="http://192.168.1.50:6969"
            className="h-7 font-mono text-[11px]"
          />
          <div className="flex justify-end gap-1.5">
            <Button
              variant="ghost"
              size="sm"
              className="h-6 px-2 text-[11px]"
              onClick={(e) => {
                e.preventDefault();
                setEditingUrl(false);
              }}
            >
              {t("connection.workstation_url.cancel")}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="h-6 px-2 text-[11px]"
              onClick={(e) => {
                e.preventDefault();
                clearWorkstationUrl();
                setEditingUrl(false);
                toast.success(t("connection.workstation_url.reset"));
              }}
            >
              {t("connection.workstation_url.use_default")}
            </Button>
            <Button
              size="sm"
              className="h-6 px-2 text-[11px]"
              onClick={(e) => {
                e.preventDefault();
                saveUrl();
              }}
            >
              {t("connection.workstation_url.save")}
            </Button>
          </div>
        </div>
      ) : (
        <div className="font-mono text-[11px] text-foreground">
          {workstationUrl}
        </div>
      )}
    </div>
  );
}
