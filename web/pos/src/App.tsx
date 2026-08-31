import { Navigate, Route, Routes } from "react-router-dom";
import { AuthGuard } from "@/components/auth-guard";
import { ShopScope } from "@/components/shop-scope";
import { PairingPage } from "@/app/pairing/page";
import { PosPage } from "@/app/pos/page";
import { RevenuePage } from "@/app/reports/revenue/page";
import { OrderHistoryPage } from "@/app/reports/history/page";
import { ShiftOpenPage } from "@/app/shift/open-page";
import { ShiftClosePage } from "@/app/shift/close-page";
import { ShiftHistoryPage } from "@/app/shift/history/page";
import { ShiftGate } from "@/app/shift/shift-gate";
import { SettingsPage } from "@/app/settings/page";
import MenuAvailabilityPage from "@/app/menu-availability/page";
import { getDeviceInfo } from "@/lib/auth";

/**
 * Resolves the shop to open when the user hits the bare "/" (or an unknown
 * path). Priority:
 *   1. the paired device's own branch slug — a POS terminal is pinned to one
 *      branch at pairing time, so its shop is unambiguous;
 *   2. `VITE_SHOP_SLUG` — a build-time default for a single-shop deployment.
 * With neither, there's no shop context to guess, so bounce to /pairing
 * instead of a sentinel slug that would 404 the shift gate. (Previously this
 * fell back to a hard-coded "van-phong-chinh" that 404s on every seeded DB.)
 */
function DefaultShopRedirect() {
  const branchSlug = getDeviceInfo()?.branch_slug?.trim();
  const envSlug = (import.meta.env.VITE_SHOP_SLUG as string | undefined)?.trim();
  const target = branchSlug || envSlug || "";

  return target ? (
    <Navigate to={`/shop/${target}`} replace />
  ) : (
    <Navigate to="/pairing" replace />
  );
}

function App() {
  return (
    <Routes>
      <Route path="/pairing" element={<PairingPage />} />
      <Route
        path="/shop/:shopSlug"
        element={
          <ShopScope>
            <AuthGuard>
              <ShiftGate>
                <PosPage />
              </ShiftGate>
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route
        path="/shop/:shopSlug/shift/open"
        element={
          <ShopScope>
            <AuthGuard>
              <ShiftOpenPage />
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route
        path="/shop/:shopSlug/shift/close"
        element={
          <ShopScope>
            <AuthGuard>
              <ShiftClosePage />
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route
        path="/shop/:shopSlug/shift/history"
        element={
          <ShopScope>
            <AuthGuard>
              <ShiftHistoryPage />
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route
        path="/shop/:shopSlug/reports/revenue"
        element={
          <ShopScope>
            <AuthGuard>
              <RevenuePage />
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route
        path="/shop/:shopSlug/reports/history"
        element={
          <ShopScope>
            <AuthGuard>
              <OrderHistoryPage />
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route
        path="/shop/:shopSlug/settings"
        element={
          <ShopScope>
            <AuthGuard>
              <SettingsPage />
            </AuthGuard>
          </ShopScope>
        }
      />
      {/* plan-056 — "Tồn món". Deliberately NOT behind <ShiftGate>: running out
          of something does not wait for a cashier to open a shift, and a
          manager checking stock before service should not have to open one. */}
      <Route
        path="/shop/:shopSlug/menu-availability"
        element={
          <ShopScope>
            <AuthGuard>
              <MenuAvailabilityPage />
            </AuthGuard>
          </ShopScope>
        }
      />
      <Route path="/" element={<DefaultShopRedirect />} />
      <Route path="*" element={<DefaultShopRedirect />} />
    </Routes>
  );
}

export default App;
