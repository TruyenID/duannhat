"use client";

import { notFound, useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { LoadingShell } from "@/components/layout/loading-shell";
import { PageShell } from "@/components/layout/page-shell";
import { RealtimeMount } from "@/components/notifications/realtime-mount";
import { ShopTimezoneProvider } from "@/providers/shop-timezone-provider";
import { ShopCurrencyProvider } from "@/providers/shop-currency-provider";
import type { NavGroup } from "@/components/layout/app-sidebar";
import { useTranslation } from "@/providers/app-provider";
import { ApiError, apiFetch } from "@/lib/api";
import { Button } from "@godxjp/ui";
import { ErrorShell } from "@/components/layout/error-shell";
import { ForbiddenShell } from "@/components/layout/forbidden-shell";
import {
  LayoutDashboard,
  Clock,
  Gift,
  UtensilsCrossed,
  BarChart3,
  ArrowLeftRight,
  Boxes,
  ClipboardList,
  Trash2,
  Factory,
  FlaskConical,
  Calculator,
  Warehouse as WarehouseIcon,
  AlertTriangle,
  LayoutGrid,
  Printer as PrinterIcon,
  Tablet,
  PlugZap,
  Users,
  HelpCircle,
  ShoppingCart,
  Settings,
  Percent,
  Bell,
  HandCoins,
  Receipt,
  ScrollText,
  Wallet,
} from "lucide-react";

interface ShopResponse {
  data: {
    id: string;
    slug: string;
    name: string;
    code: string | null;
    is_headquarters: boolean;
    console_brand_id: string | null;
    brand_slug?: string | null;
    // Returned by ShopInfoController all along; nothing read it, so every
    // shop-scoped screen drew its timestamps in the viewer's zone (#1248).
    timezone?: string | null;
    // #1260 — shop_order_settings.currency_code, falling back to the older
    // branches.currency. Money on a shop screen belongs to the shop, not to the
    // language the reader picked.
    currency_code?: string | null;
  };
}

export default function ShopLayout({ children }: { children: React.ReactNode }) {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const { t } = useTranslation();

  // Single-row endpoint backed by ResolveShopFromSlug middleware. Same shape
  // and same scaling rationale as the HQ layout — see
  // src/app/hq/[brandSlug]/layout.tsx for the full explanation.
  const { data, error, refetch } = useQuery({
    queryKey: ["shop", "info", shopSlug],
    queryFn: ({ signal }) => apiFetch<ShopResponse>(`/api/v1/shops/${shopSlug}`, { signal }),
    staleTime: 5 * 60 * 1000,
    retry: false,
    // Always fire even when the browser reports offline so we get a real error
    // instead of the query being paused indefinitely (the default "online" mode
    // would leave the spinner spinning when the backend is unreachable).
    networkMode: "always",
  });

  if (error) {
    if (error instanceof ApiError) {
      if (error.status === 404) notFound();
      if (error.status === 403) return <ForbiddenShell />;
    }
    // 5xx / network / timeout — show classified recovery UI.
    return <ErrorShell error={error} onRetry={refetch} />;
  }

  // The shop shell depends on the resolved shop: name, sidebar nav, child
  // pages that use branch_id. Until the API answers, render a loading state —
  // anything else would be a half-built page with a placeholder name and
  // broken child queries. See HQ layout for the same rationale.
  if (!data) {
    return <LoadingShell />;
  }

  const shopName = data.data.name;

  const navGroups: NavGroup[] = [
    {
      label: t("nav.overview"),
      items: [
        { title: t("nav.dashboard"), href: `/shop/${shopSlug}/dashboard`, icon: LayoutDashboard },
      ],
    },
    {
      label: t("nav.sales"),
      items: [
        { title: t("nav.menus"), href: `/shop/${shopSlug}/menus`, icon: UtensilsCrossed },
        {
          title: t("nav.floating_sections"),
          href: `/shop/${shopSlug}/floating-sections`,
          icon: Clock,
        },
        { title: t("nav.customers"), href: `/shop/${shopSlug}/customers`, icon: Users },
        { title: t("nav.customer_orders"), href: `/shop/${shopSlug}/orders`, icon: ShoppingCart },
        // #1673 — câu hỏi riêng chi nhánh + công tắc kế thừa từ HQ. Đặt trong
        // nhóm Bán hàng cạnh Khách hàng: đây là nội dung khách đọc.
        { title: t("nav.faqs"), href: `/shop/${shopSlug}/faqs`, icon: HelpCircle },
        { title: t("nav.promotions"), href: `/shop/${shopSlug}/promotions`, icon: Percent },
        // #1514 — chỉ đọc + công tắc bật/tắt cho riêng cửa hàng này. Thông số
        // (điểm, ảnh, giảm giá) là quyết định cấp brand, sửa ở màn HQ.
        {
          title: t("nav.point_rewards"),
          href: `/shop/${shopSlug}/point-rewards`,
          icon: Gift,
        },
      ],
    },
    {
      label: t("nav.stock"),
      items: [
        { title: t("nav.stock_levels"), href: `/shop/${shopSlug}/stock/levels`, icon: BarChart3 },
        { title: t("nav.material_lots"), href: `/shop/${shopSlug}/material-lots`, icon: Boxes },
        {
          title: t("nav.transactions"),
          href: `/shop/${shopSlug}/stock/transactions`,
          icon: ClipboardList,
        },
        {
          title: t("nav.transfers"),
          href: `/shop/${shopSlug}/stock/transfers`,
          icon: ArrowLeftRight,
        },
        { title: t("nav.counts"), href: `/shop/${shopSlug}/stock/counts`, icon: ClipboardList },
        { title: t("nav.disposals"), href: `/shop/${shopSlug}/stock/disposals`, icon: Trash2 },
        { title: t("nav.alerts"), href: `/shop/${shopSlug}/stock/alerts`, icon: AlertTriangle },
      ],
    },
    {
      label: t("nav.floor"),
      items: [
        { title: t("nav.tables"), href: `/shop/${shopSlug}/tables`, icon: LayoutGrid },
        { title: t("nav.devices"), href: `/shop/${shopSlug}/devices`, icon: Tablet },
        {
          title: t("nav.peripherals"),
          href: `/shop/${shopSlug}/peripheral-devices`,
          icon: PlugZap,
        },
        { title: t("nav.printers"), href: `/shop/${shopSlug}/printers`, icon: PrinterIcon },
        {
          title: t("nav.print_templates"),
          href: `/shop/${shopSlug}/print-templates`,
          icon: PrinterIcon,
        },
        // plan-052 M2 (#1166) — the print LEDGER. Sits next to the printers it
        // describes: when a slip does not come out, the operator's next move is
        // this page, not the printer config.
        {
          title: t("nav.print_jobs"),
          href: `/shop/${shopSlug}/print-jobs`,
          icon: ScrollText,
        },
        // Plan-036 — manager till tracking. #1005 consolidated the former
        // three entries (Dashboard / Shift history / Ca treo) into one page:
        // /till now hosts Tổng quan · Lịch sử ca · Ca treo as tabs, so the
        // sidebar carries a single link. Server-gated by ShopTillTrackingPolicy
        // (manager+); non-managers hitting the URL see the inline 403 alert.
        {
          title: t("till_tracking.nav.dashboard"),
          href: `/shop/${shopSlug}/till`,
          icon: Receipt,
        },
        // #1998 — mặt công nợ cho QUẢN LÝ. Thu ngân đã thấy số này ở POS từ
        // #1990; người quyết định có đi đòi hay không thì trước đây không có
        // chỗ nào xem. Dùng `HandCoins` chứ không `Wallet`: `Wallet` đã là icon
        // của "kích hoạt phương thức thanh toán" ở nhóm Cài đặt, và hai mục
        // cùng icon trong một sidebar là cách nhanh nhất để bấm nhầm.
        {
          title: t("nav.shop_debts"),
          href: `/shop/${shopSlug}/debts`,
          icon: HandCoins,
        },
      ],
    },
    {
      label: t("nav.production"),
      items: [
        {
          title: t("nav.batches"),
          href: `/shop/${shopSlug}/production/batches`,
          icon: FlaskConical,
        },
        { title: t("nav.orders"), href: `/shop/${shopSlug}/production/orders`, icon: Factory },
        {
          title: t("nav.calculator"),
          href: `/shop/${shopSlug}/production/calculator`,
          icon: Calculator,
        },
      ],
    },
    {
      label: t("nav.workflow"),
      items: [
        { title: t("nav.notifications"), href: `/shop/${shopSlug}/notifications`, icon: Bell },
        {
          title: t("nav.notifications_audiences"),
          href: `/shop/${shopSlug}/notifications/audiences`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_templates"),
          href: `/shop/${shopSlug}/notifications/templates`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_routing"),
          href: `/shop/${shopSlug}/notifications/routing`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_compose"),
          href: `/shop/${shopSlug}/notifications/compose`,
          icon: Bell,
        },
      ],
    },
    {
      label: t("nav.settings"),
      items: [
        { title: t("nav.warehouses"), href: `/shop/${shopSlug}/warehouses`, icon: WarehouseIcon },
        {
          title: t("nav.tender_activation"),
          href: `/shop/${shopSlug}/settings/tenders`,
          icon: Wallet,
        },
        { title: t("common.settings"), href: `/shop/${shopSlug}/settings`, icon: Settings },
      ],
    },
  ];

  return (
    <ShopTimezoneProvider timezone={data.data.timezone}>
      <ShopCurrencyProvider currencyCode={data.data.currency_code}>
      <RealtimeMount shopSlug={shopSlug} />
      <PageShell sidebar topbar brandName={shopName} mode="shop" navGroups={navGroups}>
        {children}
      </PageShell>
      </ShopCurrencyProvider>
    </ShopTimezoneProvider>
  );
}
