"use client";

import { useState, useEffect } from "react";
import { useParams } from "next/navigation";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  Banknote,
  CreditCard,
  ArrowLeftRight,
  Wallet,
  Receipt,
  InfoIcon,
  LockIcon,
  TriangleAlertIcon,
} from "lucide-react";
import { formatDateTime } from "@/lib/date";
import { useShopTimezone } from "@/providers/shop-timezone-provider";
import { apiFetch, ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { shopMenuKeys } from "@/hooks/api/query-keys";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTaxTypeLookup } from "@/hooks/api/use-tax-types";
import { HelpPanel } from "@/components/shared/help-panel";
import { DenominationsTab } from "./components/denominations-tab";
import { PointEarnTab } from "./components/point-earn-tab";
import { TenderTypesTab } from "./components/tender-types-tab";
import {
  DEFAULT_STOCK_DEDUCTION_TIMING,
  STOCK_DEDUCTION_TIMINGS,
  VOID_MATRIX_STATUSES,
  deriveLegacyItemEditFlag,
  resolveServerVoidableStatuses,
  sameStatusList,
  type StockDeductionTiming,
  type VoidMatrixStatus,
} from "./lib/void-matrix";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  Input,
  Label,
  RadioGroup,
  RadioGroupItem,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type SplitBillRoundingMode = "auto" | "integer" | "two_decimals" | "none";

// plan-045 rev-B — per-shop tax-rounding rule, snapshotted immutably onto each
// new order. Three directions: `round` (四捨五入), `ceil` (切り上げ), `floor`
// (切り捨て); decimals (0–3) set the precision.
type TaxRoundingMode = "round" | "ceil" | "floor";

// #491 — table status a table auto-returns to after a paid order closes.
// null (shop) = inherit the HQ brand default.
type TableStatusAfterPayment = "free" | "cleaning";

// plan-051 (#1149 / #1150) — the void matrix + stock-deduction timing rules
// live in `./lib/void-matrix` so they can be unit-tested without mounting this
// 2 200-line screen. `pending` is a hard floor there (always voidable, checkbox
// checked+disabled here).

// Ngôn ngữ phiếu in. Đúng tập locale toàn hệ thống (omnify.yaml
// `locale.locales`); backend validate lại bằng Rule::in nên FE và BE không
// thể lệch nhau.
type PrintLabelLocale = "ja" | "en" | "vi";

const PRINT_LABEL_LOCALES: PrintLabelLocale[] = ["ja", "en", "vi"];

interface OrderSettingsStatus {
  value: string;
  label: string;
  description: string;
}

interface OrderSettingsCurrency {
  code: string;
  label: string;
  symbol: string;
}

interface OrderSettingsData {
  default_order_item_status: string | null;
  enable_quick_order: boolean;
  service_charge_rate: string;
  // Item-edit policy (legacy #1148 flag) — true = void items in any status;
  // false (default) = pending-only. Kept one release as the fallback source
  // for the plan-051 matrix below.
  allow_item_edit_any_status: boolean;
  // plan-051 (#1149) — per-status void matrix. Raw column (null = legacy-flag
  // fallback) + server-RESOLVED effective list. Optional: the settings
  // serializer may not emit them yet (backend gap) — resolution then falls
  // back to allow_item_edit_any_status.
  item_voidable_statuses?: string[] | null;
  effective_item_voidable_statuses?: string[];
  // plan-051 (#1150) — per-shop stock deduction timing (default on_close).
  stock_deduction_timing?: StockDeductionTiming;
  // #1152 — print the resolved 登録番号 on receipts (default ON).
  show_seller_registration_on_receipt: boolean;
  // #876 — Handy may settle an order directly at the table (default OFF).
  handy_allow_direct_payment: boolean;
  // #2806 — pay-at-counter channel on customer-web, and its kiosk QR. Both
  // default ON; a branch with no settings row reads as ON on the server too.
  counter_pay_enabled: boolean;
  counter_pay_show_qr: boolean;
  // plan-043 — 税 / Tax
  default_tax_type_id: string | null;
  prices_include_tax: boolean;
  service_charge_tax_rate: string;
  close_report_tax_breakdown: boolean;
  // plan-045 — tax rounding. `tax_rounding_decimals` null = derive step from
  // currency (legacy behaviour).
  tax_rounding_mode: TaxRoundingMode;
  tax_rounding_decimals: number | null;
  currency_code: string;
  split_bill_rounding_mode: SplitBillRoundingMode;
  // plan-035 — null = inherit BrandOrderPolicy default; bool = override.
  prep_before_payment: boolean | null;
  customer_email_required: boolean;
  // plan-037 — null = inherit BrandOrderPolicy.default_confirmation_timeout_minutes
  confirmation_timeout_minutes: number | null;
  // #1160 — phút chuẩn bị cho MỖI món. null = inherit brand default; the
  // resolved value the customer ETA actually uses rides alongside so the form
  // can show "Theo HQ (5 phút/món)" without a second request.
  prep_minutes_per_item: number | null;
  effective_prep_minutes_per_item: number;
  // #491 — raw shop value (null = inherit HQ) + resolved effective value.
  table_status_after_payment: TableStatusAfterPayment | null;
  effective_table_status_after_payment: TableStatusAfterPayment;
  // Ngôn ngữ của MỌI phiếu in tại quán (vé bếp / hold / hoá đơn).
  // null = chưa cấu hình → workstation tự fallback về mặc định chi nhánh.
  print_label_locale: PrintLabelLocale | null;
  available_statuses: OrderSettingsStatus[];
  available_currencies: OrderSettingsCurrency[];
}

interface OrderSettingsPatchBody {
  default_order_item_status?: string | null;
  enable_quick_order?: boolean;
  service_charge_rate?: number;
  allow_item_edit_any_status?: boolean;
  // plan-051 — void matrix + stock timing.
  item_voidable_statuses?: string[];
  stock_deduction_timing?: StockDeductionTiming;
  show_seller_registration_on_receipt?: boolean;
  handy_allow_direct_payment?: boolean;
  counter_pay_enabled?: boolean;
  counter_pay_show_qr?: boolean;
  // plan-043 — 税 / Tax
  default_tax_type_id?: string | null;
  prices_include_tax?: boolean;
  service_charge_tax_rate?: number;
  close_report_tax_breakdown?: boolean;
  tax_rounding_mode?: TaxRoundingMode;
  tax_rounding_decimals?: number | null;
  currency_code?: string;
  split_bill_rounding_mode?: SplitBillRoundingMode;
  prep_before_payment?: boolean | null;
  customer_email_required?: boolean;
  confirmation_timeout_minutes?: number | null;
  prep_minutes_per_item?: number | null;
  table_status_after_payment?: TableStatusAfterPayment | null;
  print_label_locale?: PrintLabelLocale | null;
}

interface OrderSettingsResponse {
  data: OrderSettingsData;
}

interface BranchSettingsData {
  cart_timeout_minutes: number | null;
  hq_brand_timeout_minutes: number | null;
  effective_timeout_minutes: number | null;
  takeaway_payment_timeout_minutes: number | null;
  hq_brand_takeaway_payment_timeout_minutes: number | null;
  effective_takeaway_payment_timeout_minutes: number | null;
  // #1152 — インボイス T+13: shop override ?? brand default.
  invoice_registration_number: string | null;
  hq_brand_invoice_registration_number: string | null;
  effective_invoice_registration_number: string | null;
}

interface BranchSettingsResponse {
  data: BranchSettingsData;
}

interface PaymentMethodData {
  id: string;
  code: string;
  name: string;
  is_active: boolean;
  sort_order: number;
  branch_id: string | null;
  organization_id: string;
}

interface PaymentMethodsResponse {
  data: PaymentMethodData[];
}

/**
 * Một lựa chọn thanh toán đã được RESOLVER quyết định (#1895).
 *
 * Trước đây tab này đọc `/shops/{slug}/payment-methods` — danh sách hàng
 * `payment_methods` đã CẤU HÌNH. Route đó đã bị xoá; nguồn thay thế là
 * `/shops/{slug}/effective-payment-options`, trả về thứ shop THẬT SỰ nhận
 * được sau khi đi qua thang quyết định (ownership → connection → provider →
 * capability → chính sách HQ → shop → thiết bị).
 *
 * Khác biệt đáng kể, không phải đổi tên trường: danh sách cũ trả cả phương
 * thức đang tắt (`include_inactive=true`); danh sách mới chỉ nói cái gì có
 * hiệu lực và VÌ SAO (`source`).
 */
interface EffectivePaymentOption {
  id: string;
  display_name: string;
  provider: string | null;
  rail: string | null;
  method_type: string | null;
  effective: boolean;
  source: string | null;
}

interface EffectivePaymentOptionsResponse {
  data: {
    options: EffectivePaymentOption[];
  };
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const orderSettingsKeys = {
  get: (shopSlug: string) => ["shop", shopSlug, "settings", "order"] as const,
};

const effectivePaymentOptionKeys = {
  list: (shopSlug: string) => ["shop", shopSlug, "effective-payment-options"] as const,
};

const branchSettingsKeys = {
  get: (shopSlug: string) => ["shop", shopSlug, "settings", "branch"] as const,
};

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function SettingsPage() {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const { t } = useTranslation();

  return (
    <>
      <PageHeader title={t("settings.page.title")} description={t("settings.page.description")}>
        <HelpPanel
          title={t("settings.page.title")}
          subtitle={t("help.panel.shop_settings.subtitle")}
          purpose={t("help.panel.shop_settings.purpose")}
          usage={[
            t("help.panel.shop_settings.usage.1"),
            t("help.panel.shop_settings.usage.2"),
            t("help.panel.shop_settings.usage.3"),
            t("help.panel.shop_settings.usage.4"),
          ]}
          checks={[
            t("help.panel.shop_settings.checks.1"),
            t("help.panel.shop_settings.checks.2"),
            t("help.panel.shop_settings.checks.3"),
            t("help.panel.shop_settings.checks.4"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_settings.glossary.two_payment_tabs.term"),
              description: t("help.panel.shop_settings.glossary.two_payment_tabs.desc"),
            },
            {
              term: t("help.panel.shop_settings.glossary.use_hq.term"),
              description: t("help.panel.shop_settings.glossary.use_hq.desc"),
            },
            {
              term: t("help.panel.shop_settings.glossary.tax_inclusive.term"),
              description: t("help.panel.shop_settings.glossary.tax_inclusive.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <Tabs defaultValue="order" className="w-full">
          <TabsList className="mb-4">
            <TabsTrigger value="order">{t("settings.tabs.order")}</TabsTrigger>
            <TabsTrigger value="payment">{t("settings.tabs.payment")}</TabsTrigger>
            <TabsTrigger value="cart-timeout">{t("shop.settings.timeout.tab_label")}</TabsTrigger>
            <TabsTrigger value="takeaway-payment">
              {t("shop.settings.takeaway_payment.tab_label")}
            </TabsTrigger>
            <TabsTrigger value="point-earn">{t("shop.settings.point_earn.tab_label")}</TabsTrigger>
            <TabsTrigger value="denominations">{t("settings.denomination.tab_label")}</TabsTrigger>
            <TabsTrigger value="tender-types">{t("settings.tender_type.tab_label")}</TabsTrigger>
          </TabsList>

          <TabsContent value="order" className="mt-0">
            <OrderSettingsTab shopSlug={shopSlug} />
          </TabsContent>

          <TabsContent value="payment" className="mt-0">
            <PaymentMethodsTab shopSlug={shopSlug} />
          </TabsContent>

          <TabsContent value="cart-timeout" className="mt-0">
            <CartTimeoutTab shopSlug={shopSlug} />
          </TabsContent>

          <TabsContent value="takeaway-payment" className="mt-0">
            <TakeawayPaymentTimeoutTab shopSlug={shopSlug} />
          </TabsContent>

          <TabsContent value="point-earn" className="mt-0">
            <PointEarnTab shopSlug={shopSlug} />
          </TabsContent>

          <TabsContent value="denominations" className="mt-0">
            <DenominationsTab shopSlug={shopSlug} />
          </TabsContent>

          <TabsContent value="tender-types" className="mt-0">
            <TenderTypesTab shopSlug={shopSlug} />
          </TabsContent>
        </Tabs>
      </PageContent>
    </>
  );
}

// ---------------------------------------------------------------------------
// Order settings tab
// ---------------------------------------------------------------------------

function OrderSettingsTab({ shopSlug }: { shopSlug: string }) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: timestamps belong to the shop's clock, not the
  // viewer's browser (#1248). Null outside a shop route, which makes
  // formatDateTime fall back to the old behaviour.
  const shopTimezone = useShopTimezone();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: orderSettingsKeys.get(shopSlug),
    queryFn: () => apiFetch<OrderSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/order`),
    staleTime: 60 * 1000,
    retry: false,
  });

  // Pre-flight check: is there an open cashier shift at this branch? If so,
  // the backend will block any currency change (CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT
  // 409) — surface that state up front so the admin doesn't bounce off the
  // PATCH. 60s poll + refetchOnFocus picks up the cashier's close in near
  // real time without spamming the API.
  const tillStatusQuery = useQuery({
    queryKey: ["shop-till-status", shopSlug],
    queryFn: () =>
      apiFetch<{
        data: {
          has_open_shift: boolean;
          /** #1130 — plan-046 R8: chain awaiting continuation after a handover. */
          has_open_chain?: boolean;
          open_session: {
            id: string;
            session_code: string;
            opened_at: string | null;
            opener_name: string | null;
            opened_by_id: string | null;
            default_currency_code: string;
          } | null;
        };
      }>(`/api/v1/shops/${shopSlug}/till/current`),
    enabled: !!shopSlug,
    refetchOnWindowFocus: true,
    refetchInterval: 60 * 1000,
    retry: false,
  });

  // #1130 — the backend guards 409 on open shift OR open chain (the window
  // after a handover, before the next open). Locking the controls on the same
  // predicate keeps the UI from enabling a control the PATCH will reject.
  const hasOpenShift =
    (tillStatusQuery.data?.data.has_open_shift ?? false) ||
    (tillStatusQuery.data?.data.has_open_chain ?? false);
  const openSession = tillStatusQuery.data?.data.open_session ?? null;

  // plan-043 — the tax-type lookup is brand-scoped, so resolve the shop's brand
  // slug first (cached shop-info row, shared with the shop layout).
  const shopInfoQuery = useQuery({
    queryKey: ["shop", "info", shopSlug],
    queryFn: () => apiFetch<{ data: { brand_slug?: string | null } }>(`/api/v1/shops/${shopSlug}`),
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
  const brandSlug = shopInfoQuery.data?.data.brand_slug ?? "";
  const taxTypeLookup = useTaxTypeLookup(brandSlug);
  const taxTypes = taxTypeLookup.data?.data ?? [];

  const settings = data?.data;

  const [selectedStatus, setSelectedStatus] = useState<string | null>(null);
  const [quickOrder, setQuickOrder] = useState<boolean>(false);
  // plan-051 — per-status void matrix (pending always in) + stock timing.
  const [voidableStatuses, setVoidableStatuses] = useState<VoidMatrixStatus[]>(["pending"]);
  const [stockDeductionTiming, setStockDeductionTiming] = useState<StockDeductionTiming>(
    DEFAULT_STOCK_DEDUCTION_TIMING
  );
  const [showSellerRegistration, setShowSellerRegistration] = useState<boolean>(true);
  const [handyAllowDirectPayment, setHandyAllowDirectPayment] = useState<boolean>(false);
  // #2806 — seeded true so a slow settings fetch never renders the switches in
  // the OFF position for a beat; OFF is the destructive reading here.
  const [counterPayEnabled, setCounterPayEnabled] = useState<boolean>(true);
  const [counterPayShowQr, setCounterPayShowQr] = useState<boolean>(true);
  const [serviceChargeRate, setServiceChargeRate] = useState<string>("0");
  // plan-043 — 税 / Tax section. "" = no default tax type selected.
  const [defaultTaxTypeId, setDefaultTaxTypeId] = useState<string>("");
  const [pricesIncludeTax, setPricesIncludeTax] = useState<boolean>(false);
  const [serviceChargeTaxRate, setServiceChargeTaxRate] = useState<string>("0");
  const [closeReportTaxBreakdown, setCloseReportTaxBreakdown] = useState<boolean>(true);
  const [currencyCode, setCurrencyCode] = useState<string>("VND");
  // Ngôn ngữ phiếu in. "" = chưa cấu hình (gửi null lên BE) → workstation
  // fallback về mặc định chi nhánh.
  const [printLabelLocale, setPrintLabelLocale] = useState<string>("");
  const [splitBillRoundingMode, setSplitBillRoundingMode] = useState<SplitBillRoundingMode>("auto");
  // plan-045 rev-B — tax rounding. Decimals is a plain integer 0–3 (the "auto"/
  // currency-step option was dropped); modelled as a string select value.
  const [taxRoundingMode, setTaxRoundingMode] = useState<TaxRoundingMode>("round");
  const [taxRoundingDecimals, setTaxRoundingDecimals] = useState<string>("0");
  // plan-035 — tri-state ("use_hq" / "on" / "off") drives the
  // nullable bool sent to the BE: null / true / false.
  const [prepMode, setPrepMode] = useState<"use_hq" | "on" | "off">("use_hq");
  const [emailRequired, setEmailRequired] = useState<boolean>(false);
  // plan-037 — null = "use HQ default", number = override.
  const [confirmTimeoutMode, setConfirmTimeoutMode] = useState<"use_hq" | "custom">("use_hq");
  const [confirmTimeoutRaw, setConfirmTimeoutRaw] = useState<string>("3");
  // #1160 — phút chuẩn bị / món (null = Theo HQ).
  const [prepMinutesMode, setPrepMinutesMode] = useState<"use_hq" | "custom">("use_hq");
  const [prepMinutesRaw, setPrepMinutesRaw] = useState<string>("5");
  // #491 — tri-state ("use_hq" / "free" / "cleaning") drives the nullable
  // enum sent to the BE: null / "free" / "cleaning".
  const [tableStatusMode, setTableStatusMode] = useState<"use_hq" | TableStatusAfterPayment>(
    "use_hq"
  );

  useEffect(() => {
    if (settings !== undefined) {
      setSelectedStatus(settings.default_order_item_status);
      setQuickOrder(settings.enable_quick_order);
      setVoidableStatuses(resolveServerVoidableStatuses(settings));
      setStockDeductionTiming(settings.stock_deduction_timing ?? DEFAULT_STOCK_DEDUCTION_TIMING);
      setShowSellerRegistration(settings.show_seller_registration_on_receipt ?? true);
      setHandyAllowDirectPayment(settings.handy_allow_direct_payment ?? false);
      setCounterPayEnabled(settings.counter_pay_enabled ?? true);
      setCounterPayShowQr(settings.counter_pay_show_qr ?? true);
      setServiceChargeRate(settings.service_charge_rate ?? "0");
      setDefaultTaxTypeId(settings.default_tax_type_id ?? "");
      setPricesIncludeTax(settings.prices_include_tax ?? false);
      setServiceChargeTaxRate(settings.service_charge_tax_rate ?? "0");
      setCloseReportTaxBreakdown(settings.close_report_tax_breakdown ?? true);
      setCurrencyCode(settings.currency_code ?? "VND");
      setPrintLabelLocale(settings.print_label_locale ?? "");
      setSplitBillRoundingMode(
        (settings.split_bill_rounding_mode ?? "auto") as SplitBillRoundingMode
      );
      setTaxRoundingMode((settings.tax_rounding_mode ?? "round") as TaxRoundingMode);
      setTaxRoundingDecimals(
        settings.tax_rounding_decimals == null ? "0" : String(settings.tax_rounding_decimals)
      );
      setPrepMode(
        settings.prep_before_payment === null
          ? "use_hq"
          : settings.prep_before_payment
            ? "on"
            : "off"
      );
      setEmailRequired(settings.customer_email_required ?? false);
      const ct = settings.confirmation_timeout_minutes;
      if (ct === null || ct === undefined) {
        setConfirmTimeoutMode("use_hq");
        setConfirmTimeoutRaw("3");
      } else {
        setConfirmTimeoutMode("custom");
        setConfirmTimeoutRaw(String(ct));
      }
      // #1160 — seed the custom input with the RESOLVED value so switching to
      // "Tự chọn" starts from what the shop is effectively using today.
      const prep = settings.prep_minutes_per_item;
      if (prep === null || prep === undefined) {
        setPrepMinutesMode("use_hq");
        setPrepMinutesRaw(String(settings.effective_prep_minutes_per_item ?? 5));
      } else {
        setPrepMinutesMode("custom");
        setPrepMinutesRaw(String(prep));
      }
      setTableStatusMode(settings.table_status_after_payment ?? "use_hq");
    }
  }, [settings]);

  const fallbackCurrencies: OrderSettingsCurrency[] = [
    { code: "VND", label: "Vietnamese Đồng (VND)", symbol: "₫" },
    { code: "JPY", label: "Japanese Yen (JPY)", symbol: "¥" },
    { code: "USD", label: "US Dollar (USD)", symbol: "$" },
    { code: "EUR", label: "Euro (EUR)", symbol: "€" },
  ];

  const currencies = settings?.available_currencies?.length
    ? settings.available_currencies
    : fallbackCurrencies;

  const saveMutation = useMutation({
    mutationFn: (body: OrderSettingsPatchBody) =>
      apiFetch<OrderSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/order`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: orderSettingsKeys.get(shopSlug) });
      toast.success(t("toast.settings.saved"));
    },
    onError: (err) => {
      // Mid-shift currency guard. Backend returns 409 with a structured code;
      // we translate it here AND roll back the local currencyCode dropdown
      // so the form doesn't show a dirty value the server refused.
      if (err instanceof ApiError && err.status === 409) {
        const code = (err.body as { code?: string })?.code;
        if (code === "CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT") {
          toast.error(t("settings.order.currency_blocked_open_shift"), {
            description: t("settings.order.currency_blocked_open_shift_hint"),
          });
          // Revert local state to the server-known value.
          setCurrencyCode(settings?.currency_code ?? "VND");
          return;
        }
        // plan-043 — mid-shift 税込/税別 flip guard. Revert the toggle to the
        // server-known value so the form doesn't show a value the server refused.
        if (code === "TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT") {
          toast.error(t("settings.order.tax_mode_blocked_open_shift"), {
            description: t("settings.order.tax_mode_blocked_open_shift_hint"),
          });
          setPricesIncludeTax(settings?.prices_include_tax ?? false);
          return;
        }
        // plan-045 — mid-shift tax-rounding guard. Revert both rounding controls
        // to the server-known values so the form doesn't show a refused change.
        if (code === "TAX_ROUNDING_LOCKED_OPEN_SHIFT") {
          toast.error(t("settings.order.tax_rounding_blocked_open_shift"), {
            description: t("settings.order.tax_rounding_blocked_open_shift_hint"),
          });
          setTaxRoundingMode((settings?.tax_rounding_mode ?? "round") as TaxRoundingMode);
          setTaxRoundingDecimals(
            settings?.tax_rounding_decimals == null ? "0" : String(settings.tax_rounding_decimals)
          );
          return;
        }
      }
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("common.failed_to_load")
      );
    },
  });

  const fallbackStatuses: OrderSettingsStatus[] = [
    { value: "pending", label: t("settings.order.status_pending"), description: "" },
    { value: "preparing", label: t("settings.order.status_preparing"), description: "" },
    { value: "ready", label: t("settings.order.status_ready"), description: "" },
    { value: "served", label: t("settings.order.status_served"), description: "" },
  ];

  const statuses = settings?.available_statuses?.length
    ? settings.available_statuses
    : fallbackStatuses;

  const currentPrepMode: "use_hq" | "on" | "off" =
    settings?.prep_before_payment === undefined || settings?.prep_before_payment === null
      ? "use_hq"
      : settings.prep_before_payment
        ? "on"
        : "off";

  const confirmTimeoutNum = parseInt(confirmTimeoutRaw, 10);
  const confirmTimeoutError =
    confirmTimeoutMode === "custom" &&
    (Number.isNaN(confirmTimeoutNum) || confirmTimeoutNum < 1 || confirmTimeoutNum > 30)
      ? t("settings.order.confirmation_timeout_range_error", { min: "1", max: "30" })
      : "";
  const serverConfirmTimeout = settings?.confirmation_timeout_minutes ?? null;

  // #1160 — 0 is legitimate (a shop handing over pre-made goods), so the
  // guard is a range check, not a truthiness check.
  const prepMinutesNum = parseInt(prepMinutesRaw, 10);
  const prepMinutesError =
    prepMinutesMode === "custom" &&
    (Number.isNaN(prepMinutesNum) || prepMinutesNum < 0 || prepMinutesNum > 120)
      ? t("settings.order.prep_minutes_range_error", { min: "0", max: "120" })
      : "";
  const serverPrepMinutes = settings?.prep_minutes_per_item ?? null;
  const isDirty =
    settings !== undefined &&
    (selectedStatus !== settings.default_order_item_status ||
      quickOrder !== settings.enable_quick_order ||
      !sameStatusList(voidableStatuses, resolveServerVoidableStatuses(settings)) ||
      stockDeductionTiming !==
        (settings.stock_deduction_timing ?? DEFAULT_STOCK_DEDUCTION_TIMING) ||
      showSellerRegistration !== (settings.show_seller_registration_on_receipt ?? true) ||
      handyAllowDirectPayment !== (settings.handy_allow_direct_payment ?? false) ||
      counterPayEnabled !== (settings.counter_pay_enabled ?? true) ||
      counterPayShowQr !== (settings.counter_pay_show_qr ?? true) ||
      Number(serviceChargeRate) !== Number(settings.service_charge_rate ?? "0") ||
      defaultTaxTypeId !== (settings.default_tax_type_id ?? "") ||
      pricesIncludeTax !== (settings.prices_include_tax ?? false) ||
      Number(serviceChargeTaxRate) !== Number(settings.service_charge_tax_rate ?? "0") ||
      closeReportTaxBreakdown !== (settings.close_report_tax_breakdown ?? true) ||
      currencyCode !== (settings.currency_code ?? "VND") ||
      splitBillRoundingMode !==
        ((settings.split_bill_rounding_mode ?? "auto") as SplitBillRoundingMode) ||
      taxRoundingMode !== ((settings.tax_rounding_mode ?? "round") as TaxRoundingMode) ||
      Number(taxRoundingDecimals) !== (settings.tax_rounding_decimals ?? 0) ||
      prepMode !== currentPrepMode ||
      emailRequired !== (settings.customer_email_required ?? false) ||
      (confirmTimeoutMode === "use_hq" ? null : confirmTimeoutNum) !== serverConfirmTimeout ||
      (prepMinutesMode === "use_hq" ? null : prepMinutesNum) !== serverPrepMinutes ||
      (tableStatusMode === "use_hq" ? null : tableStatusMode) !==
        (settings.table_status_after_payment ?? null) ||
      // "" (Theo mặc định chi nhánh) ↔ server null — compare in the same
      // shape the save handler sends, else picking only a print language
      // leaves the form "clean" and the Save button stays disabled.
      printLabelLocale !== (settings.print_label_locale ?? ""));

  if (error) {
    throw error;
  }

  return (
    <div className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.default_item_status")}</CardTitle>
          <CardDescription>{t("settings.order.default_item_status_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          {isLoading ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Spinner className="size-4" />
              {t("common.loading")}
            </div>
          ) : (
            <RadioGroup
              value={selectedStatus ?? ""}
              onValueChange={(val) => setSelectedStatus(val === "" ? null : val)}
              className="space-y-3"
            >
              {statuses.map((status) => {
                const labelText =
                  status.value === "pending"
                    ? t("settings.order.status_pending")
                    : status.value === "preparing"
                      ? t("settings.order.status_preparing")
                      : status.value === "ready"
                        ? t("settings.order.status_ready")
                        : status.value === "served"
                          ? t("settings.order.status_served")
                          : status.label;

                return (
                  <div key={status.value} className="flex items-start gap-3">
                    <RadioGroupItem
                      value={status.value}
                      id={`status-${status.value}`}
                      className="mt-0.5 shrink-0"
                    />
                    <Label
                      htmlFor={`status-${status.value}`}
                      className="cursor-pointer leading-snug"
                    >
                      {labelText}
                    </Label>
                  </div>
                );
              })}
            </RadioGroup>
          )}
        </CardContent>
      </Card>

      {/* plan-051 (#1149) — per-status VOID MATRIX, replacing the blanket
          allow_item_edit_any_status switch. EDITS stay pending-only always
          (#1148 law); this matrix governs only which statuses can be VOIDED. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.void_matrix_title")}</CardTitle>
          <CardDescription>{t("settings.order.void_matrix_description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-3">
          {VOID_MATRIX_STATUSES.map((status) => {
            const isPendingRow = status === "pending";
            const checked = isPendingRow || voidableStatuses.includes(status);
            const labelText =
              status === "pending"
                ? t("settings.order.status_pending")
                : status === "preparing"
                  ? t("settings.order.status_preparing")
                  : status === "ready"
                    ? t("settings.order.status_ready")
                    : t("settings.order.status_served");
            return (
              <div key={status} className="flex items-start gap-3">
                <Checkbox
                  id={`voidable-${status}`}
                  checked={checked}
                  // pending is the hard floor — always voidable, never
                  // uncheckable (mirrors the backend resolver's union).
                  disabled={isPendingRow || isLoading}
                  onCheckedChange={(v) =>
                    setVoidableStatuses((prev) =>
                      v === true
                        ? VOID_MATRIX_STATUSES.filter((s) => s === status || prev.includes(s))
                        : prev.filter((s) => s !== status)
                    )
                  }
                  className="mt-0.5 shrink-0"
                />
                <div className="flex flex-col gap-0.5">
                  <Label htmlFor={`voidable-${status}`} className="cursor-pointer leading-snug">
                    {labelText}
                  </Label>
                  {isPendingRow && (
                    <p className="text-xs text-muted-foreground">
                      {t("settings.order.void_matrix_pending_hint")}
                    </p>
                  )}
                  {status === "served" && (
                    <p className="text-xs text-muted-foreground">
                      {t("settings.order.void_matrix_served_hint")}
                    </p>
                  )}
                </div>
              </div>
            );
          })}

          {/* #1148/plan-051 — PERMANENT red warning while the risky combo is
              configured: voiding an already-cooked item under on_close timing
              skips its stock deduction, so system inventory silently drifts
              above reality. Switching to on_preparing removes the drift (the
              line was already deducted; the reason's stock_effect decides the
              compensation), so the warning disappears with it. */}
          {voidableStatuses.some((s) => s !== "pending") && stockDeductionTiming === "on_close" && (
            <div
              role="alert"
              className="mt-3 flex items-start gap-2 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
              <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
              <p>{t("settings.order.void_matrix_inventory_warning")}</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* plan-051 (#1150) — stock deduction timing. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.stock_timing_title")}</CardTitle>
          <CardDescription>{t("settings.order.stock_timing_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          <RadioGroup
            value={stockDeductionTiming}
            onValueChange={(v) => setStockDeductionTiming(v as StockDeductionTiming)}
            className="space-y-3"
          >
            {STOCK_DEDUCTION_TIMINGS.map((timing) => (
              <div key={timing} className="flex items-start gap-3">
                <RadioGroupItem
                  value={timing}
                  id={`stock-timing-${timing}`}
                  className="mt-0.5 shrink-0"
                  disabled={isLoading}
                />
                <div className="flex flex-col gap-0.5">
                  <Label htmlFor={`stock-timing-${timing}`} className="cursor-pointer leading-snug">
                    {t(`settings.order.stock_timing_${timing}_label`)}
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    {t(`settings.order.stock_timing_${timing}_desc`)}
                  </p>
                </div>
              </div>
            ))}
          </RadioGroup>
        </CardContent>
      </Card>

      {/* #1152 — 登録番号 display toggle (default ON) + shop override entry. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("settings.order.seller_registration_title")}
          </CardTitle>
          <CardDescription>{t("settings.order.seller_registration_description")}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch
              id="show-seller-registration"
              checked={showSellerRegistration}
              onCheckedChange={setShowSellerRegistration}
              disabled={isLoading}
            />
            <Label htmlFor="show-seller-registration" className="cursor-pointer">
              {t("settings.order.seller_registration_toggle_label")}
            </Label>
          </div>
          <SellerRegistrationNumberField shopSlug={shopSlug} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("settings.order.handy_direct_payment_title")}
          </CardTitle>
          <CardDescription>{t("settings.order.handy_direct_payment_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          <div className="flex items-center gap-3">
            <Switch
              id="handy-allow-direct-payment"
              checked={handyAllowDirectPayment}
              onCheckedChange={setHandyAllowDirectPayment}
              disabled={isLoading}
            />
            <Label htmlFor="handy-allow-direct-payment" className="cursor-pointer">
              {t("settings.order.handy_direct_payment_label")}
            </Label>
          </div>
        </CardContent>
      </Card>

      {/* #2806 — thanh toán tại quầy. Trước đây customer-web SUY RA việc có
          chào kênh này hay không từ trạng thái cổng online, nên quán không
          đụng vào được và luật đã lật ba lần. Nay quán tự quyết. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("settings.order.counter_pay_title")}
          </CardTitle>
          <CardDescription>{t("settings.order.counter_pay_description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch
              id="counter-pay-enabled"
              checked={counterPayEnabled}
              onCheckedChange={setCounterPayEnabled}
              disabled={isLoading}
            />
            <Label htmlFor="counter-pay-enabled" className="cursor-pointer">
              {t("settings.order.counter_pay_label")}
            </Label>
          </div>

          {/* Công tắc QR nằm DƯỚI và tắt theo công tắc trên: "hiện QR" không có
              nghĩa gì khi kênh trả tại quầy đã tắt, và một switch bật nhưng vô
              hiệu lực là lời nói dối trên màn cài đặt. */}
          <div className="flex items-center gap-3">
            <Switch
              id="counter-pay-show-qr"
              checked={counterPayShowQr}
              onCheckedChange={setCounterPayShowQr}
              disabled={isLoading || !counterPayEnabled}
            />
            <Label
              htmlFor="counter-pay-show-qr"
              className={
                counterPayEnabled ? "cursor-pointer" : "cursor-not-allowed text-muted-foreground"
              }
            >
              {t("settings.order.counter_pay_qr_label")}
            </Label>
          </div>
          <p className="text-xs text-muted-foreground">
            {t("settings.order.counter_pay_qr_hint")}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.quick_order_title")}</CardTitle>
          <CardDescription>{t("settings.order.quick_order_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          <div className="flex items-center gap-3">
            <Switch
              id="enable-quick-order"
              checked={quickOrder}
              onCheckedChange={setQuickOrder}
              disabled={isLoading}
            />
            <Label htmlFor="enable-quick-order" className="cursor-pointer">
              {t("settings.order.quick_order_title")}
            </Label>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.currency_title")}</CardTitle>
          <CardDescription>{t("settings.order.currency_description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-3">
          {hasOpenShift && (
            <Alert
              variant="default"
              className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700/40 dark:bg-amber-950/30 dark:text-amber-100"
            >
              <LockIcon className="size-4 text-amber-600" />
              <AlertDescription className="space-y-1">
                <div className="font-medium">
                  {t("settings.order.currency_locked_open_shift_title")}
                </div>
                <div className="text-[12px] opacity-90">
                  {t("settings.order.currency_locked_open_shift_desc")}
                </div>
                {openSession && (
                  <div className="mt-1 font-mono text-[11px] opacity-80">
                    {t("settings.order.currency_locked_open_shift_session", {
                      code: openSession.session_code,
                      opener:
                        openSession.opener_name ??
                        t("settings.order.currency_locked_unknown_opener"),
                      at: openSession.opened_at
                        ? formatDateTime(openSession.opened_at, locale, shopTimezone)
                        : "—",
                    })}
                  </div>
                )}
                {/* Plan-032 T7.5 — manager exit door: land the operator on the
                    shifts actually blocking the currency change. Filters on
                    status, not age (#1221): the 409 fires for an open shift of
                    ANY age, so the old `?tab=stale&filter=open_overdue` link
                    showed an empty tab whenever the blocker was younger than
                    the overdue band — which it usually is. */}
                <a
                  href={`/shop/${shopSlug}/till?tab=history&status=open,closing`}
                  className="mt-2 inline-block text-[12px] font-medium underline underline-offset-2"
                >
                  {t("till_sessions.actions.view_detail")} →
                </a>
              </AlertDescription>
            </Alert>
          )}
          <div className="space-y-1.5">
            <Label htmlFor="currency-code" className="text-sm">
              {t("settings.order.currency_label")}
            </Label>
            <Select
              value={currencyCode}
              onValueChange={setCurrencyCode}
              disabled={isLoading || hasOpenShift}
            >
              <SelectTrigger id="currency-code" className="w-full sm:w-72">
                <SelectValue placeholder={t("settings.order.currency_placeholder")} />
              </SelectTrigger>
              <SelectContent>
                {currencies.map((c) => (
                  <SelectItem key={c.code} value={c.code}>
                    <span className="inline-flex items-center gap-2">
                      <span className="w-5 text-center font-semibold text-muted-foreground">
                        {c.symbol}
                      </span>
                      <span>{c.label}</span>
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{t("settings.order.currency_hint")}</p>
          </div>
        </CardContent>
      </Card>

      {/* Ngôn ngữ phiếu in — đứng riêng, không thuộc thẻ tiền tệ hay đóng ca:
          nó chi phối MỌI phiếu in tại quán (vé bếp, phiếu hold, hoá đơn),
          chứ không chỉ báo cáo kết ca. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.print_section_title")}</CardTitle>
          <CardDescription>{t("settings.order.print_section_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          <div className="space-y-1.5">
            <Label htmlFor="print-label-locale" className="text-sm">
              {t("settings.order.print_locale_label")}
            </Label>
            <Select
              value={printLabelLocale || "use_branch"}
              onValueChange={(v) => setPrintLabelLocale(v === "use_branch" ? "" : v)}
              disabled={isLoading}
            >
              <SelectTrigger id="print-label-locale" className="w-full sm:w-72">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="use_branch">
                  {t("settings.order.print_locale_use_branch")}
                </SelectItem>
                {PRINT_LABEL_LOCALES.map((code) => (
                  <SelectItem key={code} value={code}>
                    {t(`settings.order.print_locale_option_${code}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{t("settings.order.print_locale_hint")}</p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.charges_title")}</CardTitle>
          <CardDescription>{t("settings.order.charges_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="service-charge-rate" className="text-sm">
                {t("settings.order.service_charge_rate_label")}
              </Label>
              <div className="relative">
                <Input
                  id="service-charge-rate"
                  type="number"
                  inputMode="decimal"
                  min={0}
                  max={100}
                  step="0.01"
                  value={serviceChargeRate}
                  onChange={(e) => setServiceChargeRate(e.target.value)}
                  disabled={isLoading}
                  className="pr-8"
                />
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                  %
                </span>
              </div>
              <p className="text-xs text-muted-foreground">
                {t("settings.order.service_charge_rate_hint")}
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* plan-043 — 税 / Tax */}
      <Card data-slot="tax-settings-card">
        <CardHeader>
          <CardTitle className="text-base">{t("settings.order.tax_section_title")}</CardTitle>
          <CardDescription>{t("settings.order.tax_section_description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-5">
          <Alert>
            <InfoIcon className="size-4" />
            <AlertDescription>{t("settings.order.tax_off_hours_hint")}</AlertDescription>
          </Alert>

          {/* #1130 — same rich lock affordance as the Currency card: the
              tax-mode/rounding controls 409 on the same open-shift/-chain
              guard, so the user deserves the same who/when/where-to-go. */}
          {hasOpenShift && (
            <Alert
              variant="default"
              className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700/40 dark:bg-amber-950/30 dark:text-amber-100"
            >
              <LockIcon className="size-4 text-amber-600" />
              <AlertDescription className="space-y-1">
                <div className="font-medium">{t("settings.order.tax_locked_open_shift_title")}</div>
                <div className="text-[12px] opacity-90">
                  {t("settings.order.tax_locked_open_shift_desc")}
                </div>
                {openSession && (
                  <div className="mt-1 font-mono text-[11px] opacity-80">
                    {t("settings.order.currency_locked_open_shift_session", {
                      code: openSession.session_code,
                      opener:
                        openSession.opener_name ??
                        t("settings.order.currency_locked_unknown_opener"),
                      at: openSession.opened_at
                        ? formatDateTime(openSession.opened_at, locale, shopTimezone)
                        : "—",
                    })}
                  </div>
                )}
                {/* Same exit door as the currency guard above — see #1221. */}
                <a
                  href={`/shop/${shopSlug}/till?tab=history&status=open,closing`}
                  className="mt-2 inline-block text-[12px] font-medium underline underline-offset-2"
                >
                  {t("till_sessions.actions.view_detail")} →
                </a>
              </AlertDescription>
            </Alert>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="default-tax-type" className="text-sm">
              {t("settings.order.default_tax_type_label")}
            </Label>
            <Select
              value={defaultTaxTypeId || "__none__"}
              onValueChange={(v) => setDefaultTaxTypeId(v === "__none__" ? "" : v)}
              disabled={isLoading || taxTypeLookup.isLoading}
            >
              <SelectTrigger id="default-tax-type" className="w-full sm:w-72">
                <SelectValue placeholder={t("settings.order.default_tax_type_placeholder")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">
                  {t("settings.order.default_tax_type_none")}
                </SelectItem>
                {taxTypes.map((tt) => (
                  <SelectItem key={tt.id} value={tt.id}>
                    {tt.name} · {t("hq.tax_types.rate_display", { rate: String(tt.rate) })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t("settings.order.default_tax_type_hint")}
            </p>
          </div>

          <div className="space-y-3 border-t pt-4">
            <div className="flex items-center gap-3">
              <Switch
                id="prices-include-tax"
                checked={pricesIncludeTax}
                onCheckedChange={setPricesIncludeTax}
                disabled={isLoading || hasOpenShift}
              />
              <div className="flex-1">
                <Label htmlFor="prices-include-tax" className="cursor-pointer">
                  {t("settings.order.prices_include_tax_title")}
                </Label>
                <p className="text-xs text-muted-foreground">
                  {hasOpenShift
                    ? t("settings.order.tax_mode_locked_open_shift_hint")
                    : t("settings.order.prices_include_tax_hint")}
                </p>
              </div>
            </div>
            {/* #2108 (#2102 ruling) — PERMANENT red warning: 総額表示 is a
                money switch, not a display switch. The engine either extracts
                the tax from the menu price (内税) or adds it on top (外税), so
                flipping it changes what customers PAY on every NEW order.
                Existing orders keep their immutable is_tax_included snapshot.
                The pre-#2108 hint claimed the exact opposite ("display only,
                price does not change") — that copy caused real over-charging
                (#2102), hence a warning that never hides. */}
            <div
              role="alert"
              className="flex items-start gap-2 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
              <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" />
              <p>{t("settings.order.prices_include_tax_money_warning")}</p>
            </div>
          </div>

          <div className="space-y-1.5 border-t pt-4">
            <Label htmlFor="service-charge-tax-rate" className="text-sm">
              {t("settings.order.service_charge_tax_rate_label")}
            </Label>
            <div className="relative w-full sm:w-72">
              <Input
                id="service-charge-tax-rate"
                type="number"
                inputMode="decimal"
                min={0}
                max={100}
                step="0.01"
                value={serviceChargeTaxRate}
                onChange={(e) => setServiceChargeTaxRate(e.target.value)}
                disabled={isLoading}
                className="pr-8"
              />
              <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                %
              </span>
            </div>
            <p className="text-xs text-muted-foreground">
              {t("settings.order.service_charge_tax_rate_hint")}
            </p>
          </div>

          <div className="flex items-center gap-3 border-t pt-4">
            <Switch
              id="close-report-tax-breakdown"
              checked={closeReportTaxBreakdown}
              onCheckedChange={setCloseReportTaxBreakdown}
              disabled={isLoading}
            />
            <div className="flex-1">
              <Label htmlFor="close-report-tax-breakdown" className="cursor-pointer">
                {t("settings.order.close_report_tax_breakdown_title")}
              </Label>
              <p className="text-xs text-muted-foreground">
                {t("settings.order.close_report_tax_breakdown_hint")}
              </p>
            </div>
          </div>

          {/* plan-045 — tax-rounding rule. Snapshotted onto each new order, so
              a mid-shift change is blocked by the backend (409
              TAX_ROUNDING_LOCKED_OPEN_SHIFT) and the controls are disabled
              while a till shift is open, mirroring the currency / tax-mode
              guards above. */}
          <div className="space-y-4 border-t pt-4">
            <div className="space-y-1.5">
              <Label htmlFor="tax-rounding-mode" className="text-sm">
                {t("settings.order.tax_rounding_mode_label")}
              </Label>
              <Select
                value={taxRoundingMode}
                onValueChange={(v) => setTaxRoundingMode(v as TaxRoundingMode)}
                disabled={isLoading || hasOpenShift}
              >
                <SelectTrigger id="tax-rounding-mode" className="w-full sm:w-72">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="round">
                    {t("settings.order.tax_rounding_mode_round")}
                  </SelectItem>
                  <SelectItem value="ceil">{t("settings.order.tax_rounding_mode_ceil")}</SelectItem>
                  <SelectItem value="floor">
                    {t("settings.order.tax_rounding_mode_floor")}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                {hasOpenShift
                  ? t("settings.order.tax_rounding_locked_open_shift_hint")
                  : t("settings.order.tax_rounding_mode_hint")}
              </p>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="tax-rounding-decimals" className="text-sm">
                {t("settings.order.tax_rounding_decimals_label")}
              </Label>
              <Select
                value={taxRoundingDecimals}
                onValueChange={setTaxRoundingDecimals}
                disabled={isLoading || hasOpenShift}
              >
                <SelectTrigger id="tax-rounding-decimals" className="w-full sm:w-72">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="0">
                    {t("settings.order.tax_rounding_decimals_option", { n: "0" })}
                  </SelectItem>
                  <SelectItem value="1">
                    {t("settings.order.tax_rounding_decimals_option", { n: "1" })}
                  </SelectItem>
                  <SelectItem value="2">
                    {t("settings.order.tax_rounding_decimals_option", { n: "2" })}
                  </SelectItem>
                  <SelectItem value="3">
                    {t("settings.order.tax_rounding_decimals_option", { n: "3" })}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                {/* #1130 — the decimals select is locked by the same guard as
                    the mode select; its hint must say so too. */}
                {hasOpenShift
                  ? t("settings.order.tax_rounding_locked_open_shift_hint")
                  : t("settings.order.tax_rounding_decimals_hint")}
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex items-center gap-1.5">
            <CardTitle className="text-base">{t("settings.order.split_rounding_title")}</CardTitle>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="inline-flex size-4 items-center justify-center rounded-full border border-muted-foreground/40 text-[10px] text-muted-foreground hover:bg-muted"
                  aria-label={t("settings.order.split_rounding_help_aria")}
                >
                  ?
                </button>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                {t("settings.order.split_rounding_help")}
              </TooltipContent>
            </Tooltip>
          </div>
          <CardDescription>{t("settings.order.split_rounding_description")}</CardDescription>
        </CardHeader>

        <CardContent>
          <div className="space-y-1.5">
            <Label htmlFor="split-bill-rounding" className="text-sm">
              {t("settings.order.split_rounding_title")}
            </Label>
            <Select
              value={splitBillRoundingMode}
              onValueChange={(value) => setSplitBillRoundingMode(value as SplitBillRoundingMode)}
              disabled={isLoading}
            >
              <SelectTrigger id="split-bill-rounding" className="w-full sm:w-72">
                <SelectValue placeholder={t("settings.order.split_rounding_title")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="auto">{t("settings.order.split_rounding_auto")}</SelectItem>
                <SelectItem value="integer">
                  {t("settings.order.split_rounding_integer")}
                </SelectItem>
                <SelectItem value="two_decimals">
                  {t("settings.order.split_rounding_two_decimals")}
                </SelectItem>
                <SelectItem value="none">{t("settings.order.split_rounding_none")}</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t(`settings.order.split_rounding_preview_${splitBillRoundingMode}`, {
                currency: currencies.find((c) => c.code === currencyCode)?.label ?? currencyCode,
              })}
            </p>
          </div>
        </CardContent>
      </Card>

      {/* plan-035 — Payment policy + customer-email-required */}
      <Card data-slot="payment-policy-card">
        <CardHeader>
          <div className="flex items-center gap-1.5">
            <CardTitle className="text-base">{t("settings.order.payment_policy_title")}</CardTitle>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="inline-flex size-4 items-center justify-center rounded-full border border-muted-foreground/40 text-[10px] text-muted-foreground hover:bg-muted"
                  aria-label={t("settings.order.payment_policy_help_aria")}
                >
                  ?
                </button>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                {t("settings.order.payment_policy_help")}
              </TooltipContent>
            </Tooltip>
          </div>
          <CardDescription>{t("settings.order.payment_policy_description")}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-5">
          <RadioGroup
            value={prepMode}
            onValueChange={(v) => setPrepMode(v as "use_hq" | "on" | "off")}
            className="space-y-2"
          >
            <div className="flex items-start gap-3">
              <RadioGroupItem value="use_hq" id="prep-use-hq" className="mt-0.5 shrink-0" />
              <Label htmlFor="prep-use-hq" className="cursor-pointer leading-snug">
                {t("settings.order.payment_policy_use_hq")}
              </Label>
            </div>
            <div className="flex items-start gap-3">
              <RadioGroupItem value="on" id="prep-on" className="mt-0.5 shrink-0" />
              <Label htmlFor="prep-on" className="flex-1 cursor-pointer leading-snug">
                {t("settings.order.payment_policy_on")}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      onClick={(e) => e.preventDefault()}
                      className="ml-1.5 inline-flex size-4 items-center justify-center rounded-full border border-muted-foreground/40 text-[10px] text-muted-foreground hover:bg-muted"
                      aria-label={t("settings.order.payment_policy_on_help_aria")}
                    >
                      ?
                    </button>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                    {t("settings.order.payment_policy_on_help")}
                  </TooltipContent>
                </Tooltip>
              </Label>
            </div>
            <div className="flex items-start gap-3">
              <RadioGroupItem value="off" id="prep-off" className="mt-0.5 shrink-0" />
              <Label htmlFor="prep-off" className="flex-1 cursor-pointer leading-snug">
                {t("settings.order.payment_policy_off")}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      onClick={(e) => e.preventDefault()}
                      className="ml-1.5 inline-flex size-4 items-center justify-center rounded-full border border-muted-foreground/40 text-[10px] text-muted-foreground hover:bg-muted"
                      aria-label={t("settings.order.payment_policy_off_help_aria")}
                    >
                      ?
                    </button>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                    {t("settings.order.payment_policy_off_help")}
                  </TooltipContent>
                </Tooltip>
              </Label>
            </div>
          </RadioGroup>

          <div className="flex items-center gap-3 border-t pt-4">
            <Switch
              id="customer-email-required"
              checked={emailRequired}
              onCheckedChange={setEmailRequired}
              disabled={isLoading}
            />
            <div className="flex-1">
              <div className="flex items-center gap-1.5">
                <Label htmlFor="customer-email-required" className="cursor-pointer">
                  {t("settings.order.email_required_title")}
                </Label>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      className="inline-flex size-4 items-center justify-center rounded-full border border-muted-foreground/40 text-[10px] text-muted-foreground hover:bg-muted"
                      aria-label={t("settings.order.email_required_help_aria")}
                    >
                      ?
                    </button>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                    {t("settings.order.email_required_help")}
                  </TooltipContent>
                </Tooltip>
              </div>
              <p className="text-xs text-muted-foreground">
                {t("settings.order.email_required_hint")}
              </p>
            </div>
          </div>

          {/* plan-037 — confirmation timeout override on its own row so
              it doesn't get squished into the email-required flex layout.
              null = inherit brand (1–30 minutes). */}
          <div className="space-y-3 border-t pt-4">
            <div>
              <Label>{t("settings.order.confirmation_timeout_label")}</Label>
              <p className="mt-1 text-xs text-muted-foreground">
                {t("settings.order.confirmation_timeout_hint")}
              </p>
            </div>
            <RadioGroup
              value={confirmTimeoutMode}
              onValueChange={(v) => setConfirmTimeoutMode(v as "use_hq" | "custom")}
              className="gap-2"
            >
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value="use_hq"
                  id="confirm-timeout-use-hq"
                  className="mt-0.5 shrink-0"
                />
                <Label
                  htmlFor="confirm-timeout-use-hq"
                  className="flex-1 cursor-pointer leading-snug"
                >
                  {t("settings.order.confirmation_timeout_use_hq")}
                </Label>
              </div>
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value="custom"
                  id="confirm-timeout-custom"
                  className="mt-0.5 shrink-0"
                />
                <Label
                  htmlFor="confirm-timeout-custom"
                  className="flex-1 cursor-pointer leading-snug"
                >
                  {t("settings.order.confirmation_timeout_custom")}
                </Label>
              </div>
            </RadioGroup>
            {confirmTimeoutMode === "custom" && (
              <div className="ml-7 space-y-1">
                <Input
                  id="confirm-timeout-minutes"
                  type="number"
                  min={1}
                  max={30}
                  step={1}
                  value={confirmTimeoutRaw}
                  onChange={(e) => setConfirmTimeoutRaw(e.target.value)}
                  error={confirmTimeoutError || undefined}
                  className="w-24"
                />
                {!confirmTimeoutError && (
                  <p className="text-xs text-muted-foreground">
                    {t("settings.order.confirmation_timeout_minutes_hint")}
                  </p>
                )}
              </div>
            )}
          </div>

          {/* #1160 — thời gian chuẩn bị / món. ETA khách nhìn thấy =
              giá trị này x TỔNG SỐ LƯỢNG trong giỏ. null = Theo HQ. */}
          <div className="space-y-3 border-t pt-4">
            <div>
              <Label>{t("settings.order.prep_minutes_label")}</Label>
              <p className="mt-1 text-xs text-muted-foreground">
                {t("settings.order.prep_minutes_hint")}
              </p>
            </div>
            <RadioGroup
              value={prepMinutesMode}
              onValueChange={(v) => setPrepMinutesMode(v as "use_hq" | "custom")}
              className="gap-2"
            >
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value="use_hq"
                  id="prep-minutes-use-hq"
                  className="mt-0.5 shrink-0"
                />
                <Label htmlFor="prep-minutes-use-hq" className="flex-1 cursor-pointer leading-snug">
                  {t("settings.order.prep_minutes_use_hq", {
                    minutes: String(settings?.effective_prep_minutes_per_item ?? 5),
                  })}
                </Label>
              </div>
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value="custom"
                  id="prep-minutes-custom"
                  className="mt-0.5 shrink-0"
                />
                <Label htmlFor="prep-minutes-custom" className="flex-1 cursor-pointer leading-snug">
                  {t("settings.order.prep_minutes_custom")}
                </Label>
              </div>
            </RadioGroup>
            {prepMinutesMode === "custom" && (
              <div className="ml-7 space-y-1">
                <Input
                  id="prep-minutes-per-item"
                  type="number"
                  min={0}
                  max={120}
                  step={1}
                  value={prepMinutesRaw}
                  onChange={(e) => setPrepMinutesRaw(e.target.value)}
                  error={prepMinutesError || undefined}
                  className="w-24"
                />
                {!prepMinutesError && (
                  <p className="text-xs text-muted-foreground">
                    {t("settings.order.prep_minutes_example", {
                      minutes: String(Number.isNaN(prepMinutesNum) ? 0 : prepMinutesNum),
                      total: String(Number.isNaN(prepMinutesNum) ? 0 : prepMinutesNum * 2),
                    })}
                  </p>
                )}
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* #491 — table status after payment (shop override of HQ default) */}
      <Card data-slot="table-status-after-payment-card">
        <CardHeader>
          <CardTitle className="text-base">
            {t("settings.order.table_status_after_payment_title")}
          </CardTitle>
          <CardDescription>
            {t("settings.order.table_status_after_payment_description")}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <RadioGroup
            value={tableStatusMode}
            onValueChange={(v) => setTableStatusMode(v as "use_hq" | TableStatusAfterPayment)}
            className="space-y-2"
          >
            <div className="flex items-start gap-3">
              <RadioGroupItem value="use_hq" id="table-status-use-hq" className="mt-0.5 shrink-0" />
              <Label htmlFor="table-status-use-hq" className="flex-1 cursor-pointer leading-snug">
                {t("settings.order.table_status_use_hq")}
                {settings?.effective_table_status_after_payment && (
                  <span className="ml-1 text-xs text-muted-foreground">
                    (
                    {t(
                      settings.effective_table_status_after_payment === "cleaning"
                        ? "settings.order.table_status_cleaning"
                        : "settings.order.table_status_free"
                    )}
                    )
                  </span>
                )}
              </Label>
            </div>
            <div className="flex items-start gap-3">
              <RadioGroupItem value="free" id="table-status-free" className="mt-0.5 shrink-0" />
              <Label htmlFor="table-status-free" className="flex-1 cursor-pointer leading-snug">
                {t("settings.order.table_status_free")}
              </Label>
            </div>
            <div className="flex items-start gap-3">
              <RadioGroupItem
                value="cleaning"
                id="table-status-cleaning"
                className="mt-0.5 shrink-0"
              />
              <Label htmlFor="table-status-cleaning" className="flex-1 cursor-pointer leading-snug">
                {t("settings.order.table_status_cleaning")}
              </Label>
            </div>
          </RadioGroup>
        </CardContent>
      </Card>

      <div className="flex items-center justify-end gap-3">
        {isDirty && (
          <span className="text-xs text-muted-foreground">
            {t("settings.order.unsaved_changes")}
          </span>
        )}
        <Button
          size="sm"
          className="h-8 gap-1.5 text-xs"
          disabled={
            saveMutation.isPending ||
            isLoading ||
            !isDirty ||
            !!confirmTimeoutError ||
            !!prepMinutesError
          }
          onClick={() => {
            const serviceNum = Number(serviceChargeRate);
            const serviceTaxNum = Number(serviceChargeTaxRate);
            saveMutation.mutate({
              default_order_item_status: selectedStatus,
              enable_quick_order: quickOrder,
              // plan-051 — the matrix + timing are the source of truth.
              item_voidable_statuses: voidableStatuses,
              stock_deduction_timing: stockDeductionTiming,
              // Legacy #1148 mirror while the settings endpoint / fleet may
              // still read only the boolean: true ONLY for the lossless
              // all-four case; any narrower matrix maps to false (strictest
              // legacy interpretation — pending-only — is the safe fallback).
              allow_item_edit_any_status: deriveLegacyItemEditFlag(voidableStatuses),
              show_seller_registration_on_receipt: showSellerRegistration,
              handy_allow_direct_payment: handyAllowDirectPayment,
              counter_pay_enabled: counterPayEnabled,
              counter_pay_show_qr: counterPayShowQr,
              service_charge_rate: Number.isFinite(serviceNum) ? serviceNum : 0,
              default_tax_type_id: defaultTaxTypeId || null,
              prices_include_tax: pricesIncludeTax,
              service_charge_tax_rate: Number.isFinite(serviceTaxNum) ? serviceTaxNum : 0,
              close_report_tax_breakdown: closeReportTaxBreakdown,
              tax_rounding_mode: taxRoundingMode,
              tax_rounding_decimals: Number(taxRoundingDecimals),
              currency_code: currencyCode,
              split_bill_rounding_mode: splitBillRoundingMode,
              prep_before_payment: prepMode === "use_hq" ? null : prepMode === "on",
              customer_email_required: emailRequired,
              confirmation_timeout_minutes:
                confirmTimeoutMode === "use_hq" ? null : confirmTimeoutNum,
              prep_minutes_per_item: prepMinutesMode === "use_hq" ? null : prepMinutesNum,
              table_status_after_payment: tableStatusMode === "use_hq" ? null : tableStatusMode,
              // "" = "Theo mặc định chi nhánh" → gửi null để workstation tự
              // fallback, thay vì ép một ngôn ngữ quản lý không chọn.
              print_label_locale: (printLabelLocale || null) as PrintLabelLocale | null,
            });
          }}
        >
          {saveMutation.isPending && <Spinner className="size-3.5" />}
          {t("common.save_changes")}
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Cart timeout tab
// ---------------------------------------------------------------------------

/**
 * #1152 — shop-level 登録番号 override (branch ?? brand). Self-contained:
 * reads/writes /settings/branch so the surrounding order-settings form's
 * save button stays independent.
 */
function SellerRegistrationNumberField({ shopSlug }: { shopSlug: string }) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: branchSettingsKeys.get(shopSlug),
    queryFn: () => apiFetch<BranchSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/branch`),
    staleTime: 60 * 1000,
    retry: false,
  });
  const settings = data?.data;

  const [value, setValue] = useState("");
  useEffect(() => {
    if (settings !== undefined) {
      setValue(settings.invoice_registration_number ?? "");
    }
  }, [settings]);

  const saveMutation = useMutation({
    mutationFn: (body: { invoice_registration_number: string | null }) =>
      apiFetch<BranchSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/branch`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: branchSettingsKeys.get(shopSlug) });
      toast.success(t("settings.order.seller_registration_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : t("settings.order.seller_registration_error")
      );
    },
  });

  const trimmed = value.trim();
  // #1153 — the client doesn't know the org's operating country, so accept
  // either national format (JP インボイス T+13 · VN mã số thuế 10/-3) and let
  // the server, which resolves the country profile, reject the wrong one.
  const formatInvalid =
    trimmed !== "" && !/^T\d{13}$/.test(trimmed) && !/^\d{10}(-\d{3})?$/.test(trimmed);
  const isDirty =
    settings !== undefined && trimmed !== (settings.invoice_registration_number ?? "");

  return (
    <div className="space-y-1.5">
      <Label htmlFor="seller-registration-number">
        {t("settings.order.seller_registration_field_label")}
      </Label>
      <div className="flex items-center gap-2">
        <Input
          id="seller-registration-number"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder={settings?.hq_brand_invoice_registration_number ?? "T1234567890123"}
          maxLength={14}
          disabled={isLoading}
          className="max-w-xs font-mono"
        />
        <Button
          size="sm"
          disabled={isLoading || saveMutation.isPending || formatInvalid || !isDirty}
          onClick={() =>
            saveMutation.mutate({ invoice_registration_number: trimmed === "" ? null : trimmed })
          }
        >
          {t("common.save")}
        </Button>
      </div>
      {formatInvalid && (
        <p className="text-xs text-destructive">
          {t("settings.order.seller_registration_format_error")}
        </p>
      )}
      <p className="text-[11px] text-muted-foreground">
        {t("settings.order.seller_registration_field_help", {
          effective:
            settings?.effective_invoice_registration_number ??
            t("settings.order.seller_registration_none"),
        })}
      </p>
    </div>
  );
}

function CartTimeoutTab({ shopSlug }: { shopSlug: string }) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: branchSettingsKeys.get(shopSlug),
    queryFn: () => apiFetch<BranchSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/branch`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  // "use_hq" | "custom"
  const [mode, setMode] = useState<"use_hq" | "custom">("use_hq");
  const [minutes, setMinutes] = useState<number>(15);

  useEffect(() => {
    if (settings !== undefined) {
      const mins = settings.cart_timeout_minutes;
      if (mins !== null && mins >= 1) {
        setMode("custom");
        setMinutes(mins);
      } else {
        setMode("use_hq");
      }
    }
  }, [settings]);

  const isDirty =
    settings !== undefined &&
    (() => {
      const current = settings.cart_timeout_minutes;
      if (mode === "use_hq") {
        return current !== null;
      }

      return current !== minutes;
    })();

  const saveMutation = useMutation({
    mutationFn: (body: { cart_timeout_minutes: number | null }) =>
      apiFetch<BranchSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/branch`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: branchSettingsKeys.get(shopSlug) });
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
      toast.success(t("shop.settings.timeout.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("shop.settings.timeout.toast_error")
      );
    },
  });

  if (error) {
    throw error;
  }

  const hqDefault = settings?.hq_brand_timeout_minutes;
  const hqLabel =
    hqDefault !== null && hqDefault !== undefined
      ? t("shop.settings.timeout.hq_default_label", { minutes: String(hqDefault) })
      : t("shop.settings.timeout.hq_default_label_empty");

  return (
    <div data-slot="cart-timeout-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("shop.settings.timeout.section_title")}</CardTitle>
          <CardDescription>{hqLabel}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Spinner className="size-4" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <RadioGroup
                value={mode}
                onValueChange={(val) => setMode(val as "use_hq" | "custom")}
                className="space-y-3"
              >
                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="use_hq"
                    id="shop-timeout-use-hq"
                    className="mt-0.5 shrink-0"
                  />
                  <Label htmlFor="shop-timeout-use-hq" className="cursor-pointer leading-snug">
                    {t("shop.settings.timeout.use_hq_radio")}
                  </Label>
                </div>

                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="custom"
                    id="shop-timeout-custom"
                    className="mt-0.5 shrink-0"
                  />
                  <Label htmlFor="shop-timeout-custom" className="cursor-pointer leading-snug">
                    {t("shop.settings.timeout.custom_radio")}
                  </Label>
                </div>
              </RadioGroup>

              {mode === "custom" && (
                <div className="ml-6 space-y-1">
                  <Input
                    id="shop-timeout-minutes"
                    type="number"
                    min={1}
                    value={minutes.toString()}
                    onChange={(e) => setMinutes(Math.max(1, Number(e.target.value)))}
                    className="w-24"
                  />
                  <p className="text-xs text-muted-foreground">
                    {t("shop.settings.timeout.minutes_hint")}
                  </p>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-end gap-3">
        {isDirty && (
          <span className="text-xs text-muted-foreground">
            {t("settings.order.unsaved_changes")}
          </span>
        )}
        <Button
          size="sm"
          className="h-8 gap-1.5 text-xs"
          disabled={saveMutation.isPending || isLoading || !isDirty}
          onClick={() =>
            saveMutation.mutate({
              cart_timeout_minutes: mode === "custom" ? minutes : null,
            })
          }
        >
          {saveMutation.isPending && <Spinner className="size-3.5" />}
          {t("common.save_changes")}
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Takeaway payment timeout tab (plan-031)
// ---------------------------------------------------------------------------

function TakeawayPaymentTimeoutTab({ shopSlug }: { shopSlug: string }) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: ["shop-takeaway-payment-settings", shopSlug],
    queryFn: () =>
      apiFetch<{
        data: {
          takeaway_payment_timeout_minutes: number | null;
          hq_brand_takeaway_payment_timeout_minutes: number | null;
          effective_takeaway_payment_timeout_minutes: number | null;
        };
      }>(`/api/v1/shops/${shopSlug}/settings/takeaway-payment`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  // "use_hq" | "custom"
  const [mode, setMode] = useState<"use_hq" | "custom">("use_hq");
  const [minutes, setMinutes] = useState<number>(15);

  useEffect(() => {
    if (settings !== undefined) {
      const mins = settings.takeaway_payment_timeout_minutes;
      if (mins !== null && mins >= 5) {
        setMode("custom");
        setMinutes(mins);
      } else {
        setMode("use_hq");
      }
    }
  }, [settings]);

  const isDirty =
    settings !== undefined &&
    ((mode === "custom" && settings.takeaway_payment_timeout_minutes !== minutes) ||
      (mode === "use_hq" && settings.takeaway_payment_timeout_minutes !== null));

  const saveMutation = useMutation({
    mutationFn: (body: { takeaway_payment_timeout_minutes: number | null }) =>
      apiFetch<{
        data: {
          takeaway_payment_timeout_minutes: number | null;
          hq_brand_takeaway_payment_timeout_minutes: number | null;
          effective_takeaway_payment_timeout_minutes: number | null;
        };
      }>(`/api/v1/shops/${shopSlug}/settings/takeaway-payment`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["shop-takeaway-payment-settings", shopSlug] });
      toast.success(t("shop.settings.takeaway_payment.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("shop.settings.takeaway_payment.toast_error")
      );
    },
  });

  if (error) {
    throw error;
  }

  const hqDefault = settings?.hq_brand_takeaway_payment_timeout_minutes;
  const hqLabel =
    hqDefault !== null && hqDefault !== undefined
      ? t("shop.settings.takeaway_payment.hq_default_label", { minutes: String(hqDefault) })
      : t("shop.settings.takeaway_payment.hq_default_label_empty");

  return (
    <div data-slot="takeaway-payment-timeout-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <div className="flex items-center gap-1.5">
            <CardTitle className="text-base">
              {t("shop.settings.takeaway_payment.section_title")}
            </CardTitle>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="inline-flex size-4 items-center justify-center rounded-full border border-muted-foreground/40 text-[10px] text-muted-foreground hover:bg-muted"
                  aria-label={t("shop.settings.takeaway_payment.help_aria")}
                >
                  ?
                </button>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                {t("shop.settings.takeaway_payment.help")}
              </TooltipContent>
            </Tooltip>
          </div>
          <CardDescription>{hqLabel}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Spinner className="size-4" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <RadioGroup value={mode} onValueChange={(v) => setMode(v as typeof mode)}>
                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="use_hq"
                    id="shop-takeaway-hq"
                    className="mt-0.5 shrink-0"
                  />
                  <Label htmlFor="shop-takeaway-hq" className="cursor-pointer leading-snug">
                    {t("shop.settings.takeaway_payment.use_hq_radio")}
                  </Label>
                </div>

                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="custom"
                    id="shop-takeaway-custom"
                    className="mt-0.5 shrink-0"
                  />
                  <Label htmlFor="shop-takeaway-custom" className="cursor-pointer leading-snug">
                    {t("shop.settings.takeaway_payment.custom_radio")}
                  </Label>
                </div>
              </RadioGroup>

              {mode === "custom" && (
                <div className="ml-6 space-y-1">
                  <Input
                    id="shop-takeaway-minutes"
                    type="number"
                    min={5}
                    max={120}
                    value={minutes.toString()}
                    onChange={(e) => {
                      const val = Number(e.target.value);
                      setMinutes(Math.max(5, Math.min(120, val)));
                    }}
                    className="w-24"
                  />
                  <p className="text-xs text-muted-foreground">
                    {t("shop.settings.takeaway_payment.minutes_hint")}
                  </p>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-end gap-3">
        {isDirty && (
          <span className="text-xs text-muted-foreground">
            {t("settings.order.unsaved_changes")}
          </span>
        )}
        <Button
          size="sm"
          className="h-8 gap-1.5 text-xs"
          disabled={saveMutation.isPending || isLoading || !isDirty}
          onClick={() =>
            saveMutation.mutate({
              takeaway_payment_timeout_minutes: mode === "custom" ? minutes : null,
            })
          }
        >
          {saveMutation.isPending && <Spinner className="size-3.5" />}
          {t("common.save_changes")}
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Payment methods tab
// ---------------------------------------------------------------------------

const METHOD_ICON: Record<string, typeof Banknote> = {
  cash: Banknote,
  card: CreditCard,
  transfer: ArrowLeftRight,
  e_wallet: Wallet,
};

function paymentMethodIcon(code: string) {
  return METHOD_ICON[code] ?? Receipt;
}

function PaymentMethodsTab({ shopSlug }: { shopSlug: string }) {
  const { t } = useTranslation();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: effectivePaymentOptionKeys.list(shopSlug),
    queryFn: () =>
      apiFetch<EffectivePaymentOptionsResponse>(
        `/api/v1/shops/${shopSlug}/effective-payment-options`
      ),
    staleTime: 60 * 1000,
    retry: false,
  });

  const methods = data?.data?.options ?? [];

  if (error) {
    return (
      <Alert variant="destructive" className="max-w-xl">
        <AlertDescription className="flex items-center justify-between gap-3">
          <span>{t("common.failed_to_load")}</span>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            {t("common.retry")}
          </Button>
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="max-w-xl space-y-4">
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm">{t("shop.payments.gateway_link_title")}</CardTitle>
          <CardDescription>{t("shop.payments.gateway_link_desc")}</CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild variant="outline" size="sm" className="gap-1">
            <a href={`/shop/${shopSlug}/settings/payments`}>
              {t("shop.payments.gateway_link_action")}
              <ArrowLeftRight className="size-3.5" aria-hidden />
            </a>
          </Button>
        </CardContent>
      </Card>

      <Alert>
        <InfoIcon className="size-4" />
        <AlertDescription>{t("settings.payment.admin_note")}</AlertDescription>
      </Alert>

      {isLoading ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          {t("common.loading")}
        </div>
      ) : methods.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center gap-2 py-10 text-center">
            <Receipt className="size-8 text-muted-foreground/40" />
            <p className="text-xs text-muted-foreground">{t("settings.payment.empty")}</p>
          </CardContent>
        </Card>
      ) : (
        <ul className="space-y-2">
          {methods.map((method) => {
            // Icon theo `method_type`, rơi về `rail` khi thiếu: hai trường này
            // thay cho `code` của bảng cũ, và không option nào chắc có cả hai.
            const Icon = paymentMethodIcon(method.method_type ?? method.rail ?? "");

            return (
              <li key={method.id}>
                <div
                  data-slot="payment-method-row"
                  data-active={method.effective}
                  className="flex items-center gap-3 rounded-lg border bg-card p-3 transition-colors data-[active=false]:opacity-60"
                >
                  <span
                    className={`flex size-10 shrink-0 items-center justify-center rounded-md ${
                      method.effective
                        ? "bg-primary/10 text-primary"
                        : "bg-muted text-muted-foreground"
                    }`}
                  >
                    <Icon className="size-5" />
                  </span>

                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-foreground">
                        {method.display_name}
                      </span>
                      {method.method_type ? (
                        <Badge variant="outline" className="h-5 text-[10px]">
                          {method.method_type}
                        </Badge>
                      ) : null}
                    </div>
                    <div className="mt-0.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                      {method.provider ? <span>{method.provider}</span> : null}
                      {method.provider && method.source ? <span>·</span> : null}
                      {/* `source` = tầng nào trong thang quyết định đã chốt option
                          này. Bảng cũ không có thông tin đó, mà nó lại chính là
                          thứ người vận hành cần khi một phương thức "biến mất". */}
                      {method.source ? <span>{method.source}</span> : null}
                    </div>
                  </div>

                  <Badge variant={method.effective ? "default" : "outline"} className="shrink-0">
                    {method.effective
                      ? t("settings.payment.status.active")
                      : t("settings.payment.status.inactive")}
                  </Badge>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
