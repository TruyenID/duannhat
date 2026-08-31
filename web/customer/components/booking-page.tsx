"use client";

import { useState, useCallback, useEffect } from "react";
import { QRCodeSVG } from "qrcode.react";
import { useTranslations, useLocale } from 'next-intl';
import type { MenuItem, MenuCategory } from "@/data/menu";
import { apiFetch } from "@/lib/api";
import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import { useCartReconcile } from "@/hooks/use-cart-reconcile";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import Sidebar from "./Sidebar";
import MenuGrid from "./menu-grid";
import ProductModal from "./product-modal";
import CartBar from "./cart-bar";
import { UtensilsCrossed, Users, MapPin, ChevronDown, QrCode, Download, Loader2 } from "lucide-react";

interface ZoneApiItem {
  id: string;
  name: string;
  tables: { id: string; number: string; seats: number; status: string; qr_token: string }[];
}

// Map backend status (free/occupied/call_staff/paid) → frontend display status
function mapStatus(s: string): "available" | "occupied" | "reserved" {
  if (s === "free") { return "available"; }
  if (s === "occupied" || s === "call_staff" || s === "paid") { return "occupied"; }
  return "reserved";
}

export default function BookingPage() {
  const { setOrderType, dineInTable, setDineInTable, setIsTableLocked, clearCart } = useCart();
  // #1715 — trang này render CartBar/CartDrawer nên hiện giá, phải soát như
  // mọi màn khác có giỏ.
  useCartReconcile();
  const { currentBranch } = useBrand();
  const locale = useLocale();
  const t = useTranslations('booking');

  const [zones, setZones] = useState<ZoneApiItem[] | null>(null);
  const [categories, setCategories] = useState<MenuCategory[]>([]);
  const [activeCategory, setActiveCategory] = useState("");
  const [selectedProduct, setSelectedProduct] = useState<MenuItem | null>(null);
  const [activeZone, setActiveZone] = useState("");
  const [tablePickerOpen, setTablePickerOpen] = useState(true);
  const [qrModalOpen, setQrModalOpen] = useState(false);

  const tableQrToken = dineInTable?.qr_token;
  const isLoadingZones = zones === null;

  // Fetch zones + tables for current branch
  useEffect(() => {
    if (!currentBranch.slug) { return; }
    const ac = new AbortController();
    apiFetch<{ data: ZoneApiItem[] }>(`/api/v1/customer/branches/${currentBranch.slug}/zones`, { signal: ac.signal })
      .then(({ data }) => {
        if (ac.signal.aborted) return;
        setZones(data);
        setActiveZone(data[0]?.id ?? "");
      })
      .catch(() => {
        if (!ac.signal.aborted) setZones([]);
      });
    return () => ac.abort();
  }, [currentBranch.slug, locale]);

  // Fetch menu for current branch
  useEffect(() => {
    if (!currentBranch.slug) { return; }
    const ac = new AbortController();
    const brandParam = currentBranch.brand?.id ? `?brand=${currentBranch.brand.id}` : '';
    apiFetch<{ data: { categories: MenuCategory[] } }>(`/api/v1/customer/branches/${currentBranch.slug}/menu${brandParam}`, { signal: ac.signal })
      .then(({ data }) => {
        if (ac.signal.aborted) return;
        setCategories(data.categories);
        setActiveCategory(data.categories[0]?.id ?? "");
      })
      .catch(() => {});
    return () => ac.abort();
  }, [currentBranch.slug, locale]);

  useEffect(() => {
    setOrderType("dine_in");
    setDineInTable(null);
    setIsTableLocked(false);
    clearCart();
  }, [setOrderType, setDineInTable, setIsTableLocked, clearCart]);

  const handleSelect = useCallback((id: string) => {
    setActiveCategory(id);
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
  }, []);

  const handleItemClick = useCallback((item: MenuItem) => {
    setSelectedProduct(item);
  }, []);

  const handlePickTable = (tableId: string, number: string, seats: number, zoneName: string, qrToken: string) => {
    setDineInTable({ id: tableId, number, seats, zoneName, qr_token: qrToken });
    setIsTableLocked(true);
    setTablePickerOpen(false);
  };

  const currentZone = (zones ?? []).find((z: ZoneApiItem) => z.id === activeZone);

  const statusColor: Record<string, string> = {
    available: "border-emerald-400 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400",
    occupied:  "border-rose-300   bg-rose-50   text-rose-600   dark:bg-rose-950/30   dark:text-rose-400   opacity-60 cursor-not-allowed",
    reserved:  "border-amber-300  bg-amber-50  text-amber-700  dark:bg-amber-950/30  dark:text-amber-400  opacity-70 cursor-not-allowed",
  };

  const statusLabelMap: Record<string, string> = {
    available: t('statusAvailable'),
    occupied: t('statusOccupied'),
    reserved: t('statusReserved'),
  };

  return (
    <>
      {/* Table picker */}
      {tablePickerOpen ? (
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 md:px-6">
          <h2 className="mb-1 text-lg font-bold">{t('selectTable')}</h2>
          <p className="mb-5 text-xs text-muted-foreground">{t('selectTableDesc')}</p>

          {/* Zone tabs */}
          <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
            {isLoadingZones ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" /> {t('loadingZones')}
              </div>
            ) : (zones ?? []).map((z) => (
              <button key={z.id} onClick={() => setActiveZone(z.id)}
                className={`shrink-0 rounded-full border px-4 py-1.5 text-sm font-medium transition-all ${
                  activeZone === z.id ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card text-muted-foreground hover:text-foreground"
                }`}
              >
                {z.name}
              </button>
            ))}
          </div>

          {/* Table grid */}
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
            {currentZone?.tables.map((table) => {
              const displayStatus = mapStatus(table.status);
              return (
                <button key={table.id} disabled={displayStatus !== "available"}
                  onClick={() => handlePickTable(table.id, table.number, table.seats, currentZone.name, table.qr_token)}
                  className={`flex flex-col items-center gap-1 rounded-xl border-2 p-3 text-center transition-all ${statusColor[displayStatus]} ${
                    displayStatus === "available" ? "hover:scale-105 hover:shadow-md" : ""
                  }`}
                >
                  <UtensilsCrossed className="h-5 w-5" />
                  <span className="text-sm font-bold">{t('table', { number: table.number })}</span>
                  <span className="flex items-center gap-0.5 text-[10px]"><Users className="h-3 w-3" />{table.seats}</span>
                  <span className="mt-0.5 rounded-full bg-white/50 px-2 py-0.5 text-[10px] font-medium dark:bg-black/20">
                    {statusLabelMap[displayStatus]}
                  </span>
                </button>
              );
            })}
          </div>
        </div>
      ) : (
        <>
          {/* Selected table banner */}
          <div className="border-b bg-primary/5 py-2">
            <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 md:px-6">
              <UtensilsCrossed className="h-4 w-4 shrink-0 text-primary" />
              <span className="flex-1 text-sm font-bold">
                {t('table', { number: dineInTable?.number ?? '' })}
                <span className="ml-2 text-xs font-normal text-muted-foreground">
                  <MapPin className="mb-0.5 mr-0.5 inline h-3 w-3" />{dineInTable?.zoneName}
                  {" · "}
                  <Users className="mb-0.5 mr-0.5 inline h-3 w-3" />{t('seats', { count: dineInTable?.seats ?? 0 })}
                </span>
                <button
                  onClick={() => setQrModalOpen(true)}
                  className="ml-2 inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary transition-colors hover:bg-primary/20"
                >
                  <QrCode className="h-3 w-3" /> QR
                </button>
              </span>
              <button onClick={() => setTablePickerOpen(true)}
                className="flex items-center gap-1 text-xs text-primary hover:underline">
                {t('changeTable')} <ChevronDown className="h-3 w-3" />
              </button>
            </div>
          </div>

          {/* Menu layout */}
          <div className="mx-auto flex w-full max-w-7xl flex-1 overflow-hidden px-4 md:px-6">
            <aside className="hidden w-56 shrink-0 overflow-y-auto border-r bg-card lg:block">
              <div className="px-4 py-5">
                <h2 className="mb-0.5 text-lg font-bold">{t('dineIn')}</h2>
                <p className="text-xs text-muted-foreground">{t('table', { number: dineInTable?.number ?? '' })}</p>
              </div>
              <Sidebar categories={categories} activeId={activeCategory} onSelect={handleSelect} />
            </aside>

            <div className="fixed bottom-0 left-0 right-0 z-10 overflow-x-auto border-t bg-card md:hidden">
              <div className="flex gap-1 p-2">
                {categories.map((cat) => (
                  <Button key={cat.id} variant={activeCategory === cat.id ? "default" : "secondary"}
                    size="sm" className="shrink-0 rounded-full text-xs" onClick={() => handleSelect(cat.id)}>
                    {cat.name}
                  </Button>
                ))}
              </div>
            </div>

            <main className="flex-1 overflow-y-auto bg-muted/30 p-4 pb-20 md:p-6 md:pb-8 lg:p-8">
              <div className="mx-auto max-w-4xl">
                <MenuGrid categories={categories} onItemClick={handleItemClick} />
              </div>
            </main>
          </div>

          <CartBar />
        </>
      )}

      {selectedProduct && (
        <ProductModal item={selectedProduct} open={!!selectedProduct}
          onOpenChange={(open) => { if (!open) setSelectedProduct(null); }} />
      )}

      {/* QR Modal */}
      {dineInTable && tableQrToken && (
        <Dialog open={qrModalOpen} onOpenChange={setQrModalOpen}>
          <DialogContent className="max-w-xs text-center">
            <DialogHeader>
              <DialogTitle className="text-center">{t('qrTitle', { number: dineInTable.number })}</DialogTitle>
            </DialogHeader>
            <p className="text-xs text-muted-foreground">
              {dineInTable.zoneName} · {t('seats', { count: dineInTable.seats })}
            </p>
            <div className="flex justify-center py-4">
              <div className="rounded-xl border-4 border-primary/20 p-4 shadow-sm">
                <QRCodeSVG
                  value={`${typeof window !== "undefined" ? window.location.origin : ""}/dine-in/${currentBranch.slug}/table/${tableQrToken}`}
                  size={180}
                  level="M"
                  includeMargin={false}
                />
              </div>
            </div>
            <p className="text-[11px] text-muted-foreground">
              {t('qrScanHint')}
            </p>
            <Button variant="outline" size="sm" className="gap-2" onClick={() => setQrModalOpen(false)}>
              <Download className="h-3.5 w-3.5" /> {t('close')}
            </Button>
          </DialogContent>
        </Dialog>
      )}
    </>
  );
}
