import { useState } from "react";
import { Settings, X } from "lucide-react";
import { useTranslation } from "@/i18n";
import { useAuth } from "@/providers/use-auth";
import { useTheme, type Theme } from "@/providers/use-theme";
import { getMode, setMode, type ApiMode } from "@/services/base-url-resolver";
import { cn } from "@/lib/utils";

const AUDIO_STORAGE_KEY = "kds_audio_enabled";

function readAudioEnabled(): boolean {
  try {
    return localStorage.getItem(AUDIO_STORAGE_KEY) !== "0";
  } catch {
    return true;
  }
}

export function SettingsDrawer() {
  const { t } = useTranslation();
  const { logout, device } = useAuth();
  const { theme, setTheme } = useTheme();
  const [open, setOpen] = useState(false);
  const [mode, setLocalMode] = useState<ApiMode>(getMode());
  const [audio, setAudio] = useState<boolean>(() => readAudioEnabled());

  function changeMode(m: ApiMode) {
    setMode(m);
    setLocalMode(m);
  }

  function changeAudio(on: boolean) {
    try {
      localStorage.setItem(AUDIO_STORAGE_KEY, on ? "1" : "0");
    } catch {
      // ignore
    }
    setAudio(on);
  }

  function handleLogout() {
    if (confirm(t("settings.confirm_forget"))) {
      logout();
    }
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="p-2 min-h-11 min-w-11 hover:bg-muted rounded"
        aria-label={t("settings.title")}
      >
        <Settings className="size-5" />
      </button>

      {open && (
        <div
          data-testid="settings-overlay"
          className="fixed inset-0 z-50 bg-black/50"
          onClick={() => setOpen(false)}
        >
          <aside
            className="absolute right-0 top-0 h-full w-full max-w-sm bg-card text-card-foreground shadow-xl flex flex-col"
            onClick={(e) => e.stopPropagation()}
          >
            <header className="p-4 border-b flex justify-between items-center">
              <h2 className="text-lg font-semibold">{t("settings.title")}</h2>
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="p-2 min-h-11 min-w-11 hover:bg-muted rounded"
                aria-label={t("common.close")}
              >
                <X className="size-5" />
              </button>
            </header>

            <div className="flex-1 overflow-y-auto p-4 space-y-6">
              {/* Connection mode */}
              <section>
                <h3 className="text-sm font-medium mb-2">{t("connection.mode_label")}</h3>
                <div className="space-y-1">
                  {(["auto", "workstation", "cloud"] as const).map((m) => (
                    <label
                      key={m}
                      className={cn(
                        "flex items-center gap-3 p-3 rounded cursor-pointer hover:bg-muted min-h-11",
                        mode === m && "bg-muted"
                      )}
                    >
                      <input
                        type="radio"
                        name="api-mode"
                        checked={mode === m}
                        onChange={() => changeMode(m)}
                        className="size-4"
                      />
                      <span>{t(`settings.mode_${m}`)}</span>
                    </label>
                  ))}
                </div>
              </section>

              {/* Theme */}
              <section>
                <h3 className="text-sm font-medium mb-2">{t("settings.theme_label")}</h3>
                <div className="space-y-1">
                  {(["light", "dark", "system"] as const).map((thm) => (
                    <label
                      key={thm}
                      className={cn(
                        "flex items-center gap-3 p-3 rounded cursor-pointer hover:bg-muted min-h-11",
                        theme === thm && "bg-muted"
                      )}
                    >
                      <input
                        type="radio"
                        name="theme"
                        checked={theme === thm}
                        onChange={() => setTheme(thm as Theme)}
                        className="size-4"
                      />
                      <span>{t(`settings.theme_${thm}`)}</span>
                    </label>
                  ))}
                </div>
              </section>

              {/* Audio */}
              <section>
                <label className="flex items-center gap-3 p-3 rounded cursor-pointer hover:bg-muted min-h-11">
                  <input
                    type="checkbox"
                    checked={audio}
                    onChange={(e) => changeAudio(e.target.checked)}
                    className="size-4"
                  />
                  <span className="font-medium">{t("settings.audio")}</span>
                </label>
              </section>

              {/* Device info */}
              {device && (
                <section className="text-sm text-muted-foreground space-y-1">
                  <div>{device.name}</div>
                  <div>{device.branch_name}</div>
                </section>
              )}

              {/* Forget device */}
              <section>
                <button
                  type="button"
                  onClick={handleLogout}
                  className="w-full p-3 bg-destructive text-destructive-foreground rounded min-h-12 font-medium"
                >
                  {t("settings.forget_device")}
                </button>
              </section>
            </div>
          </aside>
        </div>
      )}
    </>
  );
}
