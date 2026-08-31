"use client";

import { notFound, useParams } from "next/navigation";
import { useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { LoadingShell } from "@/components/layout/loading-shell";
import { PageShell } from "@/components/layout/page-shell";
import type { NavGroup } from "@/components/layout/app-sidebar";
import { useTranslation } from "@/providers/app-provider";
import { TenantThemeProvider } from "@/providers/tenant-theme-provider";
import { apiFetch, ApiError } from "@/lib/api";
import { RealtimeMount } from "@/components/notifications/realtime-mount";
import { ErrorShell } from "@/components/layout/error-shell";
import { ForbiddenShell } from "@/components/layout/forbidden-shell";
import { rememberLastBrandSlug } from "@/lib/workspace-preference";
import {
  LayoutDashboard,
  Package,
  Tags,
  Layers,
  FlaskConical,
  BookOpen,
  Clock,
  UtensilsCrossed,
  Store,
  Tablet,
  Users,
  Receipt,
  ShoppingCart,
  UserCog,
  ShieldCheck,
  Bell,
  Settings,
  ListPlus,
  Boxes,
  GitBranch,
  Gift,
  ShieldAlert,
  Ticket,
  BarChart3,
  Percent,
  LayoutGrid,
  HelpCircle,
  ListX,
  Printer as PrinterIcon,
} from "lucide-react";

interface BrandResponse {
  data: {
    id: string;
    slug: string;
    name: string;
    description: string | null;
    logo_url: string | null;
    /** Per-tenant theme colors — consumed by <TenantThemeProvider> below.
     *  See schemas/Sso/Brand.yaml + BrandInfoController for the source. */
    primary_color: string | null;
    secondary_color: string | null;
    accent_color: string | null;
    text_color: string | null;
  };
}

export default function BrandLayout({ children }: { children: React.ReactNode }) {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t } = useTranslation();

  // Single-row endpoint backed by ResolveBrandFromSlug middleware: returns 404
  // if the slug does not exist and 403 if it belongs to another organization.
  // Cached per brandSlug so navigating between pages of the same HQ does not
  // re-hit the API. Crucially this is O(1) regardless of how many brands the
  // organisation owns — the previous /me/context fetch was unbounded.
  const { data, error, refetch } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: ({ signal }) => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`, { signal }),
    staleTime: 5 * 60 * 1000,
    retry: false,
    // Always fire even when the browser reports offline so we get a real error
    // instead of the query being paused indefinitely (the default "online" mode
    // would leave the spinner spinning when the backend is unreachable).
    networkMode: "always",
  });

  useEffect(() => {
    if (data?.data.slug) rememberLastBrandSlug(data.data.slug);
  }, [data?.data.slug]);

  if (error) {
    if (error instanceof ApiError) {
      if (error.status === 404) notFound();
      if (error.status === 403) return <ForbiddenShell />;
    }
    // 5xx / network / timeout — show classified recovery UI.
    return <ErrorShell error={error} onRetry={refetch} />;
  }

  // The whole HQ shell depends on the resolved brand: name, sidebar nav,
  // child pages that use brand_id. Until the API answers, the only honest
  // thing to render is a loading state — anything else would be a half-built
  // page with a placeholder name and broken child queries.
  if (!data) {
    return <LoadingShell />;
  }

  const brandName = data.data.name;

  const navGroups: NavGroup[] = [
    {
      label: t("nav.overview"),
      items: [
        { title: t("nav.dashboard"), href: `/hq/${brandSlug}/dashboard`, icon: LayoutDashboard },
      ],
    },
    {
      label: t("nav.catalog"),
      items: [
        { title: t("nav.products"), href: `/hq/${brandSlug}/products`, icon: Package },
        { title: t("nav.categories"), href: `/hq/${brandSlug}/categories`, icon: Tags },
        { title: t("nav.product_types"), href: `/hq/${brandSlug}/product-types`, icon: Layers },
        { title: t("nav.tax_types"), href: `/hq/${brandSlug}/tax-types`, icon: Percent },
        {
          title: t("nav.topping_groups"),
          href: `/hq/${brandSlug}/topping-groups`,
          icon: ListPlus,
        },
        {
          title: t("nav.allergens"),
          href: `/hq/${brandSlug}/allergens`,
          icon: ShieldAlert,
        },
      ],
    },
    {
      label: t("nav.production"),
      items: [
        { title: t("nav.materials"), href: `/hq/${brandSlug}/materials`, icon: FlaskConical },
        {
          title: t("nav.material_lots"),
          href: `/hq/${brandSlug}/material-lots`,
          icon: Boxes,
        },
        { title: t("nav.recipes"), href: `/hq/${brandSlug}/recipes`, icon: BookOpen },
        { title: t("nav.trace"), href: `/hq/${brandSlug}/trace`, icon: GitBranch },
        { title: t("nav.recalls"), href: `/hq/${brandSlug}/recalls`, icon: ShieldAlert },
      ],
    },
    {
      label: t("nav.sales"),
      items: [
        { title: t("nav.menus"), href: `/hq/${brandSlug}/menus`, icon: UtensilsCrossed },
        {
          title: t("nav.floating_sections"),
          href: `/hq/${brandSlug}/floating-sections`,
          icon: Clock,
        },
        { title: t("nav.shops"), href: `/hq/${brandSlug}/shops`, icon: Store },
        { title: t("nav.default_tables"), href: `/hq/${brandSlug}/tables`, icon: LayoutGrid },
        { title: t("nav.devices"), href: `/hq/${brandSlug}/devices`, icon: Tablet },
        { title: t("nav.customers"), href: `/hq/${brandSlug}/customers`, icon: Users },
        { title: t("nav.customer_orders"), href: `/hq/${brandSlug}/orders`, icon: ShoppingCart },
        // #2880 — tra cứu giao dịch. Đứng cạnh đơn hàng chứ KHÔNG nằm trong
        // `settings/payments`: settings là nơi cấu hình cổng, còn đây là việc
        // vận hành hằng ngày — và 検索要件 của 電子帳簿保存法 là nghĩa vụ, không
        // phải một mục cài đặt.
        { title: t("nav.transactions"), href: `/hq/${brandSlug}/transactions`, icon: Receipt },
        {
          title: t("nav.void_reasons"),
          href: `/hq/${brandSlug}/void-reasons`,
          icon: ListX,
        },
        // #1504 — nội dung khách đọc, không phải master data vận hành; đặt
        // cạnh Khách hàng để người quản nội dung không phải lục trong nhóm
        // catalog.
        {
          title: t("nav.faqs"),
          href: `/hq/${brandSlug}/faqs`,
          icon: HelpCircle,
        },
        { title: t("nav.coupons"), href: `/hq/${brandSlug}/coupons`, icon: Ticket },
        // #1514 — catalog đổi điểm. Ngay dưới Mã giảm giá vì một phần thưởng
        // chính là bản mẫu của một coupon: đổi điểm ⇒ mint coupon cá nhân
        // theo đúng thông số khai ở đó.
        {
          title: t("nav.point_rewards"),
          href: `/hq/${brandSlug}/point-rewards`,
          icon: Gift,
        },
        {
          title: t("nav.promotions_cross_shop"),
          href: `/hq/${brandSlug}/promotions`,
          icon: BarChart3,
        },
      ],
    },
    {
      label: t("nav.workflow"),
      items: [
        // #1214 — no `approvals` entry here. The route has never existed in
        // this repo (it came over with the nav from the old Inertia app; the
        // page did not), and there is no aggregate `GET /hq/{brand}/approvals`
        // endpoint behind it either. Nothing filtered this item, so every HQ
        // user saw a first-in-its-group link that 404s.
        //
        // No functionality is missing: approve / reject live on each product,
        // menu and recipe detail page and work. Restore this only together with
        // a real combined inbox, page and endpoint.
        { title: t("nav.notifications"), href: `/hq/${brandSlug}/notifications`, icon: Bell },
        // #1806 S3 — ngay dưới hộp thư audit, vì nó là CÙNG dữ liệu ở grain
        // khác (gom theo quán), không phải một nguồn cảnh báo thứ hai. Đặt xa
        // nhau sẽ khiến người đọc tưởng có hai bề mặt phải cùng canh — đúng cái
        // ruling S3 đã bác khi từ chối bảng `workstation_alerts` riêng.
        {
          title: t("nav.notifications_workstation_alerts"),
          href: `/hq/${brandSlug}/notifications/workstation-alerts`,
          icon: ShieldAlert,
        },
        {
          title: t("nav.notifications_audiences"),
          href: `/hq/${brandSlug}/notifications/audiences`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_templates"),
          href: `/hq/${brandSlug}/notifications/templates`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_routing"),
          href: `/hq/${brandSlug}/notifications/routing`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_compose"),
          href: `/hq/${brandSlug}/notifications/compose`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_schedules"),
          href: `/hq/${brandSlug}/notifications/schedules`,
          icon: Bell,
        },
        {
          title: t("nav.notifications_email_health"),
          href: `/hq/${brandSlug}/notifications/email-health`,
          icon: Bell,
        },
        {
          title: t("nav.print_templates"),
          href: `/hq/${brandSlug}/print-templates`,
          icon: PrinterIcon,
        },
        { title: t("common.settings"), href: `/hq/${brandSlug}/settings`, icon: Settings },
      ],
    },
    {
      label: t("nav.iam"),
      items: [
        { title: t("nav.iam_members"), href: `/hq/${brandSlug}/iam/members`, icon: UserCog },
        {
          title: t("nav.iam_permissions"),
          href: `/hq/${brandSlug}/iam/permissions`,
          icon: ShieldCheck,
        },
      ],
    },
  ];

  return (
    <TenantThemeProvider brand={data.data}>
      <RealtimeMount brandSlug={brandSlug} />
      <PageShell sidebar topbar brandName={brandName} navGroups={navGroups}>
        {children}
      </PageShell>
    </TenantThemeProvider>
  );
}
