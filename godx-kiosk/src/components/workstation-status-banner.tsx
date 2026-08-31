import { useEffect, useState } from "react";
import { Platform, TouchableOpacity, View } from "react-native";
import * as Linking from "expo-linking";
import { Text } from "@godxjp/ui-native";

import { useWorkstation } from "../providers/workstation-provider";
import { useAuth } from "../providers/auth-provider";
import { useTranslation } from "../providers/app-provider";

/**
 * Top banner reflecting workstation connection state.
 *
 * States:
 *   - searching   : auth complete, no workstation found yet (5s grace after auth)
 *   - found       : workstation discovered, briefly visible then auto-hide
 *   - unreachable : was discovered but recent fetch failed → using Cloud
 *   - offline     : iOS Local Network permission denied (placeholder — detection via mDNS no-op)
 *
 * Hidden when:
 *   - Not authenticated (banner only relevant once paired)
 *   - Workstation connected AND in-use, and the "found" 3s timer has elapsed
 */

const FOUND_VISIBLE_MS = 3_000;
const SEARCHING_GRACE_MS = 5_000;

export function WorkstationStatusBanner() {
  const { isAuthenticated } = useAuth();
  const { workstation, usingWorkstation } = useWorkstation();
  const { t } = useTranslation();

  const [authedAt, setAuthedAt] = useState<number | null>(null);
  const [foundAt, setFoundAt] = useState<number | null>(null);
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    if (isAuthenticated && authedAt === null) setAuthedAt(Date.now());
    if (!isAuthenticated) {
      setAuthedAt(null);
      setFoundAt(null);
    }
  }, [isAuthenticated, authedAt]);

  useEffect(() => {
    if (workstation && foundAt === null) setFoundAt(Date.now());
    if (!workstation) setFoundAt(null);
  }, [workstation, foundAt]);

  // Tick `now` every 500ms while any timer is active so "found" auto-hides
  // and "searching" grace window expires without external pushes.
  useEffect(() => {
    if (!isAuthenticated) return;
    const id = setInterval(() => setNow(Date.now()), 500);
    return () => clearInterval(id);
  }, [isAuthenticated]);

  if (!isAuthenticated) return null;

  const sinceAuth = authedAt ? now - authedAt : 0;
  const sinceFound = foundAt ? now - foundAt : 0;

  // 1a. Resolver routing to workstation via env-baked DEFAULT_WORKSTATION_URL
  //     or manual Settings URL (no mDNS discovery object). Hide banner — the
  //     "not found" message would be misleading because we ARE reaching one.
  if (!workstation && usingWorkstation) {
    return null;
  }

  // 1. Workstation found via mDNS, announce briefly then hide.
  if (workstation && usingWorkstation) {
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

  // 2. Discovered but resolver is currently routing to Cloud → unreachable.
  if (workstation && !usingWorkstation) {
    return (
      <Banner tone="warning">
        <Text className="text-sm font-medium text-amber-800">
          {t("workstation.banner.unreachable")}
        </Text>
      </Banner>
    );
  }

  // 3. No workstation yet — searching (with grace window).
  if (sinceAuth < SEARCHING_GRACE_MS) {
    return (
      <Banner tone="info">
        <Text className="text-sm font-medium text-gray-700">
          {t("workstation.banner.searching")}
        </Text>
      </Banner>
    );
  }

  // 4. Past grace window and still nothing — likely permission denied or no WS on LAN.
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
