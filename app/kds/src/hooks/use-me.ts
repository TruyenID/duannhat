import { useQuery } from "@tanstack/react-query";
import { getMe, type KdsDeviceInfo } from "@/services/kds/me";
import { useAuth } from "@/providers/use-auth";

export function useMe() {
  const { state } = useAuth();

  return useQuery<KdsDeviceInfo>({
    queryKey: ["kds", "me"],
    queryFn: () => getMe({ silent401: false }),  // 401 routes through global handler → logout
    enabled: state === "paired",
    refetchInterval: 30_000,                      // heartbeat
    refetchIntervalInBackground: true,
    staleTime: 30_000,
  });
}
