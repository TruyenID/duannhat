import { Button } from "@godxjp/ui";

interface Props {
  error: Error;
  reset: () => void;
}

const STRINGS: Record<string, { title: string; desc: string; retry: string; reload: string }> = {
  vi: {
    title: "Đã xảy ra lỗi",
    desc: "Trang gặp sự cố. Nhấn \"Thử lại\" hoặc tải lại trang.",
    retry: "Thử lại",
    reload: "Tải lại trang",
  },
  en: {
    title: "Something went wrong",
    desc: 'The page encountered an error. Press "Try again" or reload the page.',
    retry: "Try again",
    reload: "Reload page",
  },
  ja: {
    title: "エラーが発生しました",
    desc: "ページでエラーが発生しました。「再試行」または再読み込みしてください。",
    retry: "再試行",
    reload: "再読み込み",
  },
};

/**
 * Self-contained fallback — does NOT depend on AppProvider/useTranslation,
 * because the outermost ErrorBoundary catches provider crashes too.
 * Reads locale directly from localStorage with a safe default.
 */
function getStrings() {
  try {
    const stored = localStorage.getItem("pos_locale");
    if (stored && stored in STRINGS) return STRINGS[stored];
  } catch {
    // localStorage unavailable (private mode, embedded webview) — fall through
  }
  return STRINGS.vi;
}

export function ErrorFallback({ error, reset }: Props) {
  const t = getStrings();
  return (
    <div className="min-h-screen flex items-center justify-center p-6 bg-background">
      <div className="max-w-md w-full text-center space-y-4">
        <h1 className="text-2xl font-bold">{t.title}</h1>
        <p className="text-muted-foreground">{t.desc}</p>
        {import.meta.env.DEV && (
          <pre className="text-left text-xs bg-muted p-3 rounded overflow-auto max-h-48">
            {error.message}
            {"\n"}
            {error.stack}
          </pre>
        )}
        <div className="flex gap-2 justify-center">
          <Button onClick={reset}>{t.retry}</Button>
          <Button variant="outline" onClick={() => window.location.reload()}>
            {t.reload}
          </Button>
        </div>
      </div>
    </div>
  );
}
