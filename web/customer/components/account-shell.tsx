"use client";

import { Link, useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { accountHref } from "@/lib/shop-routes";
import { useAuth } from "@/context/auth-context";
import { useGlobalLoading } from "@/context/loading-context";
import Header from "@/components/Header";
import {
  ArrowLeft,
  ChevronRight,
  Crown,
  HelpCircle,
  KeyRound,
  LogOut,
  Receipt,
  Sparkles,
  TicketPercent,
  UserCog,
} from "lucide-react";

/** Mục sidebar đang xem. Trùng tên với route con của `/account`. */
export type AccountNavKey = "membership" | "profile" | "coupons" | "points" | "faq" | "orders" | "password";

// ---------------------------------------------------------------------------
// Sidebar row
// ---------------------------------------------------------------------------
function NavRow({
  icon: Icon,
  label,
  href,
  onClick,
  active,
  destructive,
}: {
  icon: React.ElementType;
  label: string;
  href?: string;
  onClick?: () => void;
  active?: boolean;
  destructive?: boolean;
}) {
  const content = (
    <>
      {/* Vạch trái đánh dấu mục đang xem — luôn chiếm chỗ để nhãn của mọi
          dòng thẳng cột với nhau, chỉ đổi màu khi active. */}
      <span
        aria-hidden="true"
        className={`-my-3 w-[3px] shrink-0 self-stretch rounded-full ${active ? "bg-primary" : "bg-transparent"}`}
      />
      <Icon
        className={`size-4 shrink-0 ${
          destructive ? "text-destructive" : active ? "text-primary" : "text-neutral-400"
        }`}
      />
      <span
        className={`min-w-0 flex-1 truncate text-sm ${
          destructive
            ? "font-medium text-destructive"
            : active
              ? "font-bold text-primary"
              : "font-medium text-neutral-700"
        }`}
      >
        {label}
      </span>
      <ChevronRight className="size-4 shrink-0 text-neutral-300" />
    </>
  );

  const className =
    "flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-neutral-50 active:bg-neutral-100";

  // Mục đang xem không tự trỏ về chính nó — bấm vào là một cú điều hướng rỗng.
  if (href && !active) {
    return (
      <Link href={href} className={className} aria-current={undefined}>
        {content}
      </Link>
    );
  }
  if (href) {
    return (
      <span className={className} aria-current="page">
        {content}
      </span>
    );
  }
  return (
    <button type="button" onClick={onClick} className={className}>
      {content}
    </button>
  );
}

// ---------------------------------------------------------------------------
// AccountShell — vỏ dùng chung cho mọi trang dưới `/account` (#1483).
//
// Trước đây `/account` tự dựng vỏ 2 cột còn `/account/edit` là một form 1 cột
// rời rạc, nên sửa sidebar ở một chỗ là hai trang lệch nhau ngay. Vỏ nằm ở đây
// để mọi trang con nhận CÙNG header, CÙNG sidebar, chỉ khác phần panel.
// ---------------------------------------------------------------------------
export default function AccountShell({
  active,
  children,
}: {
  active: AccountNavKey;
  children: React.ReactNode;
}) {
  const { logout } = useAuth();
  const { showLoading } = useGlobalLoading();
  const router = useRouter();
  const t = useTranslations("account");
  const tCommon = useTranslations("common");
  // Khu tài khoản nằm dưới `/account/[shop]` (#1505) — sidebar phải giữ nguyên
  // cửa hàng đang xem, nếu không mỗi lần đổi tab là rơi ra khỏi cửa hàng và bị
  // guard đá về /select-branch.
  const { shop } = useParams<{ shop?: string }>();

  async function handleLogout() {
    showLoading();
    await logout();
    router.push("/");
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
      <Header showLogo hideSwitcher hideOrderCta hideShadow hideRegister />

      {/* Sub-header: back + tiêu đề — cùng pattern /orders + /orders/[id]. */}
      <div className="sticky top-12 z-30 border-b border-neutral-200 bg-white md:static md:top-auto md:z-auto md:border-b-0 md:bg-[#FAFAFA]">
        <div className="mx-auto flex w-full max-w-7xl items-center gap-2 px-4 py-3 md:px-6 md:py-4">
          <button
            onClick={() => router.back()}
            aria-label={tCommon("back")}
            className="-ml-1 flex size-7 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </button>
          <h1 className="truncate text-base font-bold text-neutral-900">{t("pageTitle")}</h1>
        </div>
      </div>

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 pb-10 md:px-6">
        <div className="grid gap-4 md:grid-cols-[288px_minmax(0,1fr)] md:items-start md:gap-6">
          {/* ── Sidebar ───────────────────────────────────────────────── */}
          <nav className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm md:sticky md:top-[72px]">
            <div className="divide-y divide-neutral-100">
              <NavRow icon={Crown} label={t("navMembership")} href={accountHref(shop)} active={active === "membership"} />
              <NavRow icon={UserCog} label={t("navProfile")} href={accountHref(shop, "edit")} active={active === "profile"} />
              <NavRow
                icon={TicketPercent}
                label={t("navCoupons")}
                href={accountHref(shop, "coupons")}
                active={active === "coupons"}
              />
              <NavRow icon={Sparkles} label={t("navPoints")} href={accountHref(shop, "points")} active={active === "points"} />
              <NavRow icon={HelpCircle} label={t("faq")} href={accountHref(shop, "faq")} active={active === "faq"} />
              <NavRow icon={Receipt} label={t("navOrders")} href={accountHref(shop, "orders")} active={active === "orders"} />
              <NavRow
                icon={KeyRound}
                label={t("changePassword")}
                href={accountHref(shop, "password")}
                active={active === "password"}
              />
              <NavRow icon={LogOut} label={t("logout")} onClick={handleLogout} destructive />
            </div>
          </nav>

          {/* ── Panel ─────────────────────────────────────────────────── */}
          <section className="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm md:p-8">{children}</section>
        </div>
      </main>
    </div>
  );
}
