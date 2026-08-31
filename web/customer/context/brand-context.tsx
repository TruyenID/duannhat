"use client";

import React, { createContext, useContext, useState, useEffect, useCallback } from "react";
import { useLocale } from "next-intl";
import { useParams } from "next/navigation";
import { apiFetch, ApiError } from "@/lib/api";
import type { Branch, WeeklyHoursMap } from "@/data/brands";
import { SELECTED_BRANCH_STORAGE_KEY } from "@/lib/shop-routes";

interface BranchApiItem {
  id: string;
  name: string;
  slug: string;
  code: string | null;
  address: string | null;
  phone: string | null;
  img_branches: string | null;
  // #936 — per-breakpoint banners.
  banner_desktop?: string | null;
  banner_tablet?: string | null;
  banner_mobile?: string | null;
  logo: string | null;
  seat_capacity: number | null;
  business_hours: string | null;
  weekly_hours: WeeklyHoursMap | null;
  /** #1160 — IANA zone `weekly_hours` is written in. A pickup slot is judged
   * on the SHOP's clock, so a Hanoi customer picking a Tokyo slot is measured
   * against Tokyo's closing time, not their own (#1091). */
  timezone?: string | null;
  service_charge_rate?: number | null;
  // plan-043 T5.5 — per-rate consumption-tax fields (replace the dropped
  // branch-level flat `tax_rate`).
  prices_include_tax?: boolean | null;
  service_charge_tax_rate?: number | null;
  default_tax_type?: {
    id: string;
    code: string;
    rate: number;
  } | null;
  currency_code?: string | null;
	  split_bill_rounding_mode?: string | null;
  locale?: string | null;
  effective_order_policy?: {
    prep_before_payment: boolean;
    customer_email_required: boolean;
    phone_country: string;
    /** plan-037 — minutes the customer has to confirm the order
     * before it auto-voids (counter-pay takeaway). Used to compute the
     * earliest schedulable pickup time on the picker. */
    confirmation_timeout_minutes?: number;
    /** plan-031 — minutes the customer has to pay after committing
     * (counter-pay takeaway) before the order is auto-cancelled. Only counts
     * toward the earliest pickup slot when prep_before_payment is true. */
    payment_timeout_minutes?: number;
    /** #1160 — phút chuẩn bị cho MỖI món (shop ?? brand ?? 5). ETA hiển thị
     * cho khách = giá trị này x tổng số lượng trong giỏ — đúng công thức
     * backend lưu lên đơn. */
    prep_minutes_per_item?: number;
    source: {
      prep_before_payment: "shop" | "brand" | "default";
      customer_email_required: "shop" | "brand" | "default";
      prep_minutes_per_item?: "shop" | "brand" | "default";
    };
  } | null;
	  review_avg_rating?: number | null;
	  review_total_count?: number | null;
	  brand: {
	    id: string;
	    name: string;
	    slug: string;
	    logo_url?: string | null;
	    customer_header_logo_url?: string | null;
	    customer_order_logo_url?: string | null;
	    customer_order_subtitle?: string | null;
	  } | null;
}

const FALLBACK_BRANCH: Branch = {
  id: "",
  slug: "",
  name: "",
  brand: { id: "", slug: "", name: "" },
};



interface BrandContextValue {
  currentBranch: Branch;
  /** #1778 — accepts an UPDATER on purpose. The dine-in pages fold the
   *  `/customer/tables/{qr}` payload into whatever `/customer/branches`
   *  already resolved; without `prev` they can only replace, and replacing
   *  turns every field that payload omits into a wrong default. */
  setCurrentBranch: React.Dispatch<React.SetStateAction<Branch>>;
  branches: Branch[];
  isLoadingBranches: boolean;
  branchesError: ApiError | Error | null;
  refetchBranches: () => void;
  /** Switch to a branch by slug. No-op if slug not found. */
  switchBranch: (slug: string) => void;
  isSwitcherOpen: boolean;
  openSwitcher: () => void;
  closeSwitcher: () => void;
}

const BrandContext = createContext<BrandContextValue | null>(null);

export function BrandProvider({ children }: { children: React.ReactNode }) {
  const locale = useLocale();
  const params = useParams<{ shop?: string; slug?: string }>();
  const routeBranchSlug = params?.shop ?? params?.slug ?? null;
  const [branches, setBranches] = useState<Branch[] | null>(null);
  const [branchesError, setBranchesError] = useState<ApiError | Error | null>(null);
  const [currentBranch, setCurrentBranch] = useState<Branch>(FALLBACK_BRANCH);
  const [isSwitcherOpen, setIsSwitcherOpen] = useState(false);
  // refetchTick: bump để trigger reload branches từ bên ngoài.
  const [refetchTick, setRefetchTick] = useState(0);

  useEffect(() => {
    const ac = new AbortController();
    // cache: "no-store" — tránh browser/Next giữ response cũ. Khi admin đổi
    // ShopOrderSetting (currency/tax/service), client phải lấy ngay sau refresh
    // chứ không dính HTTP cache.
    apiFetch<{ data: BranchApiItem[] }>("/api/v1/customer/branches", {
      silent401: true,
      signal: ac.signal,
      cache: "no-store",
    })
	      .then(({ data }) => {
	        if (ac.signal.aborted) return;
	        const mapped: Branch[] = data.map((b) => ({
          id: b.id,
          name: b.name,
          slug: b.slug,
          code: b.code ?? undefined,
          address: b.address ?? undefined,
          phone: b.phone ?? undefined,
          img_branches: b.img_branches ?? null,
          banner_desktop: b.banner_desktop ?? null,
          banner_tablet: b.banner_tablet ?? null,
          banner_mobile: b.banner_mobile ?? null,
          logo: b.logo ?? null,
          seat_capacity: b.seat_capacity ?? undefined,
          business_hours: b.business_hours ?? undefined,
          weekly_hours: b.weekly_hours ?? null,
          timezone: b.timezone ?? null,
          service_charge_rate: b.service_charge_rate ?? 0,
          // plan-043 — per-rate tax source (the checkout/payment previews read
          // these; the branch-level flat tax_rate was dropped).
          prices_include_tax: b.prices_include_tax ?? false,
          service_charge_tax_rate: b.service_charge_tax_rate ?? 0,
	          default_tax_type: b.default_tax_type ?? null,
	          currency_code: b.currency_code ?? "JPY",
	          split_bill_rounding_mode: b.split_bill_rounding_mode ?? "auto",
          locale: b.locale ?? null,
          effective_order_policy: b.effective_order_policy ?? undefined,
	          review_avg_rating: b.review_avg_rating ?? null,
	          review_total_count: b.review_total_count ?? 0,
	          brand: b.brand ?? { id: "", slug: "", name: "" },
        }));
        setBranches(mapped);
        setBranchesError(null);
        // Bug fix: trước đây `prev.id ? prev : mapped[0]` giữ nguyên object
        // currentBranch cũ ngay cả khi setting (currency/tax/...) đã đổi ở BE,
        // dẫn tới UI không cập nhật. Nay re-resolve theo slug để lấy bản fresh.
        setCurrentBranch((prev) => {
          // A deep link such as /takeaway/jimbocho is the source of truth for
          // the initial branch. Selecting mapped[0] first creates a transient
          // wrong-shop state; the cart guard can observe that state and erase a
          // valid persisted Jimbocho cart before the page effect switches back.
          if (!prev.id) {
            const storedBranchSlug = window.localStorage.getItem(SELECTED_BRANCH_STORAGE_KEY);
            return (
              mapped.find((branch) => branch.slug === routeBranchSlug) ??
              mapped.find((branch) => branch.slug === storedBranchSlug) ??
              mapped[0] ??
              FALLBACK_BRANCH
            );
          }
          const fresh = mapped.find((b) => b.slug === prev.slug);
          return fresh ?? prev;
        });
      })
      .catch((err: unknown) => {
        if (ac.signal.aborted) return;
        const status = err instanceof ApiError ? err.status : undefined;
        console.error("[brand-context] failed to load branches", { status, err });
        setBranches([]);
        setBranchesError(err instanceof Error ? err : new Error(String(err)));
      });
    return () => ac.abort();
  }, [refetchTick, locale, routeBranchSlug]);

  const refetchBranches = useCallback(() => {
    setBranches(null);
    setBranchesError(null);
    setRefetchTick((t) => t + 1);
  }, []);

  const isLoadingBranches = branches === null;

  useEffect(() => {
    if (currentBranch.slug) {
      window.localStorage.setItem(SELECTED_BRANCH_STORAGE_KEY, currentBranch.slug);
    }
  }, [currentBranch.slug]);

  const closeSwitcher = useCallback(() => setIsSwitcherOpen(false), []);

  const switchBranch = useCallback((slug: string) => {
    const branch = (branches ?? []).find((b) => b.slug === slug);
    if (branch) {
      window.localStorage.setItem(SELECTED_BRANCH_STORAGE_KEY, branch.slug);
      setCurrentBranch(branch);
    }
    setIsSwitcherOpen(false);
  }, [branches]);

  return (
    <BrandContext.Provider
      value={{
        currentBranch,
        setCurrentBranch,
        branches: branches ?? [],
        isLoadingBranches,
        branchesError,
        refetchBranches,
        switchBranch,
        isSwitcherOpen,
        openSwitcher: () => setIsSwitcherOpen(true),
        closeSwitcher,
      }}
    >
      {children}
    </BrandContext.Provider>
  );
}

export function useBrand(): BrandContextValue {
  const ctx = useContext(BrandContext);
  if (!ctx) throw new Error("useBrand must be used inside BrandProvider");
  return ctx;
}
