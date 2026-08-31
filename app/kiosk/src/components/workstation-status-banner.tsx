import { useEffect, useState } from "react";
import { Platform, TouchableOpacity, View } from "react-native";
import * as Linking from "expo-linking";
import { Text } from "@godxjp/ui-native";

import { useWorkstation } from "../providers/workstation-provider";
import { useAuth } from "../providers/auth-provider";
import { useTranslation } from "../providers/app-provider";

/**
 * Top banner reflecting the LAN standby state.
 *
 * The kiosk is cloud-first (issue #44), so silence is the normal, healthy
 * state — there is nothing to tell the customer about while Cloud is serving
 * requests. The banner only speaks up about the standby:
 *   - cloud_fallback : Cloud is failing, traffic has moved to the workstation
 *   - searching      : standby armed, no workstation found yet (5s grace)
 *   - found          : workstation discovered, briefly visible then auto-hide
 *   - not_found      : standby armed but nothing on the LAN (perm / no WS)
 *
 * Hidden when:
 *   - Not authenticated (banner only relevant once paired)
 *   - LAN standby is off — the default; Cloud-only operation is not an anomaly
 */

const FOUND_VISIBLE_MS = 3_000;
const SEARCHING_GRACE_MS = 5_000;

export function WorkstationStatusBanner() {
  const { isAuthenticated } = useAuth();
  const { workstation, usingWorkstation, lanFallbackEnabled } = useWorkstation();
  const { t } = useTranslation();

  // "Armed" = authenticated AND the operator has turned LAN standby on. mDNS
  // discovery only starts then, so the searching-grace window must count from
  // this moment — NOT from pairing. Anchoring to pairing meant that toggling
  // standby on hours after boot skipped the grace entirely and flashed a false
  // "Workstation not found" the instant discovery began. See issue #44 finding #4.
  const armed = isAuthenticated && lanFallbackEnabled;
  const [armedAt, setArmedAt] = useState<number | null>(null);
  const [foundAt, setFoundAt] = useState<number | null>(null);
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    if (armed && armedAt === null) setArmedAt(Date.now());
    if (!armed) {
      setArmedAt(null);
      setFoundAt(null);
    }
  }, [armed, armedAt]);

  useEffect(() => {
    if (workstation && foundAt === null) setFoundAt(Date.now());
    if (!workstation) setFoundAt(null);
  }, [workstation, foundAt]);

  // Tick `now` every 500ms while any timer is active so "found" auto-hides
  // and "searching" grace window expires without external pushes.
  useEffect(() => {
    if (!armed) return;
    const id = setInterval(() => setNow(Date.now()), 500);
    return () => clearInterval(id);
  }, [armed]);

  if (!isAuthenticated) return null;

  const sinceArmed = armedAt ? now - armedAt : 0;
  const sinceFound = foundAt ? now - foundAt : 0;

  // 1. Traffic has moved to the LAN — which only happens because Cloud is
  //    failing. That IS the anomaly worth showing, whether the workstation came
  //    from mDNS, a manual URL or the build-time default.
  if (usingWorkstation) {
    return (
      <Banner tone="warning">
        <Text className="text-sm font-medium text-amber-800">
          {t("workstation.banner.cloud_fallback")}
        </Text>
      </Banner>
    );
  }

  // 2. Cloud is serving requests normally. With the standby off there is
  //    nothing to report — that's the default posture, not a degraded one.
  if (!lanFallbackEnabled) return null;

  // 3. Standby armed and a workstation is ready — announce briefly, then hide.
  if (workstation) {
    if (sinceFound < FOUND_VISIBLE_MS) {
      return (
        <Banner tone="success">
          <Text className="text-sm font-medium text-emerald-700">
            ✓ {t("workstation.banner.connected", { name: workstation.name })}
          </Text>
        </Banner>
      );
    }
    return null;
  }

  // 4. No workstation yet — searching (grace window from when standby armed).
  if (sinceArmed < SEARCHING_GRACE_MS) {
    return (
      <Banner tone="info">
        <Text className="text-sm font-medium text-gray-700">
          {t("workstation.banner.searching")}
        </Text>
      </Banner>
    );
  }

  // 5. Past grace window and still nothing — likely permission denied or no WS on LAN.
  return (
    <Banner tone="warning">
      <Text className="text-sm font-medium text-amber-800">
        {t("workstation.banner.not_found")}
      </Text>
      {Platform.OS === "ios" && (
        <TouchableOpacity onPress={() => Linking.openSettings()}>
          <Text className="text-sm font-semibold text-amber-900 underline">
            {t("workstation.banner.open_settings")}
          </Text>
        </TouchableOpacity>
      )}
    </Banner>
  );
}

function Banner({
  tone,
  children,
}: {
  tone: "success" | "warning" | "info";
  children: React.ReactNode;
}) {
  const bg =
    tone === "success" ? "bg-emerald-50" : tone === "warning" ? "bg-amber-50" : "bg-gray-100";
  return (
    <View className={`${bg} px-4 py-2 flex-row items-center justify-center gap-3`}>{children}</View>
  );
}
