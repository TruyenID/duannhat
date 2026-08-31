import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { AlertCircleIcon, ArrowLeftIcon } from "lucide-react";
import {
  Alert,
  AlertDescription,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Spinner,
  Switch,
} from "@godxjp/ui";
import { PosHeader } from "@/app/pos/components/pos-header";
import { VersionCard } from "./version-card";
import { HelpButton } from "@/help/help-button";
import { getHelpTopic } from "@/help";
import { HELP_SETTINGS_GROUPS } from "@/help/types";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useAuth } from "@/providers/use-auth";
import {
  useShopOrderSettings,
  useUpdateCloseReportToggles,
} from "@/hooks/api/use-shop-order-settings";
import {
  CLOSE_REPORT_TOGGLE_KEYS,
  type CloseReportToggleKey,
} from "@/services/shop-order-settings-service";

/**
 * Order the toggles top-to-bottom the way the sections appear on the printed
 * 精算 (close) slip, so the screen reads like the paper. Every key is still
 * sourced from CLOSE_REPORT_TOGGLE_KEYS below — a new toggle added to the
 * service that isn't listed here is appended rather than silently dropped, so
 * the screen can never omit a section.
 */
const SECTION_ORDER: CloseReportToggleKey[] = [
  "close_report_tax_breakdown", // 売上内訳 / 消費税内訳 (per-rate)
  "close_report_payment_methods", // 支払方法
  "close_report_service_charge", // サービス料
  "close_report_drawer_check", // レジ点検
  "close_report_denominations", // 金種
];

// Render every canonical toggle: report order first, then any not-yet-ordered
// key appended so the list is always complete.
const ORDERED_KEYS: CloseReportToggleKey[] = [
  ...SECTION_ORDER,
  ...CLOSE_REPORT_TOGGLE_KEYS.filter((k) => !SECTION_ORDER.includes(k)),
];

type ToggleView = Partial<Record<CloseReportToggleKey, boolean>>;

/**
 * Cashier-terminal settings — currently the 精算 close-report section toggles.
 *
 * The Switch reads `pending[key] ?? server ?? true`. A controlled Radix Switch
 * only moves when its `checked` prop changes, so binding it straight to an
 * async cache write left it stuck (it flipped only after a round-trip, and a
 * racing refetch could revert it — the "toast but no move" bug). A key sits in
 * `pending` ONLY between the click and the mutation settling: the switch flips
 * synchronously on click, the mutation persists + reconciles the cache in the
 * background, then `pending` clears so the server value wins (and a later
 * admin-web edit still shows through). No effect / no state mirror.
 */
export function SettingsPage() {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { device } = useAuth();

  const settings = useShopOrderSettings(shopSlug);
  const mutation = useUpdateCloseReportToggles(shopSlug);

  const data = settings.data?.data;
  const shopName = device?.branch_name ?? "—";

  // Optimistic overrides, one key per in-flight toggle. Cleared on settle.
  const [pending, setPending] = useState<ToggleView>({});

  function handleToggle(key: CloseReportToggleKey, value: boolean) {
    setPending((p) => ({ ...p, [key]: value })); // instant switch movement
    mutation.mutate(
      { [key]: value },
      {
        onSuccess: () => toast.success(t("settings.close_report.saved")),
        onError: (err) => {
          // plan-043 audit fix 1.5/A1 — the tax-breakdown section is an audit
          // control: the backend only lets a signed-in USER flip it (403
          // TAX_BREAKDOWN_TOGGLE_FORBIDDEN for anonymous device tokens). Map
          // that to a clear "manager only" message instead of a raw error;
          // onSettled below rolls the switch back to the server value.
          const forbidden =
            err instanceof ApiError &&
            err.status === 403 &&
            err.body.code === "TAX_BREAKDOWN_TOGGLE_FORBIDDEN";
          toast.error(
            forbidden
              ? t("settings.close_report.tax_breakdown_forbidden")
              : err instanceof Error
                ? err.message
                : t("settings.close_report.save_failed"),
          );
        },
        // Drop the override once the write settles: on success the cache now
        // holds the confirmed value; on error it falls back to the (unchanged)
        // server value, rolling the switch back.
        onSettled: () =>
          setPending((p) => {
            const next = { ...p };
            delete next[key];
            return next;
          }),
      },
    );
  }

  return (
    <div className="min-h-screen bg-muted/30 text-foreground">
      <PosHeader
        shopName={shopName}
        breadcrumb={{
          parent: t("settings.breadcrumb.parent"),
          current: t("settings.close_report.title"),
        }}
        helpTopic="settings"
      />
      <main className="mx-auto max-w-3xl px-4 py-6 sm:px-6 sm:py-8">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate(`/shop/${shopSlug}`)}
          className="group mb-5 h-9 gap-2 rounded-full border border-border/60 bg-background pl-1.5 pr-4 text-[13px] font-medium text-muted-foreground shadow-sm transition-all hover:border-border/80 hover:bg-muted/40 hover:text-foreground hover:shadow"
        >
          <span className="flex size-6 items-center justify-center rounded-full bg-muted text-muted-foreground transition-all duration-200 group-hover:-translate-x-0.5 group-hover:bg-primary group-hover:text-primary-foreground">
            <ArrowLeftIcon className="size-3.5" />
          </span>
          {t("settings.back")}
        </Button>
        <header className="mb-5">
          <h1 className="text-[22px] font-semibold leading-tight">
            {t("settings.close_report.title")}
          </h1>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t("settings.close_report.description")}
          </p>
        </header>

        {settings.isLoading ? (
          <div className="flex items-center justify-center py-16">
            <Spinner className="size-6" />
          </div>
        ) : settings.isError ? (
          <Alert
            variant="destructive"
            className="border-destructive/40 bg-destructive/5"
          >
            <AlertCircleIcon className="size-4" />
            <AlertDescription className="flex items-center justify-between gap-3">
              <span>{t("settings.close_report.error_loading")}</span>
              <Button
                variant="outline"
                size="sm"
                onClick={() => settings.refetch()}
              >
                {t("settings.close_report.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : (
          /* `gap-0 p-0` + explicit padding on every slot — the same shape the
             shift screens use, and for a reason that is invisible otherwise:
             `@godxjp/ui`'s Card pads itself with `px-card` / `pt-card` /
             `gap-card`, utilities built on `--spacing-card`, which lives in
             that package's `theme.css`. pos-web does not import it, so those
             classes generate NOTHING and the card renders with its text flush
             against the border. Every other Card in this app already passes
             explicit padding; these two were the only ones trusting the token. */
          <Card className="gap-0 p-0" data-slot="close-report-settings">
            <CardHeader className="border-b px-5 py-4">
              <CardTitle className="text-[15px] font-semibold">
                {t("settings.close_report.menu_item")}
              </CardTitle>
            </CardHeader>
            <CardContent className="divide-y p-0">
              {ORDERED_KEYS.map((key) => {
                const checked = pending[key] ?? data?.[key] ?? true;
                return (
                  <div
                    key={key}
                    className="flex items-center justify-between gap-4 px-5 py-4"
                  >
                    <div className="min-w-0">
                      <div className="text-[14px] font-medium text-foreground">
                        {t(`settings.close_report.${key}`)}
                      </div>
                      <div className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                        {t(`settings.close_report.${key}_desc`)}
                      </div>
                    </div>
                    <Switch
                      checked={checked}
                      onCheckedChange={(v) => handleToggle(key, v)}
                      aria-label={t(`settings.close_report.${key}`)}
                    />
                  </div>
                );
              })}
            </CardContent>
          </Card>
        )}

        <p className="mt-4 text-[12px] leading-relaxed text-muted-foreground">
          {t("settings.close_report.hint")}
        </p>

        <OtherShopSettingsCard />
        <VersionCard />
      </main>
    </div>
  );
}

/**
 * The settings that are NOT here.
 *
 * This screen owns five toggles; the shop has around thirty more — quick order,
 * the status a new item is born in, currency, denominations, the void matrix,
 * the tax rules — and every one of them changes what the POS shows or permits.
 * They live in admin-web, and nothing in pos-web said so, so the honest answer
 * to "why can't I edit this item" was only reachable by already knowing where
 * to look.
 *
 * Deliberately a signpost, not a control: pos-web may write exactly the five
 * close-report toggles above (the backend refuses the rest), so offering inputs
 * here would be offering something that 403s. Each row opens the guide for that
 * group instead — what it does, where it is, and what breaks when it changes.
 */
function OtherShopSettingsCard() {
  const { t, locale } = useTranslation();

  return (
    /* Same padding shape as the card above — see the note there for why the
       library's own `px-card` cannot be relied on here. */
    <Card className="mt-6 gap-0 p-0" data-slot="other-shop-settings">
      <CardHeader className="border-b px-5 py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0 space-y-1">
            <CardTitle className="text-[15px] font-semibold">
              {t("settings.other.title")}
            </CardTitle>
            {/* `max-w-prose`: the description is two sentences of running text
                and the card is 768px wide, so an unconstrained line runs from
                edge to edge and reads like a wall. */}
            <p className="max-w-prose text-[12px] leading-relaxed text-muted-foreground">
              {t("settings.other.description")}
            </p>
          </div>
          {/* The map of the whole thing — which group holds what, and which
              changes the server refuses while a shift is open. `-mr-1.5`
              pulls the icon's own optical padding back so it lines up with
              the row buttons below rather than sitting further right. */}
          <HelpButton
            topic="shop-settings"
            className="-mr-1.5 size-8 shrink-0"
          />
        </div>
      </CardHeader>
      <CardContent className="divide-y p-0">
        {HELP_SETTINGS_GROUPS.map((id) => {
          const topic = getHelpTopic(id, locale);
          return (
            <div
              key={id}
              className="flex items-center justify-between gap-4 px-5 py-4"
            >
              <div className="min-w-0">
                <div className="text-[14px] font-medium text-foreground">
                  {topic.title}
                </div>
                <div className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                  {topic.summary}
                </div>
              </div>
              <HelpButton topic={id} className="-mr-1.5 size-8 shrink-0" />
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}
